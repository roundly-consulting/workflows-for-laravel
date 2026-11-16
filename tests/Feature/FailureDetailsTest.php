<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Events\InstanceFailed;
use RoundlyConsulting\Workflows\Events\StepFailed;
use RoundlyConsulting\Workflows\Exceptions\InvalidFailureDetailsException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Http\Resources\InstanceResource;
use RoundlyConsulting\Workflows\Http\Resources\StepRunResource;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Support\Doctor;
use RoundlyConsulting\Workflows\Support\FailureDetails;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

const AGENT_RUN = ['run_id' => '01JD9Z3Q4M7X2K8N5P6R0S1T2V', 'stop_reason' => 'max_turns', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 80]];

function agentFlow(): WorkflowInstance
{
    published('agent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('agent', 'acme.spy'),
        node('done', 'end'),
    ], [['start', 'agent'], ['agent', 'done']]));

    return Workflows::start('agent', document())->by(user())->dispatch()->instance ?? throw new LogicException;
}

/**
 * The StepFailed and InstanceFailed events dispatched from here on, in `steps` and `instances`.
 */
function captureFailures(): object
{
    $captured = new class
    {
        /** @var list<StepFailed> */
        public array $steps = [];

        /** @var list<InstanceFailed> */
        public array $instances = [];
    };

    Event::listen(StepFailed::class, static function (StepFailed $event) use ($captured): void {
        $captured->steps[] = $event;
    });
    Event::listen(InstanceFailed::class, static function (InstanceFailed $event) use ($captured): void {
        $captured->instances[] = $event;
    });

    return $captured;
}

it('keeps a failing step\'s details on the attempt, the instance, the read API and the events', function (string $how): void {
    $this->fakeStep('acme.spy', static fn (StepContext $context): StepResult => $how === 'returned'
        ? StepResult::fail('agent_run_failed', 'The agent stopped.', AGENT_RUN)
        : throw StepFailedException::because('agent_run_failed', 'The agent stopped.')->withDetails(AGENT_RUN));
    $events = captureFailures();

    $instance = agentFlow()->refresh();
    // Read back from a json column: MySQL reorders object keys, so stored details compare
    // order-insensitively (toEqual); what the events carry is the step's own array (toBe).
    $run = Workflows::instances()->steps($instance)->firstWhere('node_id', 'agent') ?? throw new LogicException;
    $rendered = (new InstanceResource(Workflows::instances()->detail($instance)))->resolve();

    expect($instance)->toHaveFailedWith('agent_run_failed', AGENT_RUN)
        ->and($instance->failureDetails())->toEqual(AGENT_RUN)
        ->and($run->errorDetails())->toEqual(AGENT_RUN)
        ->and($rendered['failure'])->toEqual(['code' => 'agent_run_failed', 'message' => 'The agent stopped.', 'node' => 'agent', 'details' => AGENT_RUN])
        ->and($rendered['steps'][1]['error'])->toEqual(['code' => 'agent_run_failed', 'message' => 'The agent stopped.', 'retryable' => false, 'details' => AGENT_RUN])
        ->and((new StepRunResource($run))->resolve()['error']['details'])->toEqual(AGENT_RUN)
        ->and($events->steps)->toHaveCount(1)
        ->and($events->steps[0]->details)->toBe(AGENT_RUN)
        ->and($events->steps[0]->willRetry)->toBeFalse()
        ->and($events->instances)->toHaveCount(1)
        ->and($events->instances[0]->details)->toBe(AGENT_RUN)
        ->and($events->instances[0]->code)->toBe('agent_run_failed');

    // What the database holds, not just the in-memory model.
    $fresh = WorkflowInstance::query()->findOrFail($instance->id);
    $freshRun = StepRun::query()->findOrFail($run->id);

    expect($fresh->failureDetails())->toEqual(AGENT_RUN)
        ->and($freshRun->errorDetails())->toEqual(AGENT_RUN);
})->with([
    'returned', 'thrown',
]);

it('keeps the details of each retryable attempt, and the last one on the instance', function (): void {
    $attempt = 0;
    $this->fakeStep('acme.spy', static function () use (&$attempt): StepResult {
        $attempt++;

        throw RetryableStepException::because('agent_busy', 'The agent is busy.')->withDetails(['run_id' => 'run-'.$attempt, 'stop_reason' => 'rate_limited']);
    });
    $events = captureFailures();

    $instance = agentFlow();

    expect($instance->refresh()->status)->toBe(InstanceStatus::Running)
        ->and($instance->failureDetails())->toBeNull()
        ->and($events->steps[0]->willRetry)->toBeTrue()
        ->and($events->steps[0]->details)->toBe(['run_id' => 'run-1', 'stop_reason' => 'rate_limited']);

    $this->travelAndWake('PT5M');
    $this->travelAndWake('PT15M');

    $runs = Workflows::instances()->steps($instance)->where('node_id', 'agent')->values();

    expect($instance->refresh())->toHaveFailedWith('agent_busy', ['run_id' => 'run-3', 'stop_reason' => 'rate_limited'])
        ->and($runs->map(static fn (StepRun $run): ?array => $run->errorDetails())->all())->toEqual([
            ['run_id' => 'run-1', 'stop_reason' => 'rate_limited'],
            ['run_id' => 'run-2', 'stop_reason' => 'rate_limited'],
            ['run_id' => 'run-3', 'stop_reason' => 'rate_limited'],
        ])
        ->and(array_map(static fn (StepFailed $event): bool => $event->willRetry, $events->steps))->toBe([true, true, false])
        ->and($events->instances[0]->details)->toBe(['run_id' => 'run-3', 'stop_reason' => 'rate_limited']);
});

it('leaves a failure without details exactly as before: details null everywhere', function (): void {
    $events = captureFailures();
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('spy', 'acme.spy', ['mode' => 'fail']),
        node('done', 'end'),
    ], [['start', 'spy'], ['spy', 'done']]));

    $instance = Workflows::start('flow', document())->dispatch()->instance?->refresh() ?? throw new LogicException;
    $run = Workflows::instances()->steps($instance)->firstWhere('node_id', 'spy') ?? throw new LogicException;
    $rendered = (new InstanceResource(Workflows::instances()->detail($instance)))->resolve();

    expect($instance)->toHaveFailedWith('refused')
        ->and($instance->failureDetails())->toBeNull()
        ->and($run->errorDetails())->toBeNull()
        ->and(WorkflowInstance::query()->toBase()->where('id', $instance->id)->value('failure_details'))->toBeNull()
        ->and(StepRun::query()->toBase()->where('id', $run->id)->value('error_details'))->toBeNull()
        ->and($rendered['failure'])->toBe(['code' => 'refused', 'message' => 'Refused.', 'node' => 'spy', 'details' => null])
        ->and($rendered['steps'][1]['error'])->toBe(['code' => 'refused', 'message' => 'Refused.', 'retryable' => false, 'details' => null])
        ->and($events->steps[0]->details)->toBeNull()
        ->and($events->instances[0]->details)->toBeNull();
});

it('treats empty details as no details', function (): void {
    expect(StepResult::fail('x', 'X.', [])->failureDetails)->toBeNull()
        ->and(StepResult::fail('x', 'X.')->failureDetails)->toBeNull()
        ->and(StepFailedException::because('x', 'X.')->details())->toBeNull()
        ->and(StepFailedException::because('x', 'X.')->withDetails([])->details())->toBeNull();
});

it('truncates oversized details to a marker at runtime and never throws', function (): void {
    config()->set('workflows.failure_details.max_bytes', 256);
    $details = ['run_id' => 'r1', 'transcript' => str_repeat('a', 400)];
    $bytes = strlen((string) json_encode($details));
    $this->fakeStep('acme.spy', StepResult::fail('agent_run_failed', 'The agent stopped.', $details));

    $instance = agentFlow()->refresh();

    expect($instance)->toHaveFailedWith('agent_run_failed', ['truncated' => true, 'bytes' => $bytes])
        ->and(StepResult::fail('x', 'X.', ['small' => 'fits'])->failureDetails)->toBe(['small' => 'fits']);
});

it('refuses details that are not plain JSON where they are given', function (array $details, string $message): void {
    expect(static fn () => StepResult::fail('x', 'X.', $details))->toThrow(InvalidFailureDetailsException::class, $message)
        ->and(static fn () => StepFailedException::because('x', 'X.')->withDetails($details))->toThrow(InvalidFailureDetailsException::class, $message)
        ->and(static fn () => RetryableStepException::because('x', 'X.')->withDetails($details))->toThrow(InvalidFailureDetailsException::class, $message);
})->with([
    'an object' => [['run' => new stdClass], 'run is not'],
    'a model' => [fn (): array => ['nested' => ['user' => new WorkflowInstance]], 'nested.user is not'],
    'a closure' => [fn (): array => ['callback' => static fn (): int => 1], 'callback is not'],
    'NAN' => [['score' => NAN], 'score is not'],
    'INF' => [['score' => INF], 'score is not'],
    'a list' => [['a', 'b'], 'not a list'],
    'too deep' => [(static function (): array {
        $deep = ['leaf' => true];
        for ($i = 0; $i < 32; $i++) {
            $deep = ['level' => $deep];
        }

        return $deep;
    })(), 'is not'],
]);

it('fails the step permanently with invalid_failure_details when a handler gives non-JSON details', function (): void {
    $this->fakeStep('acme.spy', static fn (): StepResult => StepResult::fail('agent_run_failed', 'The agent stopped.', ['run' => new stdClass]));

    $instance = agentFlow()->refresh();
    $runs = Workflows::instances()->steps($instance)->where('node_id', 'agent');

    expect($instance)->toHaveFailedWith('invalid_failure_details')
        ->and($instance->failureDetails())->toBeNull()
        ->and($runs)->toHaveCount(1)
        ->and($instance->failure_message)->toContain('run')
        ->and($instance->failure_message)->not->toContain('stdClass');
});

it('redacts the values of secret-looking keys at any depth', function (): void {
    $details = StepResult::fail('x', 'X.', [
        'run_id' => 'r1',
        'api_key' => 'sk-live-1',
        'request' => ['headers' => ['Authorization' => 'Bearer abc', 'X-Api-Key' => 'k', 'accept' => 'json'], 'cookies' => ['a' => 'b']],
        'attempts' => [['accessToken' => 't1', 'status' => 401], ['refresh_token' => 't2', 'status' => 401]],
        'usage' => ['input_tokens' => 10, 'token_count' => 11, 'max_tokens' => 12],
        'password' => ['nested' => 'gone'],
    ])->failureDetails;

    expect($details)->toBe([
        'run_id' => 'r1',
        'api_key' => '[redacted]',
        'request' => ['headers' => ['Authorization' => '[redacted]', 'X-Api-Key' => '[redacted]', 'accept' => 'json'], 'cookies' => '[redacted]'],
        'attempts' => [['accessToken' => '[redacted]', 'status' => 401], ['refresh_token' => '[redacted]', 'status' => 401]],
        'usage' => ['input_tokens' => 10, 'token_count' => 11, 'max_tokens' => 12],
        'password' => '[redacted]',
    ]);
});

it('names a key a secret by its words', function (string $key, bool $secret): void {
    expect(FailureDetails::secret($key))->toBe($secret);
})->with([
    ['password', true], ['db_password', true], ['passwd', true], ['client_secret', true], ['clientSecret', true],
    ['credentials', true], ['Set-Cookie', true], ['authorization', true], ['token', true], ['access_token', true],
    ['ACCESS_TOKEN', true], ['idToken', true], ['api_key', true], ['apiKey', true], ['APIKEY', true], ['x-api-key', true],
    ['private_key', true], ['secretKey', true], ['basic_auth', true], ['session_id', true], ['otp', true], ['dsn', true],
    ['run_id', false], ['stop_reason', false], ['input_tokens', false], ['tokens', false], ['token_count', false],
    ['key', false], ['cache_key', false], ['idempotency_key', false], ['author', false], ['authored_by', false],
    ['model', false], ['agent_id', false], ['', false], ['---', false],
]);

it('repairs invalid UTF-8 so recording a failure can never fail to encode', function (): void {
    $this->fakeStep('acme.spy', StepResult::fail('agent_run_failed', 'The agent stopped.', ['raw' => "ok\xC3\x28end"]));

    $instance = agentFlow()->refresh();

    expect($instance->status)->toBe(InstanceStatus::Failed)
        ->and($instance->failureDetails())->toBe(['raw' => "ok\u{FFFD}(end"]);
});

it('clears the instance details on a retry and records the next failure\'s own', function (): void {
    $attempt = 0;
    $this->fakeStep('acme.spy', static function () use (&$attempt): StepResult {
        $attempt++;

        return $attempt === 1
            ? StepResult::fail('agent_run_failed', 'The agent stopped.', AGENT_RUN)
            : StepResult::fail('refused', 'Refused.');
    });

    $instance = agentFlow()->refresh();
    expect($instance->failureDetails())->toEqual(AGENT_RUN);

    Workflows::instances()->retry($instance);

    expect($instance->refresh())->toHaveFailedWith('refused')
        ->and($instance->failureDetails())->toBeNull()
        ->and(WorkflowInstance::query()->toBase()->where('id', $instance->id)->value('failure_details'))->toBeNull();
});

it('keeps failure details out of the owner export', function (): void {
    $company = company();
    published('agent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('agent', 'acme.spy'),
        node('done', 'end'),
    ], [['start', 'agent'], ['agent', 'done']]), $company);
    $this->fakeStep('acme.spy', StepResult::fail('agent_run_failed', 'The agent stopped.', ['run_id' => 'export-run-77', 'stop_reason' => 'max_turns']));
    Workflows::start('agent', document($company), $company)->dispatch();

    $export = (string) json_encode(Workflows::owners()->export($company)->toArray());

    expect($export)->toContain('agent_run_failed')
        ->and($export)->not->toContain('export-run-77')
        ->and($export)->not->toContain('max_turns')
        ->and($export)->not->toContain('details');
});

it('records plain failures on a host that has not run the 1.4 migration, and doctor warns', function (): void {
    Schema::table(WorkflowsConfig::table('step_runs'), static fn ($table) => $table->dropColumn('error_details'));
    Schema::table(WorkflowsConfig::table('instances'), static fn ($table) => $table->dropColumn('failure_details'));
    $this->fakeStep('acme.spy', StepResult::fail('refused', 'Refused.'));

    $instance = agentFlow()->refresh();
    $run = Workflows::instances()->steps($instance)->firstWhere('node_id', 'agent') ?? throw new LogicException;
    $findings = collect(app(Doctor::class)->run())->where('check', 'schema');

    expect($instance)->toHaveFailedWith('refused')
        ->and((new InstanceResource($instance))->resolve()['failure']['details'])->toBeNull()
        ->and((new StepRunResource($run))->resolve()['error']['details'])->toBeNull()
        ->and(Workflows::instances()->retry($instance)->status)->toBe(InstanceStatus::Failed)
        ->and($findings)->toHaveCount(1)
        ->and($findings->first()?->message)->toContain('workflow_step_runs.error_details')
        ->and($findings->first()?->message)->toContain('workflow_instances.failure_details');
});

it('has no schema finding when the failure-details columns exist', function (): void {
    expect(collect(app(Doctor::class)->run())->where('check', 'schema'))->toBeEmpty();
});
