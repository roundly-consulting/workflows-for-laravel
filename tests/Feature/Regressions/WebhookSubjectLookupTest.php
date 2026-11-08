<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Facades\Workflows;

// Dual review O-4: an owned webhook looks its subject up among the owner's records only.

it('binds the owner\'s own subject when another owner holds the same lookup value', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    document($globex, ['title' => 'INV-7']);
    $mine = document($acme, ['title' => 'INV-7']);

    $definition = published('hook', graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'idempotency' => ['from' => 'payload:event.id'], 'subject' => ['lookup' => 'title', 'value' => ['ref' => 'payload.invoice']]]),
        node('done', 'end'),
    ], [['start', 'done']]), $acme, 'document');

    $result = Workflows::triggers()->webhook((string) $definition->webhook_id, ['event' => ['id' => 'evt_1'], 'invoice' => 'INV-7']);

    expect($result->instance?->subject_id)->toBe($mine->id);
});
