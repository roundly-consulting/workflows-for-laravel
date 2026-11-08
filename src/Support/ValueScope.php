<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;

/**
 * What value refs resolve against: the subject and owner (through their registered fields), the
 * trigger input and payload, earlier steps' outputs, and "now".
 *
 * @internal
 */
final readonly class ValueScope
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $trigger
     * @param  Closure(string): (array<string, mixed>|null)  $outputs
     * @param  array<string, FieldType>  $inputTypes
     */
    public function __construct(
        public ?Model $subject,
        public ?SubjectTypeDefinition $subjectType,
        public ?Model $owner,
        public ?SubjectTypeDefinition $ownerType,
        public array $input,
        public array $trigger,
        public Closure $outputs,
        public CarbonImmutable $now,
        public string $timezone,
        public ?Model $starter = null,
        public array $inputTypes = [],
    ) {}

    public function at(CarbonImmutable $now): self
    {
        return new self($this->subject, $this->subjectType, $this->owner, $this->ownerType, $this->input, $this->trigger, $this->outputs, $now, $this->timezone, $this->starter, $this->inputTypes);
    }
}
