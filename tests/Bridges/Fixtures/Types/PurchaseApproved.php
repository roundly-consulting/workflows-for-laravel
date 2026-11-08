<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

final class PurchaseApproved extends PurchaseType
{
    public function key(): string
    {
        return 'purchase.approved';
    }
}
