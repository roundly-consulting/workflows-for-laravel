<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * Shared labels for the package's own step types (translated under `workflows::steps.types`).
 *
 * @internal
 */
abstract class BuiltinStep extends StepType
{
    public function label(): string
    {
        return Messages::get('steps.types.'.$this->key().'.label');
    }

    public function description(): ?string
    {
        return Messages::get('steps.types.'.$this->key().'.description');
    }

    public function category(): string
    {
        return 'flow';
    }
}
