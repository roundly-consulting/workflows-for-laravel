<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

final class PurchaseExpired extends PurchaseType
{
    public function key(): string
    {
        return 'purchase.expired';
    }
}
