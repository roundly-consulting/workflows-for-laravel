<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/workflows.php')->toSatisfyConfigContract([__DIR__.'/../../src', __DIR__.'/../../routes']);
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/workflows.php')->not->toBeFile();
});
