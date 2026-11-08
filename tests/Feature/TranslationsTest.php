<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;

$files = ['enums', 'exceptions', 'failures', 'fields', 'steps', 'validation'];

it('ships the same keys in English and Slovak', function (string $file): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $sk = Arr::dot(require __DIR__.'/../../resources/lang/sk/'.$file.'.php');

    expect(array_keys($sk))->toEqualCanonicalizing(array_keys($en));

    foreach ($en as $key => $line) {
        preg_match_all('/:[a-z_]+/', (string) $line, $enPlaceholders);
        preg_match_all('/:[a-z_]+/', (string) $sk[$key], $skPlaceholders);

        expect(array_unique($skPlaceholders[0]))->toEqualCanonicalizing(array_unique($enPlaceholders[0]), $file.'.'.$key);
    }
})->with($files);

it('renders Slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('workflows::failures.subject_missing'))->toBe('Subjekt už neexistuje alebo ho vlastník nevidí.')
        ->and(InstanceStatus::Waiting->label())->toBe('Čaká');
});

it('pluralises for every count', function (int $count, string $en, string $sk): void {
    app()->setLocale('en');
    expect(trans_choice('workflows::steps.instances', $count, ['count' => $count]))->toBe($en);

    app()->setLocale('sk');
    expect(trans_choice('workflows::steps.instances', $count, ['count' => $count]))->toBe($sk);
})->with([
    [0, '0 instances', '0 inštancií'],
    [1, '1 instance', '1 inštancia'],
    [3, '3 instances', '3 inštancie'],
    [5, '5 instances', '5 inštancií'],
]);
