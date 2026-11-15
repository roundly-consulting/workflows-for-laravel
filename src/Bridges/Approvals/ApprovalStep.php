<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Approvals;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Steps\Builtin\BuiltinStep;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Steps\WaitSpec;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Engine\Waits;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\PartyResolver;
use RoundlyConsulting\Workflows\Support\PartyValidator;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * `{stages: [{name, approvers, rule, quorum?, required?}]}`, `{approvers, rule, quorum?}` or
 * `{preset, approvers: [parties per stage]}`, plus `expires_in?`, `continue_on_rejection?` and
 * `exclude?` (parties that may never decide — the starter, the subject's creator — passed to
 * approvals' `excluding()` and dropped from every approver list, reassignments included):
 * opens an approvals-for-laravel round (once per visit) and waits for it to resolve. Parties that
 * resolve to nobody fail `no_approvers` — never "anyone may decide". A timer at the expiry plus
 * `approval.expiry_grace` is the safety net for a lost resolution event.
 *
 * @internal
 */
final class ApprovalStep extends BuiltinStep
{
    public function __construct(
        private readonly ApprovalsManager $approvals,
    ) {}

    public function key(): string
    {
        return 'approval';
    }

    public function category(): string
    {
        return 'approvals';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function supportsEscalations(): bool
    {
        return true;
    }

    public function supportsReassign(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        $rule = ['enum' => ['unanimous', 'quorum', 'any', 'weighted']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'stages' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['approvers'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 100],
                            'approvers' => ['$ref' => '#/$defs/parties'],
                            'rule' => $rule,
                            'quorum' => ['type' => 'integer', 'minimum' => 1],
                            'required' => ['type' => 'integer', 'minimum' => 1],
                        ],
                    ],
                ],
                'approvers' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['anyOf' => [['$ref' => '#/$defs/party'], ['$ref' => '#/$defs/parties']]],
                ],
                'rule' => $rule,
                'quorum' => ['type' => 'integer', 'minimum' => 1],
                'preset' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'expires_in' => ['$ref' => '#/$defs/duration'],
                'continue_on_rejection' => ['type' => 'boolean'],
                'exclude' => ['$ref' => '#/$defs/parties'],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('approved'), Outcome::of('rejected'), Outcome::of('expired'), Outcome::of('cancelled', required: false)];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $node = $scope->node?->id;
        $modes = count(array_filter([isset($config['stages']), isset($config['preset']), isset($config['approvers']) && ! isset($config['preset'])]));

        if ($modes !== 1) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, params: ['keyword' => 'oneOf', 'expected' => 'stages | approvers | preset'])];
        }

        $errors = [];
        $exclude = $config['exclude'] ?? null;

        if ($exclude !== null) {
            $errors = PartyValidator::validate($exclude, $scope, '/exclude');
        }

        if (is_array($config['stages'] ?? null)) {
            foreach ($config['stages'] as $index => $stage) {
                $errors = [...$errors, ...$this->approvers(is_array($stage) ? ($stage['approvers'] ?? null) : null, $exclude, $scope, '/stages/'.$index.'/approvers')];
            }

            return $errors;
        }

        if (isset($config['preset'])) {
            foreach (is_array($config['approvers'] ?? null) ? $config['approvers'] : [] as $index => $parties) {
                $errors = [...$errors, ...$this->approvers($parties, $exclude, $scope, '/approvers/'.$index)];
            }

            return $errors;
        }

        return [...$errors, ...$this->approvers($config['approvers'] ?? null, $exclude, $scope, '/approvers')];
    }

    /**
     * An approver list's checks, plus: a list whose every party is also excluded could never be
     * decided by anyone, which publish refuses rather than failing `no_approvers` at run time.
     *
     * @return list<ValidationError>
     */
    private function approvers(mixed $parties, mixed $exclude, ValidationScope $scope, string $path): array
    {
        $errors = PartyValidator::validate($parties, $scope, $path);

        if ($errors !== [] || ! is_array($parties) || $parties === [] || ! is_array($exclude)) {
            return $errors;
        }

        $excluded = array_filter(array_map(self::partyKey(...), $exclude), is_string(...));

        foreach ($parties as $party) {
            $key = self::partyKey($party);

            if ($key === null || ! in_array($key, $excluded, true)) {
                return [];
            }
        }

        return [ValidationError::make(ValidationCode::ApproversExcluded, node: $scope->node?->id, path: $path)];
    }

    /**
     * One party reference as a comparable string (`kind|id|field|role`), or null for a non-party.
     */
    private static function partyKey(mixed $party): ?string
    {
        $reference = PartyReference::fromPayload($party);

        return $reference === null ? null : implode('|', [$reference->kind, (string) $reference->id, (string) $reference->field, (string) $reference->role]);
    }

    public function handle(StepContext $context): StepResult
    {
        $subject = $context->subject() ?? throw StepFailedException::because(FailureCode::SubjectMissing, Messages::get('failures.subject_missing'));
        $config = $context->config();
        $expiresAt = $this->expiry($context, $config);

        $id = $context->once('round', fn (): int|string => Keys::of($this->open($context, $subject, $config, $expiresAt, null)));
        $id = is_int($id) || is_string($id) ? $id : throw StepFailedException::because(FailureCode::ApprovalMissing, Messages::get('failures.approval_missing'));

        return StepResult::wait($this->spec($id, $expiresAt)->probe(), [
            'request_id' => $id,
            'expires_at' => $expiresAt?->toIso8601String(),
            'rounds' => [$id],
        ]);
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        $id = $this->requestId($context, $signal);
        $request = $this->request($id);

        if ($request === null) {
            throw StepFailedException::because(FailureCode::ApprovalMissing, Messages::get('failures.approval_missing'));
        }

        if ($request->status === ApprovalStatus::Pending) {
            if (! $request->isOverdue()) {
                return StepResult::wait($this->spec(Keys::of($request), $this->expiresAt($context)));
            }

            $this->approvals->for($this->subject($context))->within($request)->close(ApprovalStatus::Expired);
            $request->refresh();
        }

        return match ($request->status) {
            ApprovalStatus::Approved => StepResult::complete('approved', ['request_id' => Keys::of($request)]),
            ApprovalStatus::Rejected => StepResult::complete('rejected', ['request_id' => Keys::of($request)]),
            ApprovalStatus::Expired => StepResult::complete('expired', ['request_id' => Keys::of($request)]),
            default => $context->graph()->edgeFor($context->node()->id, 'cancelled') !== null
                ? StepResult::complete('cancelled', ['request_id' => Keys::of($request)])
                : throw StepFailedException::because(FailureCode::ApprovalCancelled, Messages::get('failures.approval_cancelled')),
        };
    }

    public function simulate(StepContext $context): ?StepResult
    {
        return null;
    }

    public function cancel(StepContext $context): void
    {
        // An attempt that threw after opening its round recorded no output: the round is still in
        // the visit's once('round') record.
        $request = $this->request($context->stepRun()->output['request_id'] ?? $context->recall('round'));

        if ($request !== null && $request->status === ApprovalStatus::Pending) {
            $this->approvals->for($this->subject($context))->within($request)->close(ApprovalStatus::Cancelled);
        }
    }

    /**
     * Hand the open approval to the target parties.
     *
     * On a staged round whose open stage has a name, in place (approvals' replaceApprovers()):
     * the decisions already made keep counting, the round keeps its absolute expiry, and only
     * ApprovalStageOpened fires — never a resolution — so the step keeps waiting on the same
     * round. A dropped approver's late decision is refused by approvals.
     *
     * A flat round or an unnamed stage (which approvals can't replace in place): retire its waits
     * first (so its own resolution event resumes nothing), close it as cancelled, then open a new
     * round for the target parties with the original absolute expiry — on a staged round only the
     * stages not yet cleared, the current one with its approvers replaced — and wait on that one.
     */
    public function reassign(StepContext $context, array $to): ?StepResult
    {
        $run = $context->stepRun();
        $output = $run->output ?? [];
        $old = $this->request($output['request_id'] ?? null);

        if ($old === null || $old->status !== ApprovalStatus::Pending) {
            return null;
        }

        $subject = $this->subject($context);
        $stage = $old->staged ? $old->currentStage() : null;

        if ($stage !== null && is_string($stage->name) && $stage->name !== '') {
            $name = $stage->name;

            $context->once('replace:'.Keys::of($old).':'.Keys::of($stage), function () use ($context, $subject, $old, $name, $to): bool {
                $this->approvals->for($subject)->within($old)->replaceApprovers($name, $this->resolve($context, $to, $this->excluded($context, $context->config())));

                return true;
            });

            return null;
        }

        $expiresAt = $this->expiresAt($context);
        $waitModel = WorkflowModels::wait();

        $new = $context->once('reassign:'.Keys::of($old), function () use ($context, $subject, $old, $expiresAt, $to, $run, $waitModel): int|string {
            $waitModel::query()
                ->where('step_run_id', Keys::of($run))
                ->whereIn('status', [WaitStatus::Pending->value, WaitStatus::Claimed->value])
                ->update(['status' => WaitStatus::Cancelled->value]);

            $this->approvals->for($subject)->within($old)->close(ApprovalStatus::Cancelled);

            return Keys::of($this->open($context, $subject, $context->config(), $expiresAt, ['parties' => $to, 'from' => $old]));
        });

        if (! is_int($new) && ! is_string($new)) {
            return null;
        }

        $key = '@approvals.request.'.$new;

        if (! $waitModel::query()->where('step_run_id', Keys::of($run))->where('event_key', $key)->exists()) {
            $instance = $context->instance();
            $version = $context->version();
            Waits::create($instance, $run, $this->spec($new, $expiresAt), Runtime::timezone($version), $context->defaultCalendar(), $context->owner());
        }

        $rounds = is_array($output['rounds'] ?? null) ? $output['rounds'] : [];
        $run->output = [...$output, 'request_id' => $new, 'rounds' => [...$rounds, $new]];
        $run->save();

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{parties: list<PartyReference>, from: ApprovalRequest}|null  $replace
     */
    private function open(StepContext $context, Model $subject, array $config, ?CarbonImmutable $expiresAt, ?array $replace): ApprovalRequest
    {
        $pending = $this->approvals->request($subject);
        $excluded = $this->excluded($context, $config);

        if ($expiresAt !== null) {
            $pending->expiringAt($expiresAt);
        }

        // Only when someone is excluded: an exclusion list is written to approvals' guard columns
        // (its 0008 migration), which a round without one never touches.
        if ($excluded !== []) {
            $pending->excluding($excluded);
        }

        try {
            if (is_array($config['stages'] ?? null) || ($replace !== null && $replace['from']->staged)) {
                $stages = $this->stages($context, $config, $replace, $excluded);

                if (($config['continue_on_rejection'] ?? false) === true) {
                    $pending->continueOnRejection();
                }

                return $pending->stages($stages)->open();
            }

            if (is_string($config['preset'] ?? null) && $replace === null) {
                $lists = [];

                foreach (is_array($config['approvers'] ?? null) ? $config['approvers'] : [] as $parties) {
                    $lists[] = $this->resolve($context, PartyReference::listFrom($parties), $excluded);
                }

                return $pending->workflow($config['preset'])->open($lists);
            }

            $approvers = $this->resolve($context, $replace['parties'] ?? PartyReference::listFrom($config['approvers'] ?? []), $excluded);
            $rule = ApprovalRule::tryFrom(is_string($config['rule'] ?? null) ? $config['rule'] : 'any') ?? ApprovalRule::Any;
            $quorum = is_int($config['quorum'] ?? null) ? min($config['quorum'], count($approvers)) : null;

            return $pending->from($approvers)->rule($rule, $quorum)->open();
        } catch (ClosedApprovalRequestException $exception) {
            throw StepFailedException::because(FailureCode::ApprovalClosed, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{parties: list<PartyReference>, from: ApprovalRequest}|null  $replace
     * @param  list<Model>  $excluded
     * @return list<StageDefinition>
     */
    private function stages(StepContext $context, array $config, ?array $replace, array $excluded): array
    {
        $configured = is_array($config['stages'] ?? null) ? array_values($config['stages']) : [];
        $start = 0;

        if ($replace !== null) {
            $cleared = 0;

            foreach ($replace['from']->stages()->orderBy('position')->get() as $stage) {
                if ($stage->status === ApprovalStatus::Approved) {
                    $cleared++;
                }
            }

            $start = $cleared;
        }

        $stages = [];

        foreach (array_slice($configured, $start) as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }

            $parties = $replace !== null && $index === 0 ? $replace['parties'] : PartyReference::listFrom($stage['approvers'] ?? []);
            $approvers = $this->resolve($context, $parties, $excluded);
            $rule = ApprovalRule::tryFrom(is_string($stage['rule'] ?? null) ? $stage['rule'] : 'any') ?? ApprovalRule::Any;
            $quorum = is_int($stage['quorum'] ?? null) ? min($stage['quorum'], count($approvers)) : null;
            $required = is_int($stage['required'] ?? null) ? min($stage['required'], count($approvers)) : null;

            $stages[] = new StageDefinition($approvers, $rule, $quorum, is_string($stage['name'] ?? null) ? $stage['name'] : null, $required);
        }

        return $stages === [] ? throw StepFailedException::because(FailureCode::NoApprovers, Messages::get('failures.no_approvers')) : $stages;
    }

    /**
     * The approvers, without anyone excluded from deciding (approvals refuses naming one).
     *
     * @param  list<PartyReference>  $parties
     * @param  list<Model>  $excluded
     * @return list<Model>
     */
    private function resolve(StepContext $context, array $parties, array $excluded): array
    {
        $out = array_map(PartyResolver::identity(...), $excluded);
        $approvers = array_values(array_filter(
            $context->parties($parties),
            static fn (Model $approver): bool => ! in_array(PartyResolver::identity($approver), $out, true),
        ));

        if ($approvers === []) {
            throw StepFailedException::because(FailureCode::NoApprovers, Messages::get('failures.no_approvers'));
        }

        return $approvers;
    }

    /**
     * The actors `exclude` names. Unlike approvers they are not filtered by owner membership: a
     * non-member requester must stay excluded all the same.
     *
     * @param  array<string, mixed>  $config
     * @return list<Model>
     */
    private function excluded(StepContext $context, array $config): array
    {
        $parties = PartyReference::listFrom($config['exclude'] ?? []);

        return $parties === [] ? [] : app(PartyResolver::class)->resolve($parties, $context->scope(), members: false);
    }

    private function spec(int|string $id, ?CarbonImmutable $expiresAt): WaitSpec
    {
        $spec = WaitSpec::event('@approvals.request.'.$id);

        return $expiresAt === null ? $spec : $spec->alsoAt($expiresAt->add(WorkflowsConfig::approvalExpiryGrace()));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function expiry(StepContext $context, array $config): ?CarbonImmutable
    {
        $duration = array_key_exists('expires_in', $config) ? Durations::parse($config['expires_in']) : null;

        return $duration === null ? null : Durations::add($duration, $context->now(), $context->timezone(), $context->defaultCalendar(), $context->owner());
    }

    private function expiresAt(StepContext $context): ?CarbonImmutable
    {
        $at = $context->stepRun()->output['expires_at'] ?? null;

        return is_string($at) ? CarbonImmutable::parse($at)->utc() : null;
    }

    /**
     * The round the fired wait was keyed on — never a stale output after a reassign.
     */
    private function requestId(StepContext $context, Signal $signal): mixed
    {
        if ($signal->kind === SignalKind::Event && is_string($signal->key) && str_starts_with($signal->key, '@approvals.request.')) {
            return substr($signal->key, strlen('@approvals.request.'));
        }

        return $context->stepRun()->output['request_id'] ?? null;
    }

    private function request(mixed $id): ?ApprovalRequest
    {
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $model = ApprovalRequestModelResolver::class();
        $request = $model::query()->find($id);

        return $request instanceof ApprovalRequest ? $request : null;
    }

    private function subject(StepContext $context): Model
    {
        return $context->subject() ?? throw StepFailedException::because(FailureCode::SubjectMissing, Messages::get('failures.subject_missing'));
    }
}
