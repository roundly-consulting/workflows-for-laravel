<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Canonicalizer;
use RoundlyConsulting\Workflows\Http\Resources\CatalogueEntryResource;
use RoundlyConsulting\Workflows\Http\Resources\DefinitionResource;
use RoundlyConsulting\Workflows\Http\Resources\DryRunResultResource;
use RoundlyConsulting\Workflows\Http\Resources\InstanceResource;
use RoundlyConsulting\Workflows\Http\Resources\StepRunResource;
use RoundlyConsulting\Workflows\Http\Resources\SubjectTypeResource;
use RoundlyConsulting\Workflows\Http\Resources\TemplateResource;
use RoundlyConsulting\Workflows\Http\Resources\ValidationReportResource;
use RoundlyConsulting\Workflows\Http\Resources\VersionDiffResource;
use RoundlyConsulting\Workflows\Http\Resources\VersionResource;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A host's richer reference: the name next to the id.
 */
final class NamedReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'name' => $this->resource->name ?? $this->resource->title ?? null];
    }
}

function resourceFlow(array $spy = []): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('spy', 'acme.spy', $spy),
        node('done', 'end'),
    ], [['start', 'spy'], ['spy', 'done']]);
}

function json(JsonResource $resource): string
{
    return (string) json_encode($resource->resolve(), JSON_THROW_ON_ERROR);
}

it('renders an instance and its step log with references and no class names', function (): void {
    $company = company();
    published('flow', resourceFlow(), $company);
    $rita = user('Rita');
    $instance = Workflows::start('flow', document($company), $company)->by($rita)->dispatch()->instance ?? throw new LogicException;
    $instance->load(['definition', 'version', 'subject', 'owner', 'starter']);

    $rendered = (new InstanceResource($instance))->resolve();

    expect($rendered)->toMatchArray([
        'id' => $instance->ulid,
        'definition' => 'flow',
        'version' => 1,
        'status' => ['value' => 'completed', 'label' => $instance->status->label()],
        'subject' => ['id' => $instance->subject_id, 'type' => 'Document'],
        'subject_type' => 'document',
        'owner' => ['id' => $company->id, 'type' => 'Company'],
        'trigger' => ['type' => 'manual', 'key' => null],
        'started_by' => ['id' => $rita->id, 'type' => 'User'],
        'failure' => null,
    ])
        ->and(json(new InstanceResource($instance)))->not->toContain('\\\\')
        ->and(StepRunResource::collection(Workflows::instances()->steps($instance))->resolve())->toHaveCount(3)
        ->and(json(new StepRunResource(Workflows::instances()->steps($instance)->last())))->not->toContain('\\\\');
});

// C-120
it('renders instance collections without a query per row', function (): void {
    published('flow', resourceFlow());
    foreach (range(1, 5) as $ignored) {
        Workflows::start('flow', document())->by(user())->dispatch();
    }
    $instances = WorkflowInstance::query()->get();
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $rendered = InstanceResource::collection($instances)->resolve();

    expect($queries)->toBe(0)
        ->and($rendered)->toHaveCount(5)
        ->and($rendered[0]['subject']['type'])->toBe('Document')
        ->and($rendered[0]['definition'])->toBeNull();
});

// C-121
it('renders a deleted subject from its stored reference', function (): void {
    published('flow', resourceFlow());
    $document = document();
    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;
    $document->forceDelete();
    $instance->refresh()->load('subject');

    expect((new InstanceResource($instance))->resolve()['subject'])->toBe(['id' => $document->id, 'type' => 'Document'])
        ->and((new InstanceResource($instance))->resolve()['owner'])->toBeNull();
});

// C-122…C-124
it('renders each role through the configured resource', function (string $role): void {
    config()->set('workflows.resources.'.$role, NamedReferenceResource::class);
    $company = company();
    published('flow', resourceFlow(), $company);
    Workflows::definition('flow', $company)->edit(resourceFlow(['mode' => 'ok']));
    Workflows::definition('flow', $company)->publish(user('Pia'));
    $instance = Workflows::start('flow', document($company, ['title' => 'Contract']), $company)->by(user('Rita'))->dispatch()->instance ?? throw new LogicException;
    $instance->load(['subject', 'owner', 'starter']);
    $version = Workflows::definition('flow', $company)->version(2)?->load('publisher') ?? throw new LogicException;

    $rendered = (new InstanceResource($instance))->resolve();
    $field = ['owner' => 'owner', 'subject' => 'subject', 'user' => 'started_by'][$role];

    expect($rendered[$field])->toHaveKey('name')->not->toHaveKey('type')
        ->and((new VersionResource($version))->resolve()['published_by'])->toBe($role === 'user' ? ['id' => $version->publisher_id, 'name' => 'Pia'] : ['id' => $version->publisher_id, 'type' => 'User']);
})->with(['owner', 'subject', 'user']);

it('refuses a reference resource that is not a resource, and falls back when blank', function (string $role, mixed $value): void {
    config()->set('workflows.resources.'.$role, $value);
    $company = company();
    published('flow', resourceFlow(), $company);
    $instance = Workflows::start('flow', document($company), $company)->by(user())->dispatch()->instance ?? throw new LogicException;

    if ($value === null || $value === '') {
        expect((new InstanceResource($instance))->resolve())->toBeArray();

        return;
    }

    expect(fn () => (new InstanceResource($instance))->resolve())->toThrow(InvalidWorkflowsConfigurationException::class, 'workflows.resources.'.$role);
})->with(['owner', 'subject', 'user'])->with([
    'not a class' => ['Nope'],
    'not a resource' => [stdClass::class],
    'the bare base' => [JsonResource::class],
    'blank' => [''],
    'null' => [null],
]);

// C-135
it('never renders an unknown exception message', function (): void {
    published('flow', resourceFlow(['mode' => 'throw']));
    config()->set('workflows.retry.default.max_attempts', 1);
    $instance = Workflows::start('flow', document())->dispatch()->instance ?? throw new LogicException;
    $this->travelAndWake('P1D');
    $run = Workflows::instances()->steps($instance->refresh())->firstWhere('node_id', 'spy') ?? throw new LogicException;

    expect($run->error_message)->toContain('App\\Models\\User')
        ->and((new StepRunResource($run))->resolve()['error'])->toBe(['code' => 'exception', 'message' => 'The step failed unexpectedly.', 'retryable' => $run->error_retryable])
        ->and(json(new StepRunResource($run)))->not->toContain('App')
        ->and(json(new InstanceResource($instance)))->not->toContain('App');
});

// C-125
it('maps an owner mismatch to a 404', function (): void {
    $acme = company('Acme');
    published('flow', resourceFlow(), $acme);

    try {
        Workflows::start('flow', document(company('Globex')), $acme)->dispatch();
        $this->fail('Expected a mismatch.');
    } catch (SubjectOwnerMismatchException $exception) {
        expect($exception->status())->toBe(404)->and($exception->getMessage())->not->toContain('Globex');
    }
});

it('renders definitions, versions, diffs, reports, templates and catalogue exports', function (): void {
    $company = company();
    published('flow', resourceFlow(), $company);
    Workflows::definition('flow', $company)->edit(resourceFlow(['mode' => 'ok']));
    $handle = Workflows::definition('flow', $company);
    $definition = $handle->model()->load(['owner', 'currentVersion']);
    $v1 = $handle->version(1) ?? throw new LogicException;
    $draft = $handle->draft() ?? throw new LogicException;

    expect((new DefinitionResource($definition))->resolve())->toMatchArray([
        'key' => 'flow', 'subject_type' => 'document', 'status' => ['value' => 'active', 'label' => $definition->status->label()],
        'owner' => ['id' => $company->id, 'type' => 'Company'], 'current_version' => 1, 'draft' => true,
    ])
        ->and((new VersionResource($v1))->resolve())->toMatchArray(['number' => 1, 'actor' => ['value' => 'system', 'label' => $v1->actor_mode->label()], 'published_by' => null])
        ->and((new VersionDiffResource(Workflows::versions()->diff($v1, $draft)))->resolve())->toBeArray()->not->toBeEmpty()
        ->and((new ValidationReportResource($handle->validate()))->resolve())->toMatchArray(['valid' => true])
        ->and((new TemplateResource($handle->export()))->resolve())->toHaveKey('graph')
        ->and(CatalogueEntryResource::collection(Workflows::catalogue()->export()->types)->resolve())->not->toBeEmpty()
        ->and((new SubjectTypeResource(Workflows::subjects()->export('document')))->resolve())->toHaveKey('fields')
        ->and(json(new TemplateResource($handle->export())))->not->toContain('\\\\');
});

it('publishes the graph and template JSON schemas', function (): void {
    $graph = Workflows::schema()->graph();
    $template = Workflows::schema()->template();

    expect($graph['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($graph['required'])->toBe(['schema', 'nodes', 'edges'])
        ->and($template['type'])->toBe('object')
        ->and(Workflows::schema()->definitions())->not->toBeEmpty()
        ->and(json_encode([$graph, $template], JSON_THROW_ON_ERROR))->toBeString();
});

// W-04: the DTO swap changes no byte of the JSON (goldens captured before the swap; regenerate
// with WORKFLOWS_WRITE_GOLDEN=1 only for an intended wire change).
it('renders the same JSON after the DTO swap', function (): void {
    CarbonImmutable::setTestNow('2026-11-08 10:00:00');
    $acme = company('Acme');
    published('helper', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme);
    published('review', reviewGraph(7), $acme);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('task', 'acme.create_task', ['title' => 'Order']),
        node('done', 'end'),
    ], [['start', 'task'], ['task', 'done']]), null, null);

    $request = Request::create('/');
    $rendered = [
        'catalogue' => array_map(static fn ($entry): array => (new CatalogueEntryResource($entry))->resolve($request), Workflows::catalogue()->export()->types),
        'template' => (new TemplateResource(Workflows::definition('review', $acme)->export()))->resolve($request),
        'dry-run' => (new DryRunResultResource(Workflows::definition('flow')->dryRun()->run()))->resolve($request),
    ];

    foreach ($rendered as $name => $json) {
        $file = __DIR__.'/../Fixtures/golden/'.$name.'.json';
        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('WORKFLOWS_WRITE_GOLDEN') === '1') {
            file_put_contents($file, $encoded);
        }

        // Compared by content: MySQL's json column hands stored graphs back with its own key order.
        expect(Canonicalizer::encode($json))->toBe(Canonicalizer::encode(json_decode((string) file_get_contents($file), true)), $name);
    }

    CarbonImmutable::setTestNow();
});
