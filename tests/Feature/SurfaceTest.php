<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;
use RoundlyConsulting\Workflows\DataTransferObjects\EdgeReference;
use RoundlyConsulting\Workflows\DataTransferObjects\ImportResult;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus as Status;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\NoVersionException;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Exceptions\UnknownStepTypeException;
use RoundlyConsulting\Workflows\Exceptions\UnknownSubjectTypeException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\Suggestions;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\Testing\WorkflowExpectations;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Records outbound webhook deliveries.
 */
final class RecordingDeliverer implements DeliversWebhooks
{
    /** @var list<array{endpoint: string, event: string, payload: array<string, mixed>, key: string}> */
    public static array $deliveries = [];

    public function deliver(?Model $owner, string $endpoint, string $event, array $payload, string $idempotencyKey): int|string
    {
        self::$deliveries[] = ['endpoint' => $endpoint, 'event' => $event, 'payload' => $payload, 'key' => $idempotencyKey];

        return count(self::$deliveries);
    }
}

it('rejects invalid configuration values, naming the key', function (string $key, mixed $value, Closure $read, string $message): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidWorkflowsConfigurationException::class, $message);
})->with([
    'timezone' => ['workflows.timezone', 'Mars/Base', fn () => WorkflowsConfig::timezone(), 'IANA timezone'],
    'duration' => ['workflows.engine.lease', 'soon', fn () => WorkflowsConfig::lease(), 'ISO-8601 duration'],
    'duration range' => ['workflows.engine.lease', 'PT1S', fn () => WorkflowsConfig::lease(), 'between 60s and 86400s'],
    'int list' => ['workflows.business_time.weekdays', 'mon', fn () => WorkflowsConfig::businessWeekdays(), 'non-empty list'],
    'int list item' => ['workflows.business_time.weekdays', [1, 9], fn () => WorkflowsConfig::businessWeekdays(), 'non-empty list'],
    'string' => ['workflows.owners.context', 42, fn () => WorkflowsConfig::ownerContext(), 'a string'],
    'string map' => ['workflows.authorization.abilities', 'update', fn () => WorkflowsConfig::abilities(), 'a map of strings'],
    'string map value' => ['workflows.authorization.abilities', ['set_field' => ''], fn () => WorkflowsConfig::abilities(), 'a map of strings'],
    // C-113: a contract class that doesn't implement the contract throws, naming the key.
    'implementation' => ['workflows.adapters.webhooks', stdClass::class, fn () => Adapters::webhooks(), 'class-string of [DeliversWebhooks]'],
]);

it('reads defaults and fallbacks', function (): void {
    config()->set('workflows.timezone', 'Europe/Bratislava');
    config()->set('workflows.business_time.weekdays', ['1', 2, 2]);
    config()->set('workflows.authorization.abilities', ['set_field' => 'edit']);
    config()->set('workflows.parties.user_model', 'App\\Models\\Member');
    config()->set('workflows.owner_key_type', null);

    expect(WorkflowsConfig::timezone())->toBe('Europe/Bratislava')
        ->and(WorkflowsConfig::businessWeekdays())->toBe([1, 2])
        ->and(WorkflowsConfig::abilities())->toBe(['set_field' => 'edit'])
        ->and(WorkflowsConfig::partiesUserModel())->toBe('App\\Models\\Member')
        ->and(WorkflowsConfig::keyTypeKeyFor('owner'))->toBe('workflows.key_type');

    config()->set('workflows.authorization.abilities', null);
    config()->set('workflows.business_time.weekdays', null);

    expect(WorkflowsConfig::abilities())->toBe([])
        ->and(WorkflowsConfig::businessWeekdays())->toBe([1, 2, 3, 4, 5]);
});

it('delivers an outbound webhook once per visit through the configured adapter', function (): void {
    RecordingDeliverer::$deliveries = [];
    config()->set('workflows.adapters.webhooks', RecordingDeliverer::class);
    // Adapters are read when the catalogue is built (at boot in a host app).
    app()->forgetInstance(StepCatalogue::class);
    published('hook', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('send', 'webhook', ['endpoint' => 'erp', 'event' => 'document.approved', 'payload' => ['title' => ['ref' => 'subject.title']]]),
        node('done', 'end'),
    ], [['start', 'send'], ['send', 'done', 'queued']]));

    $instance = Workflows::start('hook', document(null, ['title' => 'Contract']))->dispatch()->instance;

    expect($instance)->toHaveCompletedVia(['start', 'send', 'done'])
        ->and(RecordingDeliverer::$deliveries)->toHaveCount(1)
        ->and(RecordingDeliverer::$deliveries[0])->toMatchArray(['endpoint' => 'erp', 'event' => 'document.approved', 'payload' => ['title' => 'Contract']])
        ->and($instance?->stepRuns()->where('node_id', 'send')->sole()->output)->toBe(['delivery' => 1])
        ->and(Workflows::catalogue()->find('webhook')?->hasSideEffects())->toBeTrue()
        ->and(Workflows::versions()->validate(graph([
            node('start', 'trigger', ['trigger' => 'manual']),
            node('send', 'webhook', ['endpoint' => 'erp', 'event' => 'Not A Key']),
            node('done', 'end'),
        ], [['start', 'send'], ['send', 'done', 'queued']]), 'document')->valid())->toBeFalse();
});

it('reads history, starts and dry runs through the definition handle', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]));
    $document = document();
    Workflows::definition('flow')->start($document)->dispatch();
    $document->workflows()->start('flow')->dispatch();

    expect(Workflows::definition('flow')->instances()->items())->toHaveCount(2)
        ->and(Workflows::definition('flow')->instances(InstanceStatus::Failed)->items())->toBe([])
        ->and($document->workflows()->instances()->items())->toHaveCount(2)
        ->and($document->workflows()->emit('doc.nothing')->started)->toBe([])
        ->and(Workflows::wake()->timers)->toBe(0)
        ->and(Workflows::prune()->instances)->toBe(0)
        ->and(Workflows::instances()->find('nope'))->toBeNull()
        ->and(Workflows::instances()->find('01J00000000000000000000000'))->toBeNull()
        ->and(Workflows::instances()->find(999999))->toBeNull();

    $first = WorkflowInstance::query()->firstOrFail();

    expect(Workflows::instances()->find(strtolower($first->ulid))?->is($first))->toBeTrue()
        ->and(Workflows::instances()->find((string) $first->id)?->is($first))->toBeTrue();

    expect(fn () => Workflows::definition('flow')->dryRun(99))->toThrow(NoVersionException::class);
});

it('round-trips a parsed graph with node retries, escalations and ui', function (): void {
    $graph = graph([
        node('start', 'trigger', ['trigger' => 'manual'], ['ui' => ['x' => 1]]),
        node('wait', 'wait', ['event' => 'doc.go'], ['retry' => ['max_attempts' => 2], 'escalations' => [['after' => 'PT1H', 'actions' => []]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]);

    $array = GraphParser::parse($graph)->toArray();

    expect($array['nodes'][0]['ui'])->toBe(['x' => 1])
        ->and($array['nodes'][1]['retry'])->toBe(['max_attempts' => 2])
        ->and($array['nodes'][1]['escalations'])->toHaveCount(1)
        ->and($array['edges'][1])->toBe(['from' => 'wait', 'to' => 'done', 'outcome' => 'received'])
        ->and($array['edges'][0])->toBe(['from' => 'start', 'to' => 'wait']);
});

it('stores and reads utc timestamps in every shape', function (): void {
    $cast = new UtcDateTime;
    $model = new WorkflowInstance;
    $instant = CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Bratislava');

    expect($cast->get($model, 'x', $instant, [])?->format('Y-m-d H:i'))->toBe('2026-11-02 09:00')
        ->and($cast->get($model, 'x', '2026-11-02T10:00:00+01:00', [])?->format('H:i'))->toBe('09:00')
        ->and($cast->get($model, 'x', 'garbage', []))->toBeNull()
        ->and($cast->set($model, 'x', '2026-11-02T10:00:00+01:00', []))->toBe('2026-11-02 09:00:00')
        ->and($cast->set($model, 'x', '2026-11-02 09:00:00', []))->toBe('2026-11-02 09:00:00')
        ->and($cast->set($model, 'x', 42, []))->toBeNull();
});

it('uses ulid and uuid keys when configured', function (string $type, string $pattern): void {
    config()->set('workflows.primary_key_type', $type);
    $model = new WorkflowInstance;

    expect($model->newUniqueId())->toMatch($pattern)
        ->and($model->configuredKeyType()->value)->toBe($type);
})->with([
    'ulid' => ['ulid', '/^[0-9A-HJKMNP-TV-Z]{26}$/'],
    'uuid' => ['uuid', '/^[0-9a-f-]{36}$/'],
]);

it('registers its expectations once', function (): void {
    $registered = new ReflectionProperty(WorkflowExpectations::class, 'registered');
    $registered->setValue(null, false);

    (new WorkflowExpectations)->boot();
    WorkflowExpectations::register();

    expect($registered->getValue())->toBeTrue();

    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance;

    expect(fn () => expect($instance)->toBeWaitingAt('done'))->toThrow(AssertionFailedError::class, 'Expected the instance to be waiting, it is completed.')
        ->and(fn () => expect($instance)->toHaveFailedWith('x'))->toThrow(AssertionFailedError::class, 'Expected the instance to have failed, it is completed.')
        ->and($instance?->status)->toBe(Status::Completed);
});

/**
 * Reads everything a handler can read off its context.
 */
final class ProbeStep extends StepType
{
    /** @var array<string, mixed> */
    public static array $seen = [];

    public function key(): string
    {
        return 'acme.probe';
    }

    public function label(): string
    {
        return 'Probe';
    }

    public function configSchema(): array
    {
        return ['type' => 'object'];
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function handle(StepContext $context): StepResult
    {
        $context->authorize('update');

        self::$seen = [
            'input' => $context->input(),
            'trigger' => $context->trigger(),
            'start' => $context->output('start'),
            'resolved' => $context->resolve(['ref' => 'subject.title']),
            'field' => $context->field('subject.priority'),
            'calendar' => $context->calendar()->isBusinessDay(CarbonImmutable::parse('2026-11-09')),
            'default' => $context->defaultCalendar(),
            'once' => $context->once('effect', static fn (): int => 7),
            'again' => $context->once('effect', static fn (): int => 8),
            'signal' => $context->signal(),
            'simulating' => $context->simulating(),
            'visit' => $context->visit(),
            'attempt' => $context->attempt(),
            'timezone' => $context->timezone(),
            'parties' => count($context->parties(PartyReference::listFrom([['kind' => 'triggered_by']]))),
        ];

        return StepResult::complete();
    }
}

it('hands a handler its whole context', function (): void {
    Workflows::catalogue()->register(ProbeStep::class);
    published('probe', graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'note', 'type' => 'string']]]),
        node('probe', 'acme.probe'),
        node('done', 'end'),
    ], [['start', 'probe'], ['probe', 'done']], ['timezone' => 'Europe/Bratislava', 'calendar' => 'weekdays']));

    Workflows::start('probe', document(null, ['title' => 'Contract', 'priority' => 'high']))->input(['note' => 'hi'])->by(user())->dispatch();

    expect(ProbeStep::$seen)->toMatchArray([
        'input' => ['note' => 'hi'],
        'resolved' => 'Contract',
        'field' => 'high',
        'calendar' => true,
        'default' => 'weekdays',
        'once' => 7,
        'again' => 7,
        'signal' => null,
        'simulating' => false,
        'visit' => 1,
        'attempt' => 1,
        'timezone' => 'Europe/Bratislava',
        'parties' => 1,
    ])->and(ProbeStep::$seen['start']['input'] ?? null)->toBe(['note' => 'hi']);
});

it('builds subject types with owners, abilities and enum options', function (): void {
    $type = Workflows::subject('ticket', Document::class)
        ->ownedBy('company')
        ->ability('edit')
        ->field('status', 'enum', 'Status', options: InstanceStatus::class)
        ->field('kind', 'enum', 'Kind', options: 'NotAnEnum')
        ->build();

    expect($type->ownedBy)->toBe('company')
        ->and($type->ability)->toBe('edit')
        ->and($type->field('status')?->options)->toContain('running', 'completed')
        ->and($type->field('kind')?->options)->toBe([])
        ->and(Workflows::subject('x', Document::class)->key())->toBe('x');
});

// W-01: the fake returns stand-ins, so no mutation needs a nullable model return.
it('declares no nullable model return on a mutating API method', function (): void {
    $classes = [WorkflowsManager::class];

    foreach (glob(__DIR__.'/../../src/Handles/*.php') ?: [] as $file) {
        $classes[] = 'RoundlyConsulting\\Workflows\\Handles\\'.basename($file, '.php');
    }

    $nullable = [];

    foreach ($classes as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($type === null || ! $type->allowsNull() || in_array($method->name, ['find', 'draft', 'version'], true) || str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            $names = $type instanceof ReflectionNamedType ? [$type->getName()] : array_map(static fn ($named): string => $named->getName(), $type instanceof ReflectionUnionType ? $type->getTypes() : []);

            foreach ($names as $name) {
                if (is_subclass_of($name, Model::class) || in_array($name, [ImportResult::class], true)) {
                    $nullable[] = class_basename($class).'::'.$method->name;
                }
            }
        }
    }

    expect($nullable)->toBe([])
        ->and(count($classes))->toBeGreaterThan(8);
});

// W-11
it('lists the first problems in an invalid graph message', function (): void {
    Workflows::definitions()->create(key: 'flow', name: 'Flow');
    Workflows::definition('flow')->edit(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('check', 'condition', ['when' => ['field' => 'input.x', 'op' => 'equals', 'value' => 'secret-value']]),
        node('orphan', 'end'),
        node('lonely', 'end'),
        node('done', 'end'),
    ], [['start', 'check'], ['check', 'done', 'true']]));

    try {
        Workflows::definition('flow')->publish();
        $this->fail('Expected InvalidGraphException.');
    } catch (InvalidGraphException $exception) {
        $count = count($exception->validationReport()->errorsOnly());

        expect($count)->toBeGreaterThan(3)
            ->and($exception->getMessage())->toStartWith('The workflow graph is invalid ('.$count.' problems): ')
            ->and($exception->getMessage())->toContain(' at check')
            ->and($exception->getMessage())->toContain('and '.($count - 3).' more')
            ->and($exception->getMessage())->not->toContain('secret-value');
    }

    expect(InvalidGraphException::report(new ValidationReport)->getMessage())->toBe('The workflow graph is invalid (0 problems).')
        ->and(InvalidGraphException::report(new ValidationReport([ValidationError::make(ValidationCode::DanglingEdge, edge: new EdgeReference('a', 'b'))]))->getMessage())
        ->toBe('The workflow graph is invalid (1 problems): dangling_edge at a→b.')
        ->and(InvalidGraphException::report(new ValidationReport([ValidationError::make(ValidationCode::InvalidStructure, params: ['keyword' => 'nodes'])]))->getMessage())
        ->toBe('The workflow graph is invalid (1 problems): invalid_structure (nodes).');
});

it('says which scope a definition was looked up in', function (): void {
    $acme = company('Acme');

    expect(fn () => Workflows::definition('nope', $acme)->model())->toThrow(UnknownDefinitionException::class, 'The workflow "nope" was not found for this owner.')
        ->and(fn () => Workflows::definition('nope')->model())->toThrow(UnknownDefinitionException::class, 'The workflow "nope" was not found among the app-wide workflows.')
        ->and(fn () => Workflows::triggers()->webhook('wh_nope', []))->toThrow(UnknownDefinitionException::class, 'The workflow "wh_nope" was not found.');

    try {
        Workflows::definition('nope', $acme)->model();
    } catch (UnknownDefinitionException $exception) {
        expect($exception->getMessage())->not->toContain('Company')->not->toContain('\\');
    }
});

it('suggests the closest step type', function (): void {
    expect(fn () => app(StepCatalogue::class)->get('acme.creat_task'))->toThrow(UnknownStepTypeException::class, 'Did you mean "acme.create_task"?')
        ->and(fn () => app(StepCatalogue::class)->get('zzzzzzzz'))->toThrow(UnknownStepTypeException::class, 'The step type "zzzzzzzz" is not registered.')
        ->and(fn () => app(SubjectRegistry::class)->get('documnt'))->toThrow(UnknownSubjectTypeException::class, 'Did you mean "document"?')
        ->and(fn () => app(SubjectRegistry::class)->get('zzz'))->toThrow(UnknownSubjectTypeException::class, 'The subject type "zzz" is not registered.')
        ->and(fn () => app(CalendarRegistry::class)->find('weekday', null, 'UTC'))->toThrow(UnknownCalendarException::class, 'Did you mean "weekdays"?')
        ->and(Suggestions::closest('ab', ['ac', 'aa', 'ab']))->toBe('aa');
});

it('names the package a bridged step type needs', function (): void {
    expect(fn () => app(StepCatalogue::class)->get('approval'))->toThrow(UnknownStepTypeException::class, 'The step type "approval" needs roundly-consulting/approvals-for-laravel.');
});

it('adds the error code and key to the log context', function (): void {
    expect(UnknownDefinitionException::key('flow', company())->context())->toBe(['code' => 'unknown_definition', 'key' => 'flow'])
        ->and(InvalidGraphException::report(new ValidationReport)->context())->toBe(['code' => 'invalid_graph']);
});

// W-26: an action's only public method is execute() (the recorder's reclaim entry is the one
// documented exception — it shares the result transaction).
it('exposes only execute() publicly on actions', function (): void {
    $extra = [];
    $root = (string) realpath(__DIR__.'/../../src/Actions');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $class = 'RoundlyConsulting\\Workflows\\Actions\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! in_array($method->name, ['__construct', 'execute'], true)) {
                $extra[] = class_basename($class).'::'.$method->name;
            }
        }
    }

    expect($extra)->toBe(['RecordStepResultAction::failExpired']);
});
