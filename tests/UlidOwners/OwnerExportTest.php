<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Actions\Owners\ExportOwnerAction;
use RoundlyConsulting\Workflows\DataTransferObjects\OwnerExport;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures\Tenant;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Tenant Acme with a published two-version flow, a draft on top, a deleted definition and two
 * runs (one failed); Globex with a flow and a run of its own.
 *
 * @return array{0: Tenant, 1: Tenant}
 */
function exportFixture(): array
{
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $flow = static fn (string $title): array => graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'note', 'type' => 'string']]]),
        node('rename', 'set_field', ['field' => 'subject.title', 'value' => $title]),
        node('done', 'end'),
    ], [['start', 'rename'], ['rename', 'done']]);

    published('triage', $flow('First'), $acme, 'ticket');
    Workflows::definition('triage', $acme)->edit($flow('Second'));
    Workflows::definition('triage', $acme)->publish();
    Workflows::definition('triage', $acme)->edit($flow('Draft'));

    published('old', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme, null);
    Workflows::definition('old', $acme)->model()->delete();

    published('fail', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy', ['mode' => 'fail']), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), $acme, 'ticket');
    published('kid', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme, null);
    published('spawner', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spawn', 'start_workflow', ['definition' => 'kid', 'wait' => false]), node('done', 'end')], [['start', 'spawn'], ['spawn', 'done', 'started']]), $acme, null);
    published('triage', $flow('Globex'), $globex, 'ticket');

    $requester = user('Rita');
    Workflows::start('triage', ticket($acme), $acme)->input(['note' => 'secret note'])->by($requester)->dispatch();
    Workflows::start('fail', ticket($acme), $acme)->dispatch();
    Workflows::start('spawner', null, $acme)->dispatch();
    Workflows::start('triage', ticket($globex), $globex)->dispatch();

    return [$acme, $globex];
}

it('exports an owner\'s definitions with every version and a summary of every run', function (): void {
    [$acme] = exportFixture();

    $export = Workflows::owners()->export($acme);
    $array = $export->toArray();
    $triage = $export->definitions[4];
    $runs = collect($export->runs)->keyBy('definition');

    expect($export)->toBeInstanceOf(OwnerExport::class)
        ->and($array['schema'])->toBe('roundly.workflows.owner-export/v1')
        ->and($export->owner)->toBe(['type' => 'tenant', 'id' => $acme->id])
        ->and(array_map(static fn ($definition): string => $definition->key, $export->definitions))->toBe(['fail', 'kid', 'old', 'spawner', 'triage'])
        ->and($export->definitions[2]->deletedAt)->not->toBeNull()
        ->and(array_map(static fn ($version): array => [$version->number, $version->status], $triage->versions))->toBe([[1, 'superseded'], [2, 'published'], [3, 'draft']])
        ->and($triage->versions[2]->template->graph['nodes'][1]['config']['value'])->toBe('Draft')
        ->and($export->templates())->toHaveCount(7)
        ->and($export->runs)->toHaveCount(4)
        ->and($runs['kid']->parent)->toBe($runs['spawner']->id)
        ->and($runs['spawner']->parent)->toBeNull()
        ->and($runs['spawner']->subject)->toBeNull()
        ->and($runs['triage']->status)->toBe('completed')
        ->and($runs['triage']->version)->toBe(2)
        ->and($runs['triage']->subject)->toBe(['type' => 'ticket', 'id' => WorkflowInstance::query()->where('ulid', $runs['triage']->id)->sole()->subject_id])
        ->and($runs['triage']->startedBy['type'] ?? null)->toBe('User')
        ->and($runs['fail']->startedBy)->toBeNull()
        ->and(array_column($runs['triage']->steps, 'node'))->toBe(['start', 'rename', 'done'])
        ->and($runs['fail']->failure)->toBe(['code' => 'refused', 'node' => 'spy'])
        ->and(array_column($runs['fail']->steps, 'error'))->toContain('refused')
        // No secrets, inputs, outputs or class names: the run's input and payload stay out.
        ->and(json_encode($array, JSON_THROW_ON_ERROR))->not->toContain('secret note')
        ->and($array)->toExposeNoClassNames();
});

it('never exports another owner\'s definitions or runs', function (): void {
    [$acme, $globex] = exportFixture();

    $acmeRuns = array_map(static fn ($run): string => $run->id, Workflows::owners()->export($acme)->runs);
    $globexExport = app(ExportOwnerAction::class)->execute($globex);

    expect(array_map(static fn ($definition): string => $definition->key, $globexExport->definitions))->toBe(['triage'])
        ->and($globexExport->definitions[0]->versions)->toHaveCount(1)
        ->and($globexExport->runs)->toHaveCount(1)
        ->and(array_intersect($acmeRuns, [$globexExport->runs[0]->id]))->toBe([])
        ->and(Workflows::owners()->export(tenant('Empty'))->toArray())->toMatchArray(['definitions' => [], 'runs' => []]);
});

it('exports through dependency injection and under the fake, which records nothing for a read', function (): void {
    [$acme] = exportFixture();
    $injected = app(WorkflowsManager::class)->owners()->export($acme);

    $fake = Workflows::fake();
    $faked = Workflows::owners()->export($acme);

    expect($faked->runs)->toHaveCount(count($injected->runs))
        ->and(array_map(static fn ($definition): string => $definition->key, $faked->definitions))->toBe(array_map(static fn ($definition): string => $definition->key, $injected->definitions));

    $fake->assertNothingRecorded();
});

it('round-trips each exported version into another owner through the template import', function (): void {
    [$acme] = exportFixture();
    $initech = tenant('Initech');
    $decoded = json_decode(json_encode(Workflows::owners()->export($acme)->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    $imported = [];

    foreach ($decoded['definitions'] as $definition) {
        foreach ($definition['versions'] as $version) {
            $result = Workflows::versions()->import($version['template'], $initech);
            $imported[] = [$result->definition->key, count($result->version->graph['nodes'])];
        }
    }

    $published = Workflows::versions()->import(TemplatePayload::fromArray($decoded['definitions'][4]['versions'][1]['template']), $initech, 'copied');
    Workflows::definition('copied', $initech)->publish();
    $ticket = ticket($initech);
    // Node ids are remapped on import: the run is checked by what it did.
    $run = Workflows::start('copied', $ticket, $initech)->dispatch()->instance;

    expect($imported)->toBe([['fail', 3], ['kid', 2], ['old', 2], ['spawner', 3], ['triage', 3], ['triage-2', 3], ['triage-3', 3]])
        ->and(WorkflowDefinition::query()->where('owner_id', $initech->id)->count())->toBe(8)
        ->and($published->definition->refresh()->currentVersion?->graph['nodes'][1]['config']['value'] ?? null)->toBe('Second')
        ->and($run?->status)->toBe(InstanceStatus::Completed)
        ->and($ticket->refresh()->title)->toBe('Second');
});
