<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;

// Dual review O-2: cancelling a subject's instances survives a parent cascading to its child.

function cancelAllParentAndChild(): void
{
    $acme = Company::query()->firstOrFail();

    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'doc.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), $acme);
    published('parent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('sw', 'start_workflow', ['definition' => 'child', 'wait' => true]),
        node('done', 'end'),
    ], [['start', 'sw'], ['sw', 'done', 'completed'], ['sw', 'done', 'failed'], ['sw', 'done', 'cancelled']]), $acme);
}

it('cancels a parent and its waited child of the same subject', function (): void {
    $doc = document(company());
    cancelAllParentAndChild();

    Workflows::for($doc)->start('parent')->dispatch();
    expect(WorkflowInstance::query()->whereIn('status', ['running', 'waiting'])->count())->toBe(2);

    expect(Workflows::for($doc)->cancelAll('done'))->toBe(2)
        ->and(WorkflowInstance::query()->pluck('status')->all())->each->toBe(InstanceStatus::Cancelled);
});

it('deletes a subject whose parent and waited child both run', function (): void {
    $doc = document(company());
    cancelAllParentAndChild();

    Workflows::for($doc)->start('parent')->dispatch();

    $doc->delete();

    expect(WorkflowInstance::query()->where('status', 'cancelled')->count())->toBe(2);
});
