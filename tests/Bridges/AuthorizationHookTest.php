<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Workflows\Contracts\AuthorizesSteps;
use RoundlyConsulting\Workflows\DataTransferObjects\StepAuthorization;
use RoundlyConsulting\Workflows\Facades\Workflows;

// Dual review O-7: a bound hook sees the bridged side-effect steps, whose types name no ability.

final class RegressionBridgeDenyAll implements AuthorizesSteps
{
    /** @var list<array{type: string, ability: string|null}> */
    public static array $calls = [];

    public function authorize(StepAuthorization $authorization): Response
    {
        self::$calls[] = ['type' => $authorization->node->type, 'ability' => $authorization->ability];

        return Response::deny('Denied by the host.');
    }
}

beforeEach(function (): void {
    RegressionBridgeDenyAll::$calls = [];
    app()->bind(AuthorizesSteps::class, RegressionBridgeDenyAll::class);
});

it('calls the hook before a bridged side-effect step and fails it on a denial', function (string $type, array $config, array $outcomes): void {
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('step', $type, $config),
        node('done', 'end'),
    ], [['start', 'step'], ...array_map(static fn (string $outcome): array => ['step', 'done', $outcome], $outcomes)]), $team, 'purchase_request');

    $instance = Workflows::start('flow', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch()->instance ?? throw new LogicException;

    expect(RegressionBridgeDenyAll::$calls)->toBe([['type' => $type, 'ability' => null]])
        ->and($instance->refresh()->failure_code)->toBe('authorization_denied')
        ->and($instance->failure_message)->toBe('Denied by the host.')
        ->and(ApprovalRequest::query()->count() + InboxItem::query()->count())->toBe(0);
})->with([
    'approval' => ['approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any'], ['approved', 'rejected', 'expired']],
    'transition' => ['transition', ['transition' => 'approve'], ['transitioned']],
    'notify' => ['notify', ['notification' => 'document.notice', 'recipients' => [['kind' => 'field', 'field' => 'subject.approver']]], ['next']],
]);
