<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * A node present in both versions that changed: its type, the config leaves that differ (RFC 6901
 * pointers), its retry block and its escalations.
 */
final readonly class NodeChange
{
    /**
     * @param  list<string>  $configChanges
     */
    public function __construct(
        public string $node,
        public bool $typeChanged,
        public array $configChanges,
        public bool $retryChanged,
        public bool $escalationsChanged,
    ) {}

    /**
     * @return array{node: string, type_changed: bool, config_changes: list<string>, retry_changed: bool, escalations_changed: bool}
     */
    public function toArray(): array
    {
        return [
            'node' => $this->node,
            'type_changed' => $this->typeChanged,
            'config_changes' => $this->configChanges,
            'retry_changed' => $this->retryChanged,
            'escalations_changed' => $this->escalationsChanged,
        ];
    }
}
