<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Steps\Schema\ConfigSchema;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Schema\SchemaValidator;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\CreateTaskStep;

it('emits only supported keywords', function (): void {
    $schema = ConfigSchema::object(additional: true)
        ->string('title', required: true, minLength: 1, maxLength: 200, pattern: '^[A-Z]', format: 'date', description: 'Title', label: 'Title')
        ->integer('count', min: 1, max: 9)
        ->number('ratio', min: 0, max: 1.5)
        ->boolean('urgent', default: false)
        ->enum('priority', ['low', 'normal', 'high'], default: 'normal')
        ->list('tags', 'string', minItems: 1, maxItems: 5)
        ->list('lines', ConfigSchema::object()->string('sku', required: true))
        ->nested('meta', ConfigSchema::object()->integer('version'))
        ->value('amount')
        ->party('assignee', ownerSpecific: true)
        ->parties('watchers')
        ->duration('within')
        ->condition('when')
        ->field('target')
        ->nodeId('after', required: true)
        ->toArray();

    SchemaValidator::assertSupported($schema, 'acme.everything');

    expect($schema['required'])->toBe(['title', 'after'])
        ->and($schema['additionalProperties'])->toBeTrue()
        ->and($schema['properties']['title'])->toBe(['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[A-Z]', 'format' => 'date', 'title' => 'Title', 'description' => 'Title'])
        ->and($schema['properties']['lines']['items']['required'])->toBe(['sku'])
        ->and($schema['properties']['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 5])
        ->and($schema['properties']['after'])->toBe(['type' => 'string', 'format' => 'node-id'])
        ->and($schema['properties']['watchers'])->toBe(['$ref' => '#/$defs/parties']);
});

it('builds the CreateTaskStep fixture schema exactly', function (): void {
    $built = ConfigSchema::object()
        ->string('title', required: true, maxLength: 200)
        ->boolean('crash_after_effect')
        ->boolean('fail_for_good')
        ->toArray();

    expect($built)->toBe((new CreateTaskStep)->configSchema());
});

it('marks owner-specific paths for import flagging', function (): void {
    $schema = ConfigSchema::object()->party('assignee', ownerSpecific: true)->party('watcher')->toArray();

    expect($schema['properties']['assignee'])->toBe(['$ref' => '#/$defs/party', 'x-workflows-owner-specific' => true])
        ->and($schema['properties']['watcher'])->toBe(['$ref' => '#/$defs/party']);
});

it('registers a host type built with it and validates configs against it', function (): void {
    $type = new class extends StepType
    {
        public function key(): string
        {
            return 'acme.ticket';
        }

        public function label(): string
        {
            return 'Ticket';
        }

        public function hasSideEffects(): bool
        {
            return false;
        }

        public function configSchema(): array
        {
            return ConfigSchema::object()->string('title', required: true)->enum('priority', ['low', 'high'])->toArray();
        }

        public function handle(StepContext $context): StepResult
        {
            return StepResult::complete();
        }
    };

    Workflows::catalogue()->register($type);
    $graph = graph([node('start', 'trigger', ['trigger' => 'manual']), node('t', 'acme.ticket', ['priority' => 'urgent']), node('done', 'end')], [['start', 't'], ['t', 'done']]);

    expect(array_map(static fn ($error): string => $error->code->value, Workflows::versions()->validate($graph)->errors))->toContain('invalid_config');
});

it('keeps a required name once and stays immutable', function (): void {
    $base = ConfigSchema::object();
    $twice = $base->string('a', required: true)->string('a', required: true);

    expect($twice->toArray()['required'])->toBe(['a'])
        ->and($base->toArray())->toBe(['type' => 'object', 'additionalProperties' => false, 'properties' => []]);
});
