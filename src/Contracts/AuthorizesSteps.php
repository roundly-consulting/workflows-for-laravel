<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Auth\Access\Response;
use RoundlyConsulting\Workflows\DataTransferObjects\StepAuthorization;

/**
 * The authorization hook, called before every side-effect step and every escalation action. A
 * denial fails the step cleanly with the response's message.
 */
interface AuthorizesSteps
{
    public function authorize(StepAuthorization $authorization): Response;
}
