<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;

// Dual review F-10: an effect a running attempt committed with once() before it failed is cleaned
// up when the instance was cancelled meanwhile.

final class RegressionLeakyStep extends StepType
{
    public static int $opened = 0;

    /** @var list<mixed> */
    public static array $closed = [];

    public function key(): string
    {
        return 'regression.leaky';
    }

    public function label(): string
    {
        return 'Leaky';
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
        return [Outcome::of('next')];
    }

    public function handle(StepContext $context): StepResult
    {
        $context->once('ticket', fn (): int => ++self::$opened);

        throw StepFailedException::because('boom', 'after opening');
    }

    public function cancel(StepContext $context): void
    {
        self::$closed[] = $context->recall('ticket');
    }
}

beforeEach(function (): void {
    config()->set('workflows.queue.enabled', 'on');
    Bus::fake();
    RegressionLeakyStep::$opened = 0;
    RegressionLeakyStep::$closed = [];
    Workflows::catalogue()->register(RegressionLeakyStep::class);
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('t', 'regression.leaky'), node('done', 'end')], [['start', 't'], ['t', 'done']]), null, null);
});

it('cleans up after an attempt that committed an effect and failed while the instance was being cancelled', function (): void {
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('node_id', 't')->sole();
    $claim = (fn (string|int $instance, string|int $run) => $this->claim($instance, $run))->call(app(RunStepAction::class), $instance->id, $run->id);

    // The cancel lands before once('ticket') commits: its clean-up sees nothing yet.
    Workflows::instances()->cancel($instance);
    $outcome = app(Attempts::class)->run($claim->instance, $claim->run);
    app(RecordStepResultAction::class)->execute($instance->id, $run->id, $claim->token, $outcome->result, $outcome->transactional);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and($run->refresh()->status)->toBe(StepRunStatus::Cancelled)
        ->and(RegressionLeakyStep::$opened)->toBe(1)
        ->and(RegressionLeakyStep::$closed)->toBe([null, 1]);
});

it('cleans up after an attempt whose worker died while the instance was being cancelled', function (): void {
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('node_id', 't')->sole();
    $claim = (fn (string|int $instance, string|int $run) => $this->claim($instance, $run))->call(app(RunStepAction::class), $instance->id, $run->id);

    Workflows::instances()->cancel($instance);
    app(Attempts::class)->run($claim->instance, $claim->run); // the effect commits; the worker never records

    CarbonImmutable::setTestNow('2026-11-02 10:00:00');
    Workflows::wake();

    expect($run->refresh()->status)->toBe(StepRunStatus::Cancelled)
        ->and(RegressionLeakyStep::$closed)->toBe([null, 1]);
});
