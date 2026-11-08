<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use RoundlyConsulting\Workflows\Contracts\AuthorizesSteps;
use RoundlyConsulting\Workflows\DataTransferObjects\StepAuthorization;

/**
 * The default authorization hook: `Gate::forUser($actor->user)->inspect($ability, [$subject])`.
 * The system actor authorizes as the configured service account, else as a null user — so a
 * policy denies unless it declares `?User` (fail closed). A null ability — a type without one,
 * e.g. start_workflow or notify — is allowed: the target package enforces its own access.
 */
final readonly class GateStepAuthorizer implements AuthorizesSteps
{
    public function __construct(
        private Gate $gate,
    ) {}

    public function authorize(StepAuthorization $authorization): Response
    {
        if ($authorization->ability === null) {
            return Response::allow();
        }

        $arguments = $authorization->subject === null
            ? $authorization->arguments
            : [$authorization->subject, ...$authorization->arguments];

        $gate = $authorization->actor->user === null
            ? $this->gate->forUser(null)
            : $this->gate->forUser($authorization->actor->user);

        return $gate->inspect($authorization->ability, $arguments);
    }
}
