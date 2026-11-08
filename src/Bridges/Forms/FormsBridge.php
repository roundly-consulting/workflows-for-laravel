<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Forms;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Forms\FormsManager;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\TriggerForms;
use Throwable;

/**
 * forms-for-laravel, when installed: a manual trigger's `form` validates its input through the
 * form (and, on request, stores a submission whose uuid lands in the input).
 *
 * @internal
 */
final class FormsBridge
{
    public static function register(Application $app): void
    {
        if (! Bridges::available('forms')) {
            return;
        }

        $forms = $app->make(TriggerForms::class);

        $forms->using(
            static function (string $key, array $input, ?Request $request, bool $submit, ?Model $by): array {
                $manager = app(FormsManager::class);
                $form = $manager->find($key);
                $request ??= Request::create('/', 'POST', $input);

                try {
                    $validated = $manager->validate($form, $request);
                } catch (ValidationException $exception) {
                    /** @var array<string, list<string>> $errors */
                    $errors = $exception->errors();

                    throw InvalidTriggerInputException::withErrors($errors);
                }

                if ($submit) {
                    $validated['submission'] = $manager->submit($form, $request, $by)->uuid;
                }

                return $validated;
            },
            static function (string $key): bool {
                try {
                    app(FormsManager::class)->find($key);
                } catch (Throwable) {
                    return false;
                }

                return true;
            },
        );
    }
}
