<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\DataTransferObjects\CreateTeamData;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Testing\Concerns\UsesPublishSandbox;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Tests\Bridges\BridgesTestCase;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Workflows\Tests\Readme\ReadmeTestCase;
use RoundlyConsulting\Workflows\Tests\Sentinel\SentinelTestCase;
use RoundlyConsulting\Workflows\Tests\TestCase;

uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');
uses(TestCase::class, UsesPublishSandbox::class)->in('Publish');

/**
 * A graph document from nodes and edges (`[from, to, outcome?]` tuples).
 *
 * @param  list<array<string, mixed>>  $nodes
 * @param  list<array{0: string, 1: string, 2?: string}>  $edges
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function graph(array $nodes, array $edges, array $settings = []): array
{
    return [
        'schema' => 'roundly.workflows.graph/v1',
        'settings' => $settings,
        'nodes' => $nodes,
        'edges' => array_map(static fn (array $edge): array => isset($edge[2]) ? ['from' => $edge[0], 'to' => $edge[1], 'outcome' => $edge[2]] : ['from' => $edge[0], 'to' => $edge[1]], $edges),
    ];
}

/**
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function node(string $id, string $type, array $config = [], array $extra = []): array
{
    return ['id' => $id, 'type' => $type, 'config' => $config, ...$extra];
}

/**
 * Create, edit and publish a definition; returns the definition.
 *
 * @param  array<string, mixed>  $graph
 */
function published(string $key, array $graph, ?Model $owner = null, ?string $subjectType = 'document'): WorkflowDefinition
{
    Workflows::definitions()->create(key: $key, name: ucfirst($key), subjectType: $subjectType, owner: $owner);
    Workflows::definition($key, $owner)->edit($graph);
    Workflows::definition($key, $owner)->publish();

    return Workflows::definition($key, $owner)->model();
}

function company(string $name = 'Acme'): Company
{
    return Company::query()->create(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function document(?Company $company = null, array $attributes = []): Document
{
    return Document::query()->create(['company_id' => $company?->id, 'title' => 'Doc', ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function user(string $name = 'Ada', array $attributes = []): User
{
    return User::query()->create(['name' => $name, ...$attributes]);
}

/**
 * A graph with owner-specific values (a reviewer id, a start target, a business-day delay).
 *
 * @return array<string, mixed>
 */
function reviewGraph(int $reviewer): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('task', 'acme.create_task', ['title' => 'Prepare']),
        node('review', 'acme.review', ['reviewer' => $reviewer, 'after' => 'task']),
        node('check', 'condition', ['when' => ['field' => 'steps.task.output.task_id', 'op' => 'gt', 'value' => 0]]),
        node('child', 'start_workflow', ['definition' => 'helper', 'wait' => false]),
        node('later', 'delay', ['for' => ['amount' => 1, 'unit' => 'business_days', 'calendar' => 'weekdays']]),
        node('done', 'end'),
    ], [
        ['start', 'task'], ['task', 'review'], ['review', 'check', 'approved'], ['review', 'done', 'rejected'], ['review', 'done', 'expired'],
        ['check', 'child', 'true'], ['check', 'done', 'false'], ['child', 'later', 'started'], ['later', 'done'],
    ]);
}

/**
 * A manual flow waiting on `doc.signed` (a 2-day timeout) with one escalation level after a day.
 *
 * @return array<string, mixed>
 */
function escalatingWait(): array
{
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);

    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed', 'timeout' => 'P2D'], ['escalations' => [['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received'], ['wait', 'done', 'timed_out']]);
}

uses(BridgesTestCase::class)->group('bridges')->in('Bridges', 'Acceptance');
// The README test case boots only the bridges the README installs; group `bridges` (approvals).
uses(ReadmeTestCase::class)->group('bridges')->in('Readme');

/**
 * A team (teams-for-laravel) and its members by role.
 *
 * @param  array<string, User>  $members  role => user
 */
function team(string $name = 'Acme', array $members = []): Team
{
    $team = Teams::create(new CreateTeamData(name: $name));

    foreach ($members as $role => $user) {
        Teams::for($team)->members()->add($user, $role);
    }

    return $team;
}

/**
 * A purchase request of a team (created inside the team context).
 *
 * @param  array<string, mixed>  $attributes
 */
function purchase(Team $team, array $attributes = []): PurchaseRequest
{
    return Teams::runAs($team, static fn (): PurchaseRequest => PurchaseRequest::query()->create([
        'team_id' => $team->getKey(),
        'title' => 'Laptops',
        'amount' => '1000',
        ...$attributes,
    ]));
}

/**
 * A workflow JSON fixture.
 *
 * @return array<string, mixed>
 */
function workflowJson(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/workflows/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

uses(SentinelTestCase::class)->group('bridges')->in('Sentinel');
