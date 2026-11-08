<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Workflows\Bridges\Lifecycle\LifecycleTransitionAdapter;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;

function transitionGraph(string $transition, array $config = []): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('go', 'transition', ['transition' => $transition, ...$config]),
        node('done', 'end'),
    ], [['start', 'go'], ['go', 'done', 'transitioned']]);
}

it('applies a transition through the lifecycle adapter as the system', function (): void {
    $team = team();
    published('go', transitionGraph('approve'), $team, 'purchase_request');
    $request = purchase($team);

    expect(Workflows::start('go', $request, $team)->dispatch()->instance)->toHaveCompletedVia(['start', 'go', 'done'])
        ->and(Teams::runAs($team, fn () => $request->refresh()->state))->toBe('approved');
});

// C-84: lifecycle history names the workflow instance.
it('names the workflow instance in the lifecycle history, truncated safely', function (): void {
    $team = team();
    published('go', transitionGraph('approve'), $team, 'purchase_request');
    $instance = Workflows::start('go', purchase($team), $team)->dispatch()->instance;

    $reason = LifecycleTransition::query()->latest('id')->value('reason');

    expect($reason)->toBe('workflow go v1 #'.$instance?->ulid);

    config()->set('lifecycle.history.reason_max_length', 10);
    published('long', transitionGraph('approve', ['reason' => str_repeat('r', 400)]), $team, 'purchase_request');
    Workflows::start('long', purchase($team), $team)->dispatch();

    expect(strlen((string) LifecycleTransition::query()->latest('id')->value('reason')))->toBe(10);
});

it('validates that the transition exists, and refuses set_field on the state column', function (): void {
    $team = team();
    $unknown = Workflows::versions()->validate(transitionGraph('teleport'), 'purchase_request', $team);
    $state = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']), node('set', 'set_field', ['field' => 'subject.state', 'value' => 'approved']), node('done', 'end'),
    ], [['start', 'set'], ['set', 'done']]), 'purchase_request', $team);

    expect($unknown->has(ValidationCode::UnknownTransition, 'go'))->toBeTrue()
        ->and($state->has(ValidationCode::FieldNotWritable, 'set'))->toBeTrue();
});

// C-96 (lifecycle half): a state column written behind publish validation's back is refused.
it('fails a direct write to a lifecycle state column', function (): void {
    $team = team();
    $graph = graph([
        node('start', 'trigger', ['trigger' => 'manual']), node('set', 'set_field', ['field' => 'subject.title', 'value' => 'x']), node('done', 'end'),
    ], [['start', 'set'], ['set', 'done']]);
    published('set', $graph, $team, 'purchase_request');

    // The host re-registers the type so `title` reads the state column.
    Workflows::subjects()->register(Workflows::subject('purchase_request', PurchaseRequest::class)->field('title', 'string', 'Title', path: 'state', writable: true));

    expect(Workflows::start('set', purchase($team), $team)->dispatch()->instance)->toHaveFailedWith('field_write_refused');
});

// C-102 + the exception mapping.
it('maps the lifecycle errors to permanent and retryable failures', function (): void {
    $team = team();
    $request = purchase($team);
    $adapter = app(LifecycleTransitionAdapter::class);

    Teams::runAs($team, fn () => $adapter->attempt($request, null, 'approve', Actor::system(), 'key-1', 'first'));

    expect(fn () => Teams::runAs($team, fn () => $adapter->attempt($request, null, 'reject', Actor::system(), 'key-1', 'second')))
        ->toThrow(StepFailedException::class)
        ->and(fn () => $adapter->attempt($request, 'nope', 'approve', Actor::system(), 'key-2', 'x'))->toThrow(StepFailedException::class)
        ->and($adapter->hasTransition(Document::class, null, 'x'))->toBeNull()
        ->and($adapter->hasTransition(PurchaseRequest::class, 'nope', 'x'))->toBeFalse()
        ->and($adapter->stateAttributes(Document::class))->toBe([])
        ->and($adapter->stateAttributes(PurchaseRequest::class))->toBe(['state']);
});

it('runs a transition on behalf of the triggering user', function (): void {
    $team = team();
    $rita = user('Rita');
    $graph = transitionGraph('approve');
    $graph['settings'] = ['actor' => 'on_behalf_of'];
    published('go', $graph, $team, 'purchase_request');

    expect(Workflows::start('go', purchase($team), $team)->by($rita)->dispatch()->instance)->toHaveCompletedVia(['start', 'go', 'done'])
        ->and(LifecycleTransition::query()->latest('id')->value('actor_id'))->toBe($rita->id);
});

it('is listed as "would run" in a dry run and needs lifecycle when it is missing', function (): void {
    Bridges::pretendMissing(['lifecycle']);

    $report = Workflows::versions()->validate(transitionGraph('approve'), 'purchase_request', team());

    expect($report->has(ValidationCode::AdapterMissing, 'go'))->toBeTrue()
        ->and(RetryableStepException::because('x', 'y')->retryAfter())->toBeNull();
});
