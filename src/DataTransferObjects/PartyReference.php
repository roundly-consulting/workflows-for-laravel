<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * A person or group a step addresses: `user` (an id), `field` (a registered party field),
 * `role` (members of the owner with that role) or `triggered_by` (the instance's starter).
 */
final readonly class PartyReference
{
    public function __construct(
        public string $kind,
        public int|string|null $id = null,
        public ?string $field = null,
        public ?string $role = null,
    ) {}

    public static function fromPayload(mixed $payload): ?self
    {
        if (! is_array($payload) || ! is_string($payload['kind'] ?? null)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        $field = $payload['field'] ?? null;
        $role = $payload['role'] ?? null;

        return match ($payload['kind']) {
            'user' => is_int($id) || (is_string($id) && $id !== '') ? new self('user', id: $id) : null,
            'field' => is_string($field) && $field !== '' ? new self('field', field: $field) : null,
            'role' => is_string($role) && $role !== '' ? new self('role', role: $role) : null,
            'triggered_by' => new self('triggered_by'),
            default => null,
        };
    }

    /**
     * @return list<self>
     */
    public static function listFrom(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $parties = [];

        foreach ($payload as $item) {
            $party = self::fromPayload($item);

            if ($party !== null) {
                $parties[] = $party;
            }
        }

        return $parties;
    }
}
