<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates a host step type (`app/Workflows/Steps/<Name>.php`) with the key `app.<snake_name>`
 * and a config schema written with ConfigSchema.
 */
final class MakeStepCommand extends GeneratorCommand
{
    protected $name = 'make:workflow-step';

    protected $description = 'Create a workflow step type';

    protected $type = 'Workflow step';

    /**
     * The application namespace, or `App\` when it cannot be read from composer.json.
     */
    protected function rootNamespace(): string
    {
        try {
            return $this->laravel->getNamespace();
        } catch (RuntimeException) {
            return 'App\\';
        }
    }

    protected function getStub(): string
    {
        return __DIR__.'/../../resources/stubs/workflow-step.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Workflows\\Steps';
    }

    protected function buildClass($name): string
    {
        $class = class_basename($name);
        $base = Str::of($class)->beforeLast('Step')->whenEmpty(static fn () => Str::of($class));

        return str_replace(
            ['{{ key }}', '{{ label }}'],
            ['app.'.$base->snake()->value(), $base->snake(' ')->ucfirst()->value()],
            parent::buildClass($name),
        );
    }
}
