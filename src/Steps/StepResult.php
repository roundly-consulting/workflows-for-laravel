<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

/**
 * What a handler returns: complete with an outcome (and plain-JSON output), wait, or fail for
 * good. In a dry run, `wouldDo()` describes the side effect a real run would have.
 */
final readonly class StepResult
{
    public const string COMPLETE = 'complete';

    public const string WAIT = 'wait';

    public const string FAIL = 'fail';

    /**
     * @param  array<string, mixed>  $output
     * @param  list<array{description: string, details: array<string, mixed>}>  $wouldDo
     */
    private function __construct(
        public string $kind,
        public string $outcome = 'next',
        public array $output = [],
        public ?WaitSpec $wait = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $wouldDo = [],
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function complete(string $outcome = 'next', array $output = []): self
    {
        return new self(self::COMPLETE, $outcome, $output);
    }

    /**
     * @param  array<string, mixed>  $output  merged into the step's output while it waits
     */
    public static function wait(WaitSpec $spec, array $output = []): self
    {
        return new self(self::WAIT, 'next', $output, $spec);
    }

    public static function fail(string $code, string $message): self
    {
        return new self(self::FAIL, 'next', [], null, $code, $message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function wouldDo(string $description, array $details = []): self
    {
        return new self($this->kind, $this->outcome, $this->output, $this->wait, $this->failureCode, $this->failureMessage, [
            ...$this->wouldDo,
            ['description' => $description, 'details' => $details],
        ]);
    }

    public function isComplete(): bool
    {
        return $this->kind === self::COMPLETE;
    }

    public function isWait(): bool
    {
        return $this->kind === self::WAIT;
    }

    public function isFail(): bool
    {
        return $this->kind === self::FAIL;
    }
}
