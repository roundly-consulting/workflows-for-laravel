<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\Workflows\Graph\Node;

/**
 * What a step run records as its `input`: the node config with every value reference resolved at
 * the start of the attempt, as the handler sees it. Literals stay verbatim, `{"ref": …}` and
 * `{"relative": …}` become their value, a model becomes `{id, type}` (the subject registry key,
 * else the class basename — never a class name), `field` and `triggered_by` parties gain the
 * resolved person's `{id, type}`. Escalations and `ui` live beside the config, so they never
 * appear. Over `workflows.steps_input.max_bytes` it is `{"truncated": true, "bytes": n}`.
 *
 * @internal
 */
final class StepInputs
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Node $node, ValueScope $scope): array
    {
        $input = [];

        foreach ($node->config as $key => $value) {
            $input[$key] = self::render($value, $scope);
        }

        return self::capped($input);
    }

    /**
     * The config of a node whose context could not be built (stored unresolved).
     *
     * @return array<string, mixed>
     */
    public static function unresolved(?Node $node): array
    {
        return self::capped($node->config ?? []);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function capped(array $input): array
    {
        $bytes = strlen((string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return $bytes > WorkflowsConfig::stepInputMaxBytes() ? ['truncated' => true, 'bytes' => $bytes] : $input;
    }

    private static function render(mixed $value, ValueScope $scope): mixed
    {
        if (Values::isRef($value) || Values::isRelative($value)) {
            return Values::plain(Values::resolve($value, $scope));
        }

        if (! is_array($value)) {
            return $value;
        }

        $rendered = [];

        foreach ($value as $key => $item) {
            $rendered[$key] = self::render($item, $scope);
        }

        $person = match ($value['kind'] ?? null) {
            'field' => is_string($value['field'] ?? null) ? Values::path($value['field'], $scope) : null,
            'triggered_by' => $scope->starter,
            default => null,
        };

        $reference = Values::plain($person);

        return is_array($reference) && array_key_exists('type', $reference) ? [...$rendered, ...$reference] : $rendered;
    }
}
