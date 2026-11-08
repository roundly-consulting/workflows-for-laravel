<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Workflows\Bridges\Forms\FormsBridge;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;

/**
 * Manual-trigger input forms: the forms bridge registers the validator (and the optional
 * submission store); without it a `form` trigger refuses every start.
 *
 * @internal
 */
final class TriggerForms
{
    /** @var (Closure(string, array<string, mixed>, ?Request, bool, ?Model): array<string, mixed>)|null */
    private ?Closure $validator = null;

    /** @var (Closure(string): bool)|null */
    private ?Closure $exists = null;

    private bool $loaded = false;

    /**
     * @param  Closure(string, array<string, mixed>, ?Request, bool, ?Model): array<string, mixed>  $validator
     * @param  Closure(string): bool  $exists
     */
    public function using(Closure $validator, Closure $exists): void
    {
        $this->loaded = true;
        $this->validator = $validator;
        $this->exists = $exists;
    }

    public function available(): bool
    {
        $this->load();

        return $this->validator !== null;
    }

    public function exists(string $form): bool
    {
        $this->load();

        return $this->exists !== null && ($this->exists)($form);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(string $form, array $input, ?Request $request, bool $submit, ?Model $by): array
    {
        $this->load();

        if ($this->validator === null) {
            throw InvalidTriggerInputException::withErrors(['form' => [Messages::get('failures.adapter_missing', ['package' => 'forms-for-laravel'])]]);
        }

        return ($this->validator)($form, $input, $request, $submit, $by);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (Bridges::available('forms')) {
            FormsBridge::forms($this);
        }
    }
}
