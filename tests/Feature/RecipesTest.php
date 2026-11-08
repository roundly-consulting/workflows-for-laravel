<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Task;

// W-08: the recipes of the technical docs (recipes.md), as files. Each is synced from its file,
// published and driven to its documented end state.

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * @return list<string>
 */
function recipeFiles(): array
{
    return glob(__DIR__.'/../Fixtures/recipes/*.json') ?: [];
}

function recipeAt(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
}

function recipeInstance(string $key): WorkflowInstance
{
    return WorkflowInstance::query()->whereHas('definition', fn ($query) => $query->where('key', $key))->latest('id')->firstOrFail();
}

/**
 * How each recipe is driven, and the end state its docs promise.
 *
 * @return array<string, Closure(): void>
 */
function recipeDrivers(): array
{
    return [
        'purchase-approval' => function (): void {
            $big = document(null, ['amount' => '9000', 'approver_id' => user('Adam')->id, 'requester_id' => user('Rita')->id]);
            $small = document(null, ['amount' => '100']);

            Workflows::emit('purchase_request.submitted', $big, idempotencyKey: 'submit:'.$big->id);
            $approved = recipeInstance('purchase-approval');
            Workflows::emit('purchase_request.submitted', $small, idempotencyKey: 'submit:'.$small->id);

            expect($approved)->toHaveCompletedVia(['start', 'big', 'approve', 'mark', 'done'])
                ->and($big->refresh()->priority)->toBe('approved')
                ->and(recipeInstance('purchase-approval'))->toHaveCompletedVia(['start', 'big', 'mark', 'done']);
        },
        'rework-loop' => function (): void {
            $document = document(null, ['approver_id' => user('Adam')->id]);

            Workflows::emit('purchase_request.submitted', $document);
            $instance = recipeInstance('rework-loop');

            expect($instance)->toBeWaitingAt('rework');

            Workflows::emit('purchase_request.resubmitted', $document);

            expect($instance)->toHaveCompletedVia(['start', 'round', 'approve', 'rework', 'round', 'approve', 'done']);
        },
        'onboarding' => function (): void {
            $instance = Workflows::start('onboarding')->input(['employee' => 'Ada'])->dispatch()->instance ?? throw new LogicException;

            expect($instance)->toHaveCompletedVia(['start', 'laptop', 'accounts', 'welcome', 'done'])
                ->and(Task::query()->pluck('title')->all())->toBe(['Order a laptop', 'Create the accounts'])
                ->and(recipeInstance('welcome')->status)->toBe(InstanceStatus::Completed);
        },
        'sla-reminder' => function (): void {
            recipeAt('2026-11-09 08:00:00 UTC');   // Monday
            $ticket = document(null, ['title' => 'Printer on fire']);

            Workflows::emit('ticket.opened', $ticket);
            $instance = recipeInstance('sla-reminder');

            expect($instance)->toBeWaitingAt('resolved');

            $this->travelAndWake(CarbonImmutable::parse('2026-11-09 13:00:00 UTC'));

            expect(recipeInstance('sla-chase')->status)->toBe(InstanceStatus::Completed);

            $ticket->update(['title' => 'Resolved']);

            expect($instance)->toHaveCompletedVia(['start', 'resolved', 'done']);
        },
        'nightly' => function (): void {
            recipeAt('2026-11-09 12:00:00 UTC');
            Workflows::definitions()->sync(__DIR__.'/../Fixtures/recipes/nightly.json', publish: true);

            $this->travelAndWake(CarbonImmutable::parse('2026-11-10 01:05:00 UTC'));   // 02:05 in Bratislava

            expect(recipeInstance('nightly'))->toHaveCompletedVia(['start', 'report', 'done'])
                ->and(Task::query()->pluck('title')->all())->toBe(['Nightly report']);
        },
    ];
}

it('publishes and runs recipe :name', function (string $name): void {
    $this->fakeStep('approval', static fn (StepContext $context): StepResult => StepResult::complete($context->visit() === 1 && $context->definitionKey() === 'rework-loop' ? 'rejected' : 'approved'), outcomes: ['approved', 'rejected', 'expired']);
    $this->fakeStep('notify', 'next', outcomes: ['next']);
    published('welcome', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    published('sla-chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]));

    $report = Workflows::definitions()->sync(__DIR__.'/../Fixtures/recipes/'.$name.'.json', publish: true);

    expect($report->failed())->toBeFalse($name.': '.($report->definitions[0]->message ?? ''))
        ->and($report->definitions[0]->status->value)->toBe('published');

    recipeDrivers()[$name]->call($this);
})->with(fn (): array => array_map(static fn (string $file): string => basename($file, '.json'), recipeFiles()));

it('has a driver for every recipe file', function (): void {
    expect(array_map(static fn (string $file): string => basename($file, '.json'), recipeFiles()))->toEqualCanonicalizing(array_keys(recipeDrivers()))
        ->and(recipeFiles())->toHaveCount(5);
});
