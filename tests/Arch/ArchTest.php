<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowDryRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\Builtin\BuiltinStep;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\WorkflowsManager;

$models = [
    WorkflowDefinition::class => 'workflows.models.definition',
    WorkflowVersion::class => 'workflows.models.version',
    WorkflowInstance::class => 'workflows.models.instance',
    StepRun::class => 'workflows.models.step_run',
    WorkflowWait::class => 'workflows.models.wait',
    WorkflowEscalation::class => 'workflows.models.escalation',
    WorkflowIdempotencyKey::class => 'workflows.models.idempotency_key',
    WorkflowDryRun::class => 'workflows.models.dry_run',
];

ArchPresets::strictTypes('RoundlyConsulting\Workflows');

// Non-final on purpose: the manager (the fake extends it), the step-type bases hosts extend,
// the handler-facing failure exception (subclassable by hosts), and the eight swappable models.
ArchPresets::finalByDefault('RoundlyConsulting\Workflows', [
    WorkflowsManager::class,
    StepType::class,
    BuiltinStep::class,
    StepFailedException::class,
    ...array_keys($models),
]);

ArchPresets::swappableModelsAreNotFinal($models);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Workflows');

ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support', array_values($models));

ArchPresets::factoriesResolveThroughSeam(__DIR__.'/../../database/factories', __DIR__.'/../../src');

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

// The Dependency Policy as a test: php, illuminate/* and roundly packages only. Every bridge
// package is a `suggest` + require-dev edge, never `require`.
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Workflows');

// Bridge isolation (C-132): nothing outside a bridge folder names that package's namespace.
foreach ([
    'RoundlyConsulting\Approvals' => 'Approvals',
    'RoundlyConsulting\Lifecycle' => 'Lifecycle',
    'RoundlyConsulting\Notifications' => 'Notifications',
    'RoundlyConsulting\OpeningHours' => 'OpeningHours',
    'RoundlyConsulting\Forms' => 'Forms',
    'RoundlyConsulting\Teams' => 'Teams',
    'RoundlyConsulting\Webhooks' => 'Webhooks',
] as $namespace => $bridge) {
    ArchPresets::noVendorNamespace($namespace, __DIR__.'/../../src', ['RoundlyConsulting\Workflows\Bridges\\'.$bridge]);
}

it('keeps the condition evaluator free of code execution (C-16)', function (): void {
    foreach (glob(__DIR__.'/../../src/Support/Conditions/*.php') ?: [] as $file) {
        expect(conditionsCodeExecution((string) file_get_contents($file)))->toBe([], basename($file));
    }
});

it('reds the code-execution scan on a mutation (the scan is not vacuous)', function (): void {
    expect(conditionsCodeExecution('<?php eval($x);'))->not->toBe([])
        ->and(conditionsCodeExecution('<?php call_user_func($f);'))->not->toBe([])
        ->and(conditionsCodeExecution('<?php $name();'))->not->toBe([])
        ->and(conditionsCodeExecution('<?php create_function("", "");'))->not->toBe([])
        ->and(conditionsCodeExecution('<?php strlen($x);'))->toBe([]);
});

it('renders no class name from an HTTP resource', function (): void {
    foreach (glob(__DIR__.'/../../src/Http/Resources/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->not->toContain('getMorphClass')
            ->and($source)->not->toContain('get_class(')
            ->and(preg_match('/=>\s*[^,\n]*::class/', $source))->toBe(0, basename($file));
    }
});

/**
 * @return list<string> the offending tokens
 */
function conditionsCodeExecution(string $source): array
{
    $tokens = PhpToken::tokenize($source);
    $found = [];

    foreach ($tokens as $index => $token) {
        if ($token->is(T_EVAL)) {
            $found[] = 'eval';
        }

        if ($token->is(T_STRING) && in_array(strtolower($token->text), ['create_function', 'call_user_func', 'call_user_func_array'], true)) {
            $found[] = $token->text;
        }

        if ($token->is(T_VARIABLE)) {
            $next = $tokens[$index + 1] ?? null;
            $previous = $tokens[$index - 1] ?? null;

            if ($next !== null && $next->text === '(' && ! ($previous !== null && in_array($previous->text, ['->', '::', 'function', 'fn'], true))) {
                $found[] = $token->text.'(';
            }
        }
    }

    return $found;
}
