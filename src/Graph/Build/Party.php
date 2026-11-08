<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

use Illuminate\Database\Eloquent\Model;

/**
 * A person or group a step addresses: a user by id, a registered party field, the owner's
 * members with a role, or the user who triggered the run.
 */
final readonly class Party
{
    /**
     * @param  array<string, int|string>  $value
     */
    private function __construct(
        private array $value,
    ) {}

    /**
     * A user by id (owner-specific: flagged for review on template import).
     */
    public static function user(Model|int|string $user): self
    {
        $id = $user instanceof Model ? $user->getKey() : $user;

        return new self(['kind' => 'user', 'id' => is_int($id) ? $id : (is_string($id) ? $id : '')]);
    }

    public static function field(string $path): self
    {
        return new self(['kind' => 'field', 'field' => $path]);
    }

    public static function role(string $role): self
    {
        return new self(['kind' => 'role', 'role' => $role]);
    }

    public static function triggeredBy(): self
    {
        return new self(['kind' => 'triggered_by']);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return $this->value;
    }

    /**
     * @param  list<self>  $parties
     * @return list<array<string, int|string>>
     */
    public static function list(array $parties): array
    {
        return array_map(static fn (self $party): array => $party->toArray(), $parties);
    }
}
