<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Graph\Reachability;

/**
 * What a step type's `validateConfig()` validates against: the graph, the node, the subject and
 * owner field schemas, the trigger's declared inputs and the owner.
 */
final readonly class ValidationScope
{
    /**
     * @param  array<string, FieldType>  $inputs
     */
    public function __construct(
        public Graph $graph,
        public ?Node $node,
        public ?SubjectTypeDefinition $subjectType,
        public ?SubjectTypeDefinition $ownerType,
        public ?Model $owner,
        public array $inputs = [],
        public ?string $definitionKey = null,
    ) {}

    public function forNode(Node $node): self
    {
        return new self($this->graph, $node, $this->subjectType, $this->ownerType, $this->owner, $this->inputs, $this->definitionKey);
    }

    /**
     * Whether `$nodeId` runs before this scope's node on every path that could reach it (an
     * ancestor), so its output can be referenced.
     */
    public function isAncestor(string $nodeId): bool
    {
        if ($this->node === null) {
            return false;
        }

        return isset(Reachability::ancestors($this->graph, $this->node->id)[$nodeId]);
    }
}
