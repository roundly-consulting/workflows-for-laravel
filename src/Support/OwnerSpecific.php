<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;

/**
 * Finds the owner-specific values of a graph — `user` party ids, owner calendars, start targets
 * the new owner does not have, and paths a type (or its schema) marks owner-specific — and, on
 * import, clears them (sets them to null) and flags each for review.
 *
 * @internal
 */
final readonly class OwnerSpecific
{
    public function __construct(
        private StepCatalogue $catalogue,
        private CalendarRegistry $calendars,
    ) {}

    /**
     * @param  array<string, mixed>  $graph
     * @return array{graph: array<string, mixed>, flags: list<ReviewFlag>}
     */
    public function process(array $graph, bool $clear, ?Model $owner = null): array
    {
        $flags = [];

        $settings = is_array($graph['settings'] ?? null) ? $graph['settings'] : [];
        $calendar = $settings['calendar'] ?? null;

        if (is_string($calendar) && $this->calendars->isOwnerSpecific($calendar)) {
            $flags[] = new ReviewFlag('*', '/settings/calendar', 'calendar');

            if ($clear) {
                $settings['calendar'] = null;
                $graph['settings'] = $settings;
            }
        }

        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $id = $node['id'];
            $type = is_string($node['type'] ?? null) ? $this->catalogue->find($node['type']) : null;
            $paths = [];

            if ($type !== null) {
                foreach ($type->ownerSpecificPaths() as $path) {
                    $paths['/config'.$path] = 'owner_specific';
                }

                foreach (self::marked($type->configSchema(), '') as $path) {
                    $paths['/config'.$path] = 'owner_specific';
                }
            }

            $this->scan($node, '', $paths, $owner, is_string($node['type'] ?? null) ? $node['type'] : '');

            foreach ($paths as $path => $reason) {
                $value = self::get($node, $path);

                if ($value === null) {
                    continue;
                }

                $flags[] = new ReviewFlag($id, $path, $reason);

                if ($clear) {
                    $node = self::set($node, $path, null);
                }
            }

            $nodes[$index] = $node;
        }

        $graph['nodes'] = $nodes;

        return ['graph' => $graph, 'flags' => $flags];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, string>  $paths
     */
    private function scan(array $value, string $path, array &$paths, ?Model $owner, string $type): void
    {
        if (($value['kind'] ?? null) === 'user' && array_key_exists('id', $value)) {
            $paths[$path.'/id'] = 'user';
        }

        if (isset($value['unit'], $value['amount']) && is_string($value['calendar'] ?? null) && $this->calendars->isOwnerSpecific($value['calendar'])) {
            $paths[$path.'/calendar'] = 'calendar';
        }

        $isStart = ($path === '/config' && $type === 'start_workflow') || (($value['type'] ?? null) === 'start_workflow' && str_contains($path, '/actions/'));

        if ($isStart && is_string($value['definition'] ?? null) && ! Definitions::exists($value['definition'], $owner)) {
            $paths[$path.'/definition'] = 'definition';
        }

        foreach ($value as $key => $item) {
            if (is_array($item) && $key !== 'ui') {
                $this->scan($item, $path.'/'.$key, $paths, $owner, $type);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function marked(array $schema, string $path): array
    {
        $found = [];

        if (($schema['x-workflows-owner-specific'] ?? false) === true) {
            $found[] = $path;
        }

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $child) {
            if (is_array($child)) {
                $found = [...$found, ...self::marked($child, $path.'/'.$name)];
            }
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function get(array $value, string $path): mixed
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function set(array $value, string $path, mixed $new): array
    {
        $segments = explode('/', trim($path, '/'));
        $head = array_shift($segments);

        if ($segments === []) {
            $value[$head] = $new;

            return $value;
        }

        if (is_array($value[$head] ?? null)) {
            $value[$head] = self::set($value[$head], '/'.implode('/', $segments), $new);
        }

        return $value;
    }
}
