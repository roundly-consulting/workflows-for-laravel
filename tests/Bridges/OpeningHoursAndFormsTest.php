<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RoundlyConsulting\Forms\Facades\Forms;
use RoundlyConsulting\Forms\GroupBuilder;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Contracts\CountsBusinessTime;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Doctor;
use RoundlyConsulting\Workflows\Support\TriggerForms;

function delayOn(string $calendar): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'delay', ['for' => ['amount' => 1, 'unit' => 'business_days', 'calendar' => $calendar]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done']], ['timezone' => 'UTC']);
}

// C-66
it('reports a missing opening-hours calendar at publish and at run time', function (): void {
    $team = team();

    expect(Workflows::versions()->validate(delayOn('opening_hours:office'), null, $team)->has(ValidationCode::UnknownCalendar, 'wait'))->toBeTrue();

    OpeningHours::sync($team, ['week' => ['monday' => ['09:00-17:00'], 'tuesday' => ['09:00-17:00']]], 'office');
    published('wait', delayOn('opening_hours:office'), $team, null);
    OpeningHours::delete($team, 'office', force: true);

    expect(Workflows::start('wait', null, $team)->dispatch()->instance)->toHaveFailedWith('calendar_missing')
        ->and(Workflows::calendars()->names())->toContain('opening_hours:*')
        ->and(fn () => Workflows::calendars()->find('opening_hours:office', company()))->toThrow(UnknownCalendarException::class);
});

it('reads business time off an opening-hours calendar', function (): void {
    $team = team();
    OpeningHours::sync($team, ['timezone' => 'UTC', 'week' => ['monday' => ['09:00-17:00'], 'tuesday' => ['09:00-17:00']]], 'office');

    $calendar = Workflows::calendars()->find('opening_hours:office', $team, 'UTC');
    $monday = CarbonImmutable::parse('2026-11-09 10:00', 'UTC');

    expect($calendar->timezone()->getName())->toBe('UTC')
        ->and($calendar->isBusinessDay($monday))->toBeTrue()
        ->and($calendar->isOpenAt($monday))->toBeTrue()
        ->and($calendar->nextOpen($monday)?->equalTo($monday))->toBeTrue()
        ->and($calendar->nextOpen($monday->setTime(18, 0))?->format('D H:i'))->toBe('Tue 09:00')
        ->and($calendar->nextClose($monday)?->format('D H:i'))->toBe('Mon 17:00');
});

it('uses the configured calendar owner for app-wide definitions', function (): void {
    $team = team();
    OpeningHours::sync($team, ['week' => ['monday' => ['09:00-17:00']]], 'office');
    app()->instance(CalendarOwner::class, new CalendarOwner($team));
    config()->set('workflows.business_time.calendar_owner', CalendarOwner::class);

    expect(Workflows::versions()->validate(delayOn('opening_hours:office'), null)->valid())->toBeTrue();
});

final class CalendarOwner
{
    public function __construct(private Team $team) {}

    public function __invoke(): Team
    {
        return $this->team;
    }
}

// C-21
it('validates a form-backed manual trigger through forms, storing a submission', function (): void {
    Forms::define('purchase-form', 'Purchase')
        ->public()
        ->group('details', 'Details', function (GroupBuilder $group): void {
            $group->field('reason', 'Reason')->rules(['required']);
        })
        ->create();

    published('form', graph([
        node('start', 'trigger', ['trigger' => 'manual', 'form' => 'purchase-form']),
        node('done', 'end'),
    ], [['start', 'done']]), null, null);

    expect(fn () => Workflows::start('form')->input([])->dispatch())->toThrow(InvalidTriggerInputException::class);

    $instance = Workflows::start('form')->withForm(Request::create('/', 'POST', ['purchase-form' => ['details' => ['reason' => 'New laptops']]]), submit: true)->by(user())->dispatch()->instance;

    expect($instance?->trigger_payload['input']['submission'] ?? null)->toBeString()
        ->and($instance?->trigger_payload['input']['purchase-form']['details']['reason'] ?? null)->toBe('New laptops')
        ->and(Workflows::start('form')->input(['purchase-form' => ['details' => ['reason' => 'From an array']]])->dispatch()->created)->toBeTrue();
});

// W-12 / build note 13
it('fails on an opening-hours calendar without HasOpeningHours', function (): void {
    $team = team();
    OpeningHours::sync($team, ['week' => ['monday' => ['09:00-17:00']]], 'office');
    $definition = published('wait', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']], ['calendar' => 'opening_hours:office']), $team, null);
    $checks = static fn (): array => array_map(static fn ($finding): string => $finding->level->value.' '.$finding->check, app(Doctor::class)->run());

    expect($checks())->not->toContain('fail opening hours');

    WorkflowDefinition::query()->whereKey($definition->id)->update(['owner_type' => Team::class]);

    expect($checks())->toContain('fail opening hours');
});

// W-07
it('reports an unknown form at publish', function (): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual', 'form' => 'no-such-form']),
        node('done', 'end'),
    ], [['start', 'done']]));

    expect($report->has(ValidationCode::UnknownForm, 'start'))->toBeTrue()
        ->and(app(TriggerForms::class)->exists('no-such-form'))->toBeFalse();
});

// W-29 / U-3: the BusinessClockTest vectors hold through opening-hours' own arithmetic.
function hoursCalendar(array $week, string $timezone = 'Europe/Bratislava'): BusinessCalendar
{
    $team = team('Hours '.bin2hex(random_bytes(3)));
    OpeningHours::sync($team, ['timezone' => $timezone, 'week' => $week], 'office');

    return Workflows::calendars()->find('opening_hours:office', $team, $timezone);
}

function allDay(array $days): array
{
    return array_fill_keys($days, ['00:00-24:00']);
}

it('adds business days through opening hours at the same local time', function (string $from, int $days, string $expected): void {
    $calendar = hoursCalendar(allDay(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']));

    expect($calendar)->toBeInstanceOf(CountsBusinessTime::class)
        ->and($calendar->addBusinessDays(CarbonImmutable::parse($from, 'Europe/Bratislava')->utc(), $days)->setTimezone('Europe/Bratislava')->format('D Y-m-d H:i'))->toBe($expected);
})->with([
    'Fri + 2 → Tue' => ['2026-11-06 15:00', 2, 'Tue 2026-11-10 15:00'],
    'Sat + 2 → Tue' => ['2026-11-07 10:00', 2, 'Tue 2026-11-10 10:00'],
    'Mon + 1 → Tue' => ['2026-11-09 10:00', 1, 'Tue 2026-11-10 10:00'],
    'Mon + 5 → Mon' => ['2026-11-09 10:00', 5, 'Mon 2026-11-16 10:00'],
    'n = 0 is the start' => ['2026-11-07 10:00', 0, 'Sat 2026-11-07 10:00'],
]);

it('lands on a valid instant across DST through opening hours', function (): void {
    $ny = hoursCalendar(allDay(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']), 'America/New_York');
    $sunday = hoursCalendar(allDay(['sunday']));

    expect($ny->addBusinessDays(CarbonImmutable::parse('2027-03-12 02:30', 'America/New_York')->utc(), 1)->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2027-03-15 02:30')
        ->and($sunday->addBusinessDays(CarbonImmutable::parse('2026-03-22 02:30', 'Europe/Bratislava')->utc(), 1)->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-03-29 03:30')
        ->and($sunday->addBusinessDays(CarbonImmutable::parse('2026-10-18 02:30', 'Europe/Bratislava')->utc(), 1)->setTimezone('Europe/Bratislava')->format('Y-m-d H:i P'))->toBe('2026-10-25 02:30 +02:00');
});

it('consumes only open time for business hours through opening hours', function (): void {
    $office = hoursCalendar(array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['09:00-17:00']), 'UTC');
    $always = hoursCalendar(allDay(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']), 'UTC');

    expect($office->addBusinessTime(CarbonImmutable::parse('2026-11-06 16:00', 'UTC'), 7200)->format('D H:i'))->toBe('Mon 10:00')
        ->and($office->addBusinessTime(CarbonImmutable::parse('2026-11-09 17:00', 'UTC'), 1800)->format('D H:i'))->toBe('Tue 09:30')
        ->and($office->addBusinessTime(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), 3600)->format('D H:i'))->toBe('Mon 10:00')
        ->and($office->addBusinessTime(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), 0)->format('D H:i'))->toBe('Mon 09:00')
        ->and($always->addBusinessTime(CarbonImmutable::parse('2026-11-06 23:00', 'UTC'), 7200)->format('D H:i'))->toBe('Mon 01:00');
});

it('maps running out of open time to calendar_never_open', function (string $method, int $amount): void {
    $never = hoursCalendar([], 'UTC');

    expect(fn () => $never->{$method}(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), $amount))->toThrow(BusinessCalendarNeverOpenException::class);

    try {
        $never->{$method}(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), $amount);
    } catch (BusinessCalendarNeverOpenException $exception) {
        expect($exception->errorCode())->toBe('calendar_never_open');
    }
})->with([['addBusinessDays', 1], ['addBusinessTime', 3600]]);

// The edge difference: opening-hours bounds the whole span by `opening-hours.search_days` — an
// always-open calendar included — where the package's own clock never stops on one that never
// closes.
it('bounds an always-open calendar by opening-hours search days', function (): void {
    config()->set('opening-hours.search_days', 7);
    $always = hoursCalendar(allDay(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']), 'UTC');

    expect($always->addBusinessTime(CarbonImmutable::parse('2026-11-09 00:00', 'UTC'), 3 * 86400)->format('D H:i'))->toBe('Thu 00:00')
        ->and(fn () => $always->addBusinessTime(CarbonImmutable::parse('2026-11-09 00:00', 'UTC'), 10 * 86400))->toThrow(BusinessCalendarNeverOpenException::class)
        ->and(fn () => $always->addBusinessDays(CarbonImmutable::parse('2026-11-09 00:00', 'UTC'), 10))->toThrow(BusinessCalendarNeverOpenException::class);
});

it('fails a business-time delay with calendar_never_open at run time', function (): void {
    $team = team();
    OpeningHours::sync($team, ['week' => ['monday' => ['09:00-17:00']]], 'office');
    published('wait', delayOn('opening_hours:office'), $team, null);
    OpeningHours::sync($team, ['week' => []], 'office');

    expect(Workflows::start('wait', null, $team)->dispatch()->instance)->toHaveFailedWith('calendar_never_open');
});
