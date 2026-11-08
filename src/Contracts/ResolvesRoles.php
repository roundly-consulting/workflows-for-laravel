<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The members of an owner holding a role: what `{"kind": "role", "role": "legal"}` parties
 * resolve to.
 */
interface ResolvesRoles
{
    /**
     * @return list<Model>
     */
    public function members(?Model $owner, string $role): array;
}
