<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\AuthorizesSteps;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\StepAuthorization;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\GateStepAuthorizer;
use RoundlyConsulting\Workflows\Support\StepCatalogue;

// Dual review O-7: a bound authorization hook sees every side-effect step and escalation action.

final class RegressionDenyAll implements AuthorizesSteps
{
    /** @var list<array{type: string, ability: string|null}> */
    public static array $calls = [];

    public function authorize(StepAuthorization $authorization): Response
    {
        self::$calls[] = ['type' => $authorization->node->type, 'ability' => $authorization->ability];

        return Response::deny();
    }
}

final class RegressionDeliverer implements DeliversWebhooks
{
    public static int $deliveries = 0;

    public function deliver(?Model $owner, string $endpoint, string $event, array $payload, string $idempotencyKey): int|string
    {
        return ++self::$deliveries;
    }
}

beforeEach(function (): void {
    RegressionDenyAll::$calls = [];
    app()->bind(AuthorizesSteps::class, RegressionDenyAll::class);
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
});

it('calls the hook before a start_workflow step and fails it on a denial', function (): void {
    published('parent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('sw', 'start_workflow', ['definition' => 'child']),
        node('done', 'end'),
    ], [['start', 'sw'], ['sw', 'done', 'started']]), null, null);

    $instance = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;

    expect(RegressionDenyAll::$calls)->toBe([['type' => 'start_workflow', 'ability' => null]])
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Failed)
        ->and($instance->failure_code)->toBe('authorization_denied')
        ->and($instance->failure_message)->toBe('The workflow is not allowed to run the step "sw".')
        ->and(WorkflowInstance::query()->count())->toBe(1);
});

it('calls the hook before an outbound webhook step', function (): void {
    RegressionDeliverer::$deliveries = 0;
    config()->set('workflows.adapters.webhooks', RegressionDeliverer::class);
    app()->forgetInstance(StepCatalogue::class);
    published('hook', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('send', 'webhook', ['endpoint' => 'erp', 'event' => 'document.approved']),
        node('done', 'end'),
    ], [['start', 'send'], ['send', 'done', 'queued']]), null, null);

    $instance = Workflows::start('hook')->dispatch()->instance ?? throw new LogicException;

    expect(RegressionDenyAll::$calls)->toBe([['type' => 'webhook', 'ability' => null]])
        ->and($instance->refresh()->failure_code)->toBe('authorization_denied')
        ->and(RegressionDeliverer::$deliveries)->toBe(0);
});

it('calls the hook before a start_workflow escalation action', function (): void {
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'start_workflow', 'definition' => 'child']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);

    Workflows::start('flow')->dispatch();
    CarbonImmutable::setTestNow('2026-11-02 10:30:00');
    Workflows::wake();

    expect(RegressionDenyAll::$calls)->toBe([['type' => 'wait', 'ability' => null]])
        ->and(WorkflowEscalation::query()->sole()->status)->toBe(EscalationStatus::Failed)
        ->and(WorkflowInstance::query()->count())->toBe(1);
});

it('lets the default authorizer allow a null ability', function (): void {
    $node = new Node('sw', 'start_workflow', []);
    $instance = new WorkflowInstance;

    expect(app(GateStepAuthorizer::class)->authorize(new StepAuthorization(Actor::system(), null, null, $node, $instance))->allowed())->toBeTrue();
});
