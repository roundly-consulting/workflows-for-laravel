<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Models\StepRun;

/**
 * One start of a definition: the subject, the owner, the trigger input and payload, who
 * triggered it and the trigger's idempotency key. `parent` is set when a workflow starts a
 * workflow (internal).
 */
final readonly class StartData
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $key,
        public ?Model $subject = null,
        public ?Model $owner = null,
        public array $input = [],
        public ?Model $by = null,
        public ?string $idempotencyKey = null,
        public TriggerType $trigger = TriggerType::Manual,
        public array $payload = [],
        public ?StepRun $parent = null,
        public ?Request $request = null,
        public bool $submitForm = false,
        public bool $internalKey = false,
    ) {}
}
