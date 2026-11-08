<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\WaitSpec;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;

// Dual review F-8: an effect a running first attempt opened is cleaned up when the instance was
// cancelled before the attempt could record it.

final class RegressionTicketStep extends StepType
{
    public static int $opened = 0;

    /** @var list<array<string, mixed>|null> */
    public static array $closed = [];

    public function key(): string
    {
        return 'regression.ticket';
    }

    public function label(): string
    {
        return 'Ticket';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return ['type' => 'object'];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('closed')];
    }

    public function handle(StepContext $context): StepResult
    {
        $ticket = ++self::$opened;

        return StepResult::wait(WaitSpec::event('ticket.closed', ['subject' => false]), ['ticket' => $ticket]);
    }

    public function cancel(StepContext $context): void
    {
        self::$closed[] = $context->stepRun()->output;
    }
}

it('runs the cancel hook with the output of an attempt that was running when the instance was cancelled', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    Bus::fake();
    RegressionTicketStep::$opened = 0;
    RegressionTicketStep::$closed = [];
    Workflows::catalogue()->register(RegressionTicketStep::class);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('ticket', 'regression.ticket'),
        node('done', 'end'),
    ], [['start', 'ticket'], ['ticket', 'done', 'closed']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('node_id', 'ticket')->sole();

    // The worker claims the attempt and its handler opens the ticket …
    $claim = (fn (string|int $instance, string|int $run) => $this->claim($instance, $run))->call(app(RunStepAction::class), $instance->id, $run->id);
    $outcome = app(Attempts::class)->run($claim->instance, $claim->run);

    // … the instance is cancelled before the attempt records its result …
    Workflows::instances()->cancel($instance);
    expect(RegressionTicketStep::$closed)->toBe([null]);

    // … and the record, which discards the result, still closes what the handler opened.
    app(RecordStepResultAction::class)->execute($instance->id, $run->id, $claim->token, $outcome->result, $outcome->transactional);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and($run->refresh()->status)->toBe(StepRunStatus::Cancelled)
        ->and($run->output)->toBe(['ticket' => 1])
        ->and(RegressionTicketStep::$closed)->toBe([null, ['ticket' => 1]]);
});
