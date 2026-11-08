<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps;

final class FlakyState
{
    public static bool $failing = true;
}
