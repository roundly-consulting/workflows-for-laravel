<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Tests\TestCase;

uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\DataTransferObjects\CreateTeamData;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Tests\Bridges\BridgesTestCase;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

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

uses(BridgesTestCase::class)->group('bridges')->in('Bridges', 'Acceptance');

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
