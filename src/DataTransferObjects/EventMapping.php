<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * How a Laravel event becomes an emit: the trigger key (`*` for a self-describing WorkflowEvent),
 * the event properties holding the subject and the user, the payload (a list of public
 * properties, or one method returning an array) and the property whose value makes the emit
 * idempotent (`"{key}:{value}"`).
 */
final readonly class EventMapping
{
    public const string SELF_DESCRIBING = '*';

    /**
     * @param  list<string>|string|null  $payload
     */
    public function __construct(
        public string $key,
        public ?string $subject = null,
        public ?string $user = null,
        public array|string|null $payload = null,
        public ?string $idempotencyKey = null,
    ) {}

    public function selfDescribing(): bool
    {
        return $this->key === self::SELF_DESCRIBING;
    }
}
