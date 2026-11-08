<?php

declare(strict_types=1);
use App\Workflows\Steps\CreateTicketStep;
use RoundlyConsulting\Workflows\Facades\Workflows;

// W-25
it('generates a host step class', function (): void {
    $this->artisan('make:workflow-step', ['name' => 'CreateTicketStep'])->assertSuccessful();

    $file = app_path('Workflows/Steps/CreateTicketStep.php');
    $source = (string) file_get_contents($file);

    expect($file)->toBeFile()
        ->and($file)->toStartWith(sys_get_temp_dir())
        ->and($source)->toContain('namespace App\\Workflows\\Steps;')
        ->and($source)->toContain("return 'app.create_ticket';")
        ->and($source)->toContain("return 'Create ticket';")
        ->and($source)->toContain('final class CreateTicketStep extends StepType')
        ->and($source)->not->toContain('{{');

    require $file;

    $type = new CreateTicketStep;

    expect($type->key())->toBe('app.create_ticket')
        ->and(Workflows::catalogue()->register($type)->key())->toBe('app.create_ticket');
});
