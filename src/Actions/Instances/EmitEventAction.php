<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitData;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitFailure;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitResult;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Steps\Builtin\TriggerStep;
use RoundlyConsulting\Workflows\Support\Engine\Signals;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * An app event: every active definition of the subject's owner (or app-wide) whose current
 * version is triggered by this key and covers the subject's type starts one instance, and the
 * pending waits on the key — of the same owner only — are signalled. A definition that refuses to
 * start is reported in `failed` and the rest carry on. Internal `@` keys are refused.
 */
final readonly class EmitEventAction
{
    public function __construct(
        private StartInstanceAction $start,
        private Signals $signals,
        private Owners $owners,
        private SubjectRegistry $subjects,
        private RestoresOwnerContext $context,
    ) {}

    public function execute(EmitData $data): EmitResult
    {
        TriggerStep::assertEventKey($data->event);

        $owner = $data->owner;

        if ($data->subject !== null) {
            $resolved = $this->owners->ownerOf($data->subject);

            if ($owner !== null && ! Owners::same($owner, $resolved)) {
                throw SubjectOwnerMismatchException::forSubject();
            }

            $owner = $resolved;
        }

        $subjectKey = $data->subject === null ? null : $this->subjects->forModel($data->subject)?->key;
        $started = [];
        $failed = [];

        foreach ($this->definitions($data->event, $owner, $subjectKey) as $definition) {
            try {
                $started[] = $this->start($definition, $data, $owner);
            } catch (WorkflowsException $exception) {
                // One definition's refusal (no triggering user, retired meanwhile) never stops
                // the others or the waits listening for the event.
                $failed[] = new EmitFailure($definition->key, $exception);
            }
        }

        $resumed = $this->context->run($owner, fn (): int => $this->signals->send($data->event, $data->subject, $data->payload, $owner));

        return new EmitResult($started, $resumed, $failed);
    }

    private function start(WorkflowDefinition $definition, EmitData $data, ?Model $owner): StartResult
    {
        return $this->start->execute(new StartData(
            key: $definition->key,
            subject: $definition->subject_key === null ? null : $data->subject,
            owner: $definition->owner_scope === '*' ? null : $owner,
            input: [],
            by: $data->user,
            idempotencyKey: $data->idempotencyKey === null ? null : 'event:'.$data->event.':'.$data->idempotencyKey,
            trigger: TriggerType::Event,
            payload: ['event' => $data->event, 'payload' => $data->payload],
        ));
    }

    /**
     * @return list<WorkflowDefinition>
     */
    private function definitions(string $event, ?Model $owner, ?string $subjectKey): array
    {
        $definitionModel = WorkflowModels::definition();
        $versionModel = WorkflowModels::version();

        $scopes = array_values(array_unique(['*', Owners::scope($owner)]));

        $definitions = $definitionModel::query()
            ->where('status', DefinitionStatus::Active->value)
            ->whereIn('owner_scope', $scopes)
            ->whereIn('current_version_id', $versionModel::query()
                ->where('status', VersionStatus::Published->value)
                ->where('trigger_type', TriggerType::Event->value)
                ->where('trigger_key', $event)
                ->select('id'))
            ->orderBy('id')
            ->get();

        return array_values($definitions->filter(
            static fn (WorkflowDefinition $definition): bool => $definition->subject_key === null || $definition->subject_key === $subjectKey,
        )->all());
    }
}
