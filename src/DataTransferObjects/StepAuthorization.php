<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * What the authorization hook decides on: the actor, the ability, the subject and the node about
 * to run, in its instance.
 */
final readonly class StepAuthorization
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        public Actor $actor,
        public string $ability,
        public ?Model $subject,
        public Node $node,
        public WorkflowInstance $instance,
        public array $arguments = [],
    ) {}
}
