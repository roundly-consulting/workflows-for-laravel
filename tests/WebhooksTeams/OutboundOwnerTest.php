<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\FakeHostResolver;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

beforeEach(function (): void {
    // Endpoint URLs are resolved (SSRF guard); never reach real DNS.
    FakeHostResolver::map(['hooks.example.com' => '93.184.216.34'])->install();
});

/**
 * An app-wide definition over the team-scoped purchase request: one `webhook` step.
 */
function publishAppWideWebhook(int|string $endpoint): void
{
    published('notify-erp', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('send', 'webhook', ['endpoint' => $endpoint, 'event' => 'purchase.approved', 'payload' => ['title' => ['ref' => 'subject.title']]]),
        node('done', 'end'),
    ], [['start', 'send'], ['send', 'done', 'queued']]), null, 'purchase_request');
}

function teamEndpoint(?Model $owner): OutboundEndpoint
{
    $outbound = $owner === null ? Webhooks::outbound() : Webhooks::for($owner)->outbound();

    return $outbound->create('https://hooks.example.com/in')->endpoint;
}

function startForPurchase(Team $team): WorkflowInstance
{
    $instance = Workflows::start('notify-erp', purchase($team))->dispatch()->instance ?? throw new LogicException;

    // App-wide definition: the instance owner is the subject's team (C-145).
    expect($instance->owner_id)->toBe($team->getKey());

    return $instance;
}

it('finds the subject team endpoint for an app-wide definition', function (): void {
    $acme = team('Acme');
    $endpoint = teamEndpoint($acme);
    publishAppWideWebhook($endpoint->id);
    $fake = Webhooks::fake();

    $instance = startForPurchase($acme);

    expect($instance)->toHaveCompletedVia(['start', 'send', 'done'])
        ->and($instance->stepRuns()->where('node_id', 'send')->sole()->output['delivery'] ?? null)->toStartWith('msg_');

    $fake->assertSentTimes(1, $endpoint);
});

it('looks up an app-wide definition endpoint in the subject team only', function (Closure $endpoint): void {
    $acme = team('Acme');
    publishAppWideWebhook($endpoint());
    Webhooks::fake();

    expect(startForPurchase($acme))->toHaveFailedWith('endpoint_not_found');

    Webhooks::assertNothingSent();
})->with([
    'owner-less' => [fn (): int|string => teamEndpoint(null)->id],
    'another team' => [fn (): int|string => teamEndpoint(team('Globex'))->id],
]);
