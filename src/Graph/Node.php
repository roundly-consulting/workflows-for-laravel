<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

/**
 * One graph vertex. `ui` is kept verbatim and never interpreted.
 */
final readonly class Node
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $retry
     * @param  list<array<string, mixed>>  $escalations
     * @param  array<string, mixed>|null  $ui
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $config = [],
        public ?array $retry = null,
        public array $escalations = [],
        public ?array $ui = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $node = ['id' => $this->id, 'type' => $this->type, 'config' => $this->config];

        if ($this->retry !== null) {
            $node['retry'] = $this->retry;
        }

        if ($this->escalations !== []) {
            $node['escalations'] = $this->escalations;
        }

        if ($this->ui !== null) {
            $node['ui'] = $this->ui;
        }

        return $node;
    }
}
