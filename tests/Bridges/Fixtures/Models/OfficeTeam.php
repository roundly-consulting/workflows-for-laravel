<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models;

use RoundlyConsulting\OpeningHours\Concerns\HasOpeningHours;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\Teams\Models\Team;

/**
 * A team with opening-hours calendars (the host's swapped team model).
 */
class OfficeTeam extends Team implements OpeningHoursOwner
{
    use HasOpeningHours;
}
