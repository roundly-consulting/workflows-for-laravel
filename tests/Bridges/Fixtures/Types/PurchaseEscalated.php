<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

final class PurchaseEscalated extends PurchaseType
{
    public function key(): string
    {
        return 'purchase.escalated';
    }
}
