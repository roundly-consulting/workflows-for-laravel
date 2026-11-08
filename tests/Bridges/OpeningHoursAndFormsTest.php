<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RoundlyConsulting\Forms\Facades\Forms;
use RoundlyConsulting\Forms\GroupBuilder;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Facades\Workflows;

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
