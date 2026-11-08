<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\AuthorizesSteps;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\StepAuthorization;
use RoundlyConsulting\Workflows\Exceptions\StepAuthorizationDeniedException;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * Runs the authorization hook: the ability is `workflows.authorization.abilities.<type>` when
 * set, else the one given (the type's own). Graph config can never pick a weaker one. A null
 * ability is allowed (the target package enforces access itself).
 *
 * @internal
 */
final readonly class Authorization
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @param  array<int, mixed>  $arguments
     *
     * @throws StepAuthorizationDeniedException
     */
    public function check(Actor $actor, ?string $ability, ?Model $subject, Node $node, WorkflowInstance $instance, array $arguments = []): void
    {
        $ability = WorkflowsConfig::abilities()[$node->type] ?? $ability;

        if ($ability === null || $ability === '') {
            return;
        }

        $authorizer = $this->container->make(AuthorizesSteps::class);

        $response = $authorizer->authorize(new StepAuthorization($actor, $ability, $subject, $node, $instance, $arguments));

        if ($response->denied()) {
            throw StepAuthorizationDeniedException::because($response->message(), $ability);
        }
    }
}
