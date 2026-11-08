<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

/**
 * One stage of a multi-stage approval: `Stage::named('Legal')->from(Party::role('legal'))->any()`.
 */
final readonly class Stage
{
    /**
     * @param  list<Party>  $approvers
     */
    private function __construct(
        private ?string $name,
        private array $approvers = [],
        private ?string $rule = null,
        private ?int $quorum = null,
        private ?int $required = null,
    ) {}

    public static function named(?string $name = null): self
    {
        return new self($name);
    }

    public function from(Party ...$approvers): self
    {
        return new self($this->name, [...$this->approvers, ...array_values($approvers)], $this->rule, $this->quorum, $this->required);
    }

    public function any(): self
    {
        return new self($this->name, $this->approvers, 'any');
    }

    public function unanimous(): self
    {
        return new self($this->name, $this->approvers, 'unanimous');
    }

    public function quorum(int $quorum): self
    {
        return new self($this->name, $this->approvers, 'quorum', $quorum);
    }

    /**
     * Approvers carry weights in the approvals package; `$required` is the weight to reach.
     */
    public function weighted(int $required): self
    {
        return new self($this->name, $this->approvers, 'weighted', required: $required);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stage = [];

        if ($this->name !== null) {
            $stage['name'] = $this->name;
        }

        $stage['approvers'] = Party::list($this->approvers);

        if ($this->rule !== null) {
            $stage['rule'] = $this->rule;
        }

        if ($this->quorum !== null) {
            $stage['quorum'] = $this->quorum;
        }

        if ($this->required !== null) {
            $stage['required'] = $this->required;
        }

        return $stage;
    }
}
