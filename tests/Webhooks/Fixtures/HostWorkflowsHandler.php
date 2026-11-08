<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Webhooks\Fixtures;

use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;

/**
 * A host's own handler under the `workflows` alias (in its `webhooks.handlers` config).
 */
final class HostWorkflowsHandler implements InboundHandler
{
    public function handle(InboundWebhook $webhook): void {}
}
