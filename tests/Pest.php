<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Tests\TestCase;

uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
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
