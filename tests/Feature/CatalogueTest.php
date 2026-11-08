<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Exceptions\DuplicateStepTypeException;
use RoundlyConsulting\Workflows\Exceptions\InvalidStepTypeException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\CreateTaskStep;

function hostType(string $key, array $schema = ['type' => 'object']): StepType
{
    return new class($key, $schema) extends StepType
    {
        public function __construct(private string $typeKey, private array $schema) {}

        public function key(): string
        {
            return $this->typeKey;
        }

        public function label(): string
        {
            return 'Host';
        }

        public function configSchema(): array
        {
            return $this->schema;
        }

        public function hasSideEffects(): bool
        {
            return false;
        }

        public function handle(StepContext $context): StepResult
        {
            return StepResult::complete();
        }
    };
}

// C-02
it('requires host step keys to be namespaced and never a built-in key', function (string $key): void {
    Workflows::catalogue()->register(hostType($key));
})->with(['create_task', 'approval', 'notify', 'Acme.Task', 'acme.', str_repeat('a', 60).'.b1234'])->throws(InvalidStepTypeException::class);

// C-03
it('refuses a second class for the same key and accepts the same class again', function (): void {
    expect(Workflows::catalogue()->register(CreateTaskStep::class))->toBeInstanceOf(CreateTaskStep::class);

    Workflows::catalogue()->register(hostType('acme.create_task'));
})->throws(DuplicateStepTypeException::class);

it('refuses a class that is not a step type', function (): void {
    Workflows::catalogue()->register(stdClass::class);
})->throws(InvalidStepTypeException::class);

// C-04: the fixture provider registered acme.create_task in boot(), after the package booted.
it('sees host step types registered after the package booted', function (): void {
    expect(Workflows::catalogue()->has('acme.create_task'))->toBeTrue()
        ->and(Workflows::catalogue()->find('acme.spy'))->not->toBeNull()
        ->and(Workflows::catalogue()->all())->toHaveKey('delay');
});

// C-06 at registration.
it('refuses a host type with an invalid schema pattern', function (): void {
    Workflows::catalogue()->register(hostType('acme.bad', ['type' => 'string', 'pattern' => '(']));
})->throws(InvalidStepTypeException::class, 'pattern');

it('exports every type with its schema, outcomes and availability for a builder', function (): void {
    $export = Workflows::catalogue()->export();
    $condition = $export->find('condition');
    $transition = $export->find('transition');

    expect($condition?->outcomes)->toBe([
        ['key' => 'true', 'label' => 'True', 'required' => true],
        ['key' => 'false', 'label' => 'False', 'required' => true],
    ])->and($condition?->configSchema['required'])->toBe(['when'])
        ->and($transition?->available)->toBeFalse()
        ->and($transition?->package)->toBe('roundly-consulting/lifecycle-for-laravel')
        ->and($export->definitions)->toHaveKeys(['value', 'party', 'parties', 'duration', 'condition', 'field'])
        ->and($export->find('nope'))->toBeNull()
        ->and($export->toArray()['types'])->not->toBeEmpty();
});
