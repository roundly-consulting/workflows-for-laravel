<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\ActorMode;

/**
 * Who a version runs as: the system (optionally a configured service account), or on behalf of
 * the user who triggered the instance. Every step receives it and authorizes as `user`.
 */
final readonly class Actor
{
    public function __construct(
        public ActorMode $kind,
        public ?Model $user = null,
    ) {}

    public static function system(?Model $user = null): self
    {
        return new self(ActorMode::System, $user);
    }

    public static function onBehalfOf(Model $user): self
    {
        return new self(ActorMode::OnBehalfOf, $user);
    }

    public function isSystem(): bool
    {
        return $this->kind === ActorMode::System;
    }
}
