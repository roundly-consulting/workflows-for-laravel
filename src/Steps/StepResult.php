<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use RoundlyConsulting\Workflows\DataTransferObjects\SideEffect;
use RoundlyConsulting\Workflows\Exceptions\InvalidFailureDetailsException;
use RoundlyConsulting\Workflows\Support\FailureDetails;

/**
 * What a handler returns: complete with an outcome (and plain-JSON output), wait, or fail for
 * good — optionally with structured failure details (an agent run's id and stop reason). In a dry
 * run, `wouldDo()` describes the side effect a real run would have.
 */
final readonly class StepResult
{
    public const string COMPLETE = 'complete';

    public const string WAIT = 'wait';

    public const string FAIL = 'fail';

    /**
     * @param  array<string, mixed>  $output
     * @param  list<SideEffect>  $wouldDo
     * @param  array<array-key, mixed>|null  $failureDetails
     */
    private function __construct(
        public string $kind,
        public string $outcome = 'next',
        public array $output = [],
        public ?WaitSpec $wait = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $wouldDo = [],
        public ?array $failureDetails = null,
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

    /**
     * Fail the step for good. `$details` is a JSON object of plain values the read API and the
     * events expose beside the code (`['run_id' => …, 'stop_reason' => …]`): values under
     * secret-looking keys are redacted, details over `workflows.failure_details.max_bytes` become
     * `{"truncated": true, "bytes": n}`, and anything that is not plain JSON is refused.
     *
     * @param  array<string, mixed>|null  $details
     *
     * @throws InvalidFailureDetailsException
     */
    public static function fail(string $code, string $message, ?array $details = null): self
    {
        return new self(self::FAIL, 'next', [], null, $code, $message, [], FailureDetails::normalise($details));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function wouldDo(string $description, array $details = []): self
    {
        return new self($this->kind, $this->outcome, $this->output, $this->wait, $this->failureCode, $this->failureMessage, [
            ...$this->wouldDo,
            new SideEffect($description, $details),
        ], $this->failureDetails);
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
