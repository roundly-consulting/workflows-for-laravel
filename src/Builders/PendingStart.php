<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * A manual start being built: `Workflows::start($key, $subject, $owner)->input([...])->by($user)
 * ->idempotencyKey('submit:42')->dispatch()`.
 */
final class PendingStart
{
    /** @var array<string, mixed> */
    private array $input = [];

    private ?Model $by = null;

    private ?string $idempotencyKey = null;

    private ?Request $request = null;

    private bool $submitForm = false;

    public function __construct(
        private readonly WorkflowsManager $manager,
        private readonly string $key,
        private readonly ?Model $subject = null,
        private readonly ?Model $owner = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function input(array $input): self
    {
        $this->input = $input;

        return $this;
    }

    /**
     * The triggering user (required for `on_behalf_of` versions).
     */
    public function by(?Model $user): self
    {
        $this->by = $user;

        return $this;
    }

    /**
     * One instance per key: a second start with the same key returns the first instance.
     */
    public function idempotencyKey(?string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * Validate a form-backed trigger against this request (forms bridge); `submit` also stores a
     * submission and puts its uuid in the trigger payload.
     */
    public function withForm(Request $request, bool $submit = false): self
    {
        $this->request = $request;
        $this->submitForm = $submit;

        return $this;
    }

    public function dispatch(): StartResult
    {
        return $this->manager->startWith(new StartData(
            key: $this->key,
            subject: $this->subject,
            owner: $this->owner,
            input: $this->input,
            by: $this->by,
            idempotencyKey: $this->idempotencyKey,
            trigger: TriggerType::Manual,
            request: $this->request,
            submitForm: $this->submitForm,
        ));
    }
}
