<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Workflows\DataTransferObjects\SyncedDefinition;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\SyncStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Drafts;

// Definition files live in a per-test temp dir, never in the shared skeleton.
beforeEach(function (): void {
    $this->syncDir = sys_get_temp_dir().'/workflows-sync-'.bin2hex(random_bytes(6));
    mkdir($this->syncDir);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->syncDir);
});

/**
 * @param  array<string, mixed>  $graph
 */
function definitionFile(string $dir, string $key, array $graph, ?string $subjectType = null, string $name = 'A flow'): string
{
    $file = $dir.'/'.$key.'.json';
    file_put_contents($file, json_encode([
        'schema' => 'roundly.workflows.template/v1',
        'definition' => ['key' => $key, 'name' => $name, 'subject_type' => $subjectType, 'description' => null],
        'graph' => $graph,
    ], JSON_THROW_ON_ERROR));

    return $file;
}

function syncFlow(string $title = 'x'): array
{
    return graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => $title]), node('done', 'end')], [['start', 'task'], ['task', 'done']]);
}

/**
 * @return array<string, string>
 */
function statuses(array $definitions): array
{
    return collect($definitions)->mapWithKeys(fn (SyncedDefinition $definition): array => [$definition->file => $definition->status->value])->all();
}

it('creates and publishes definitions from files', function (): void {
    definitionFile($this->syncDir, 'alpha', syncFlow());
    definitionFile($this->syncDir, 'beta', syncFlow('y'), 'document');

    $report = Workflows::definitions()->sync($this->syncDir, publish: true);

    expect(statuses($report->definitions))->toBe(['alpha.json' => 'published', 'beta.json' => 'published'])
        ->and($report->definitions[0]->version)->toBe(1)
        ->and(Workflows::definition('alpha')->model()->status)->toBe(DefinitionStatus::Active)
        ->and(Workflows::definition('beta')->model()->subject_key)->toBe('document')
        ->and(Workflows::definition('alpha')->model()->currentVersion?->graph)->toEqual(syncFlow())
        ->and($report->failed())->toBeFalse();
});

it('is a no-op for unchanged files', function (): void {
    definitionFile($this->syncDir, 'alpha', syncFlow());
    Workflows::definitions()->sync($this->syncDir, publish: true);

    $again = Workflows::definitions()->sync($this->syncDir, publish: true);

    expect(statuses($again->definitions))->toBe(['alpha.json' => 'unchanged'])
        ->and(WorkflowVersion::query()->count())->toBe(1)
        ->and(Drafts::find(Workflows::definition('alpha')->model()))->toBeNull();
});

it('saves a draft without publishing by default', function (): void {
    definitionFile($this->syncDir, 'alpha', syncFlow());
    Workflows::definitions()->sync($this->syncDir, publish: true);
    definitionFile($this->syncDir, 'alpha', syncFlow('changed'));

    $report = Workflows::definitions()->sync($this->syncDir);
    $definition = Workflows::definition('alpha')->model();

    expect(statuses($report->definitions))->toBe(['alpha.json' => 'drafted'])
        ->and($definition->currentVersion?->number)->toBe(1)
        ->and(Drafts::find($definition)?->graph)->toEqual(syncFlow('changed'))
        ->and(statuses(Workflows::definitions()->sync($this->syncDir)->definitions))->toBe(['alpha.json' => 'unchanged'])
        ->and(statuses(Workflows::definitions()->sync($this->syncDir, publish: true)->definitions))->toBe(['alpha.json' => 'published'])
        ->and(Workflows::definition('alpha')->model()->currentVersion?->number)->toBe(2);
});

it('reports an invalid file and publishes nothing for it', function (): void {
    definitionFile($this->syncDir, 'alpha', syncFlow());
    definitionFile($this->syncDir, 'broken', graph([node('start', 'trigger', ['trigger' => 'manual']), node('lost', 'end')], []));
    file_put_contents($this->syncDir.'/garbage.json', '{not json');
    file_put_contents($this->syncDir.'/other.json', json_encode(['schema' => 'nope']));

    $report = Workflows::definitions()->sync($this->syncDir, publish: true);

    expect(statuses($report->definitions))->toBe(['alpha.json' => 'published', 'broken.json' => 'invalid', 'garbage.json' => 'invalid', 'other.json' => 'invalid'])
        ->and($report->with(SyncStatus::Invalid)[0]->report?->valid())->toBeFalse()
        ->and($report->with(SyncStatus::Invalid)[0]->message)->toContain('The workflow graph is invalid')
        ->and(Workflows::definition('broken')->model()->current_version_id)->toBeNull()
        ->and($report->failed())->toBeTrue();
});

it('reports untracked definitions without touching them', function (): void {
    published('manual', syncFlow());
    definitionFile($this->syncDir, 'alpha', syncFlow());

    $report = Workflows::definitions()->sync($this->syncDir);

    expect($report->untracked)->toBe(['manual'])
        ->and(Workflows::definition('manual')->model()->status)->toBe(DefinitionStatus::Active);
});

it('reports a draft edited elsewhere that a sync replaced', function (): void {
    definitionFile($this->syncDir, 'alpha', syncFlow());
    Workflows::definitions()->sync($this->syncDir, publish: true);
    Workflows::definition('alpha')->edit(syncFlow('from the builder'));
    definitionFile($this->syncDir, 'alpha', syncFlow('from the file'));

    $report = Workflows::definitions()->sync($this->syncDir);

    expect($report->definitions[0]->draftOverwritten)->toBeTrue()
        ->and(Drafts::find(Workflows::definition('alpha')->model())?->graph)->toEqual(syncFlow('from the file'));
});

it('writes nothing in a dry run', function (): void {
    definitionFile($this->syncDir, 'alpha', syncFlow());
    definitionFile($this->syncDir, 'broken', graph([node('start', 'trigger', ['trigger' => 'manual'])], []));
    $file = definitionFile($this->syncDir, 'gamma', syncFlow());
    Workflows::definitions()->sync($file, publish: true);
    definitionFile($this->syncDir, 'gamma', syncFlow('new'));

    $report = Workflows::definitions()->sync($this->syncDir, publish: true, dryRun: true);

    expect(statuses($report->definitions))->toBe(['alpha.json' => 'published', 'broken.json' => 'invalid', 'gamma.json' => 'published'])
        ->and($report->dryRun)->toBeTrue()
        ->and(Workflows::definitions()->exists('alpha'))->toBeFalse()
        ->and(Workflows::definition('gamma')->model()->currentVersion?->graph)->toEqual(syncFlow())
        ->and(statuses(Workflows::definitions()->sync($this->syncDir, dryRun: true)->definitions))->toBe(['alpha.json' => 'created', 'broken.json' => 'created', 'gamma.json' => 'drafted']);
});

it('syncs into an owner and fails a file whose subject type is unknown', function (): void {
    $acme = company();
    definitionFile($this->syncDir, 'alpha', syncFlow());
    definitionFile($this->syncDir, 'odd', syncFlow(), 'spaceship');

    $report = Workflows::definitions()->sync($this->syncDir, $acme);

    expect(statuses($report->definitions))->toBe(['alpha.json' => 'created', 'odd.json' => 'failed'])
        ->and(Workflows::definitions()->exists('alpha', $acme))->toBeTrue()
        ->and(Workflows::definitions()->exists('alpha'))->toBeFalse();
});

it('reads the configured path and records syncs under the fake', function (): void {
    config()->set('workflows.sync.path', $this->syncDir);
    definitionFile($this->syncDir, 'alpha', syncFlow());

    expect(Workflows::definitions()->sync()->definitions)->toHaveCount(1);

    config()->set('workflows.sync.path', 'resources/workflows');

    expect(Workflows::definitions()->sync()->definitions)->toBe([]);

    $fake = Workflows::fake();
    $fake->assertNotSynced();

    expect(Workflows::definitions()->sync($this->syncDir, publish: true)->definitions)->toBe([]);

    $fake->assertSynced($this->syncDir, publish: true);
    expect(fn () => $fake->assertSynced('/elsewhere'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNotSynced())->toThrow(ExpectationFailedException::class);
});
