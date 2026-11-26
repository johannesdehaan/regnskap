<?php

use App\Models\Holding;
use App\Models\HoldingPriceSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // A Wednesday, well before the default time of 16:45.
    Carbon::setTestNow(osloTime('2026-11-25 12:00:00'));

    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a user without a price schedule gets weekdays at 16:45, counted from now', function () {
    $user = User::factory()->create();

    $schedule = HoldingPriceSchedule::for($user);

    expect($schedule->times)->toBe(['16:45'])
        ->and($schedule->weekdays)->toBe([1, 2, 3, 4, 5])
        ->and($schedule->last_run_at)->toBeNull()
        ->and($schedule->dueTime(now()))->toBeNull()
        ->and($schedule->nextTime(now())?->toIso8601String())->toBe('2026-11-25T16:45:00+01:00')
        ->and(HoldingPriceSchedule::for($user)->id)->toBe($schedule->id)
        ->and(HoldingPriceSchedule::query()->count())->toBe(1);
});

test('a scheduled time is due from its minute until the update has started for it', function (string $handledUntil, string $now, ?string $due) {
    $schedule = HoldingPriceSchedule::factory()->create(['handled_until' => osloTime($handledUntil)]);

    expect($schedule->dueTime(osloTime($now))?->toIso8601String())->toBe($due);
})->with([
    'the second before' => ['2026-11-24 17:00:00', '2026-11-25 16:44:59', null],
    'on the minute' => ['2026-11-24 17:00:00', '2026-11-25 16:45:00', '2026-11-25T16:45:00+01:00'],
    'later in the same minute' => ['2026-11-24 17:00:00', '2026-11-25 16:45:59', '2026-11-25T16:45:00+01:00'],
    'later the same day' => ['2026-11-24 17:00:00', '2026-11-25 23:10:00', '2026-11-25T16:45:00+01:00'],
    'the next morning' => ['2026-11-24 17:00:00', '2026-11-26 08:00:00', '2026-11-25T16:45:00+01:00'],
    'several days later, only the latest time' => ['2026-11-24 17:00:00', '2026-11-28 09:00:00', '2026-11-27T16:45:00+01:00'],
    'after the update has started for it' => ['2026-11-25 16:45:00', '2026-11-25 16:45:30', null],
    'on a saturday, with friday done' => ['2026-11-27 16:45:00', '2026-11-28 16:45:00', null],
    'on a sunday, with friday done' => ['2026-11-27 16:45:00', '2026-11-29 16:45:00', null],
    'the monday after' => ['2026-11-27 16:45:00', '2026-11-30 16:45:00', '2026-11-30T16:45:00+01:00'],
]);

test('the times are read as oslo time, in winter and in summer', function (string $utc, ?string $due) {
    $schedule = HoldingPriceSchedule::factory()->create([
        'handled_until' => Carbon::parse($utc, 'UTC')->subHour()->setTimezone(config('app.timezone')),
    ]);

    expect($schedule->dueTime(Carbon::parse($utc, 'UTC'))?->toIso8601String())->toBe($due);
})->with([
    'winter, a minute early' => ['2026-11-25 15:44:00', null],
    'winter' => ['2026-11-25 15:45:00', '2026-11-25T16:45:00+01:00'],
    'summer, a minute early' => ['2026-06-24 14:44:00', null],
    'summer' => ['2026-06-24 14:45:00', '2026-06-24T16:45:00+02:00'],
]);

test('only the chosen weekdays count', function () {
    $schedule = HoldingPriceSchedule::factory()->create([
        'times' => ['10:00'],
        'weekdays' => [6, 7],
        'handled_until' => osloTime('2026-11-25 12:00:00'),
    ]);

    expect($schedule->dueTime(osloTime('2026-11-27 10:00:00')))->toBeNull()
        ->and($schedule->dueTime(osloTime('2026-11-28 10:00:00'))?->toIso8601String())->toBe('2026-11-28T10:00:00+01:00')
        ->and($schedule->dueTime(osloTime('2026-11-29 10:00:00'))?->toIso8601String())->toBe('2026-11-29T10:00:00+01:00')
        ->and($schedule->nextTime(osloTime('2026-11-29 10:00:00'))?->toIso8601String())->toBe('2026-12-05T10:00:00+01:00');
});

test('each of several times a day is due once', function () {
    $schedule = HoldingPriceSchedule::factory()->create([
        'times' => ['16:45', '09:30', '12:00'],
        'handled_until' => osloTime('2026-11-24 17:00:00'),
    ]);

    $started = [];

    foreach (['09:29', '09:30', '09:31', '11:59', '12:00', '12:00', '16:44', '16:45', '16:46', '23:59'] as $clock) {
        $due = $schedule->dueTime(osloTime('2026-11-25 '.$clock.':00'));

        if ($due !== null && $schedule->claim($due)) {
            $started[] = $clock;
        }
    }

    expect($started)->toBe(['09:30', '12:00', '16:45']);
});

test('several missed times are made up for with one run', function () {
    $schedule = HoldingPriceSchedule::factory()->create([
        'times' => ['09:30', '12:00', '16:45'],
        'handled_until' => osloTime('2026-11-24 17:00:00'),
    ]);

    $now = osloTime('2026-11-25 17:00:00');
    $due = $schedule->dueTime($now);

    expect($due?->toIso8601String())->toBe('2026-11-25T16:45:00+01:00')
        ->and($schedule->claim($due))->toBeTrue()
        ->and($schedule->dueTime($now))->toBeNull();
});

test('only one of two checks gets the same time', function () {
    $schedule = HoldingPriceSchedule::factory()->create(['handled_until' => osloTime('2026-11-24 17:00:00')]);
    $other = HoldingPriceSchedule::query()->findOrFail($schedule->id);

    Carbon::setTestNow(osloTime('2026-11-25 16:45:03'));

    $due = $schedule->dueTime(now());
    $otherDue = $other->dueTime(now());

    expect($due)->not->toBeNull()
        ->and($otherDue)->not->toBeNull()
        ->and($schedule->claim($due))->toBeTrue()
        ->and($other->claim($otherDue))->toBeFalse()
        ->and($schedule->claim($due))->toBeFalse();

    $other->refresh();

    expect(inOslo($other->handled_until))->toBe('2026-11-25T16:45:00+01:00')
        ->and(inOslo($other->last_run_at))->toBe('2026-11-25T16:45:03+01:00');
});

test('a time in the hour that comes twice when the clocks go back is due once', function (string $appTimezone) {
    // Stored times are wall time in the app's timezone, so the hour is ambiguous in one and not in the other.
    config(['app.timezone' => $appTimezone]);
    date_default_timezone_set($appTimezone);

    $schedule = HoldingPriceSchedule::factory()->create([
        'times' => ['02:30'],
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'handled_until' => osloTime('2027-10-30 12:00:00'),
    ]);

    $started = 0;

    // 02:30 and 02:31 in summer time, and then the same two minutes in winter time.
    foreach (['00:30', '00:31', '01:30', '01:31'] as $utc) {
        $due = $schedule->dueTime(Carbon::parse('2027-10-31 '.$utc.':00', 'UTC'));

        if ($due !== null && $schedule->claim($due)) {
            $started++;
        }
    }

    expect($started)->toBe(1);
})->with(['Europe/Oslo', 'UTC']);

test('a time in the hour that is skipped when the clocks go forward is due an hour later', function () {
    $schedule = HoldingPriceSchedule::factory()->create([
        'times' => ['02:30'],
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'handled_until' => osloTime('2027-03-27 12:00:00'),
    ]);

    expect($schedule->nextTime(osloTime('2027-03-27 12:00:00'))?->toIso8601String())->toBe('2027-03-28T03:30:00+02:00')
        ->and($schedule->dueTime(Carbon::parse('2027-03-28 01:29:00', 'UTC')))->toBeNull()
        ->and($schedule->dueTime(Carbon::parse('2027-03-28 01:30:00', 'UTC'))?->toIso8601String())->toBe('2027-03-28T03:30:00+02:00');
});

test('the next time is the first one after now', function (array $times, array $weekdays, string $now, string $next) {
    $schedule = HoldingPriceSchedule::factory()->create(['times' => $times, 'weekdays' => $weekdays]);

    expect($schedule->nextTime(osloTime($now))?->toIso8601String())->toBe($next);
})->with([
    'later today' => [['16:45'], [1, 2, 3, 4, 5], '2026-11-25 16:44:00', '2026-11-25T16:45:00+01:00'],
    'tomorrow, on the minute itself' => [['16:45'], [1, 2, 3, 4, 5], '2026-11-25 16:45:00', '2026-11-26T16:45:00+01:00'],
    'monday, from friday evening' => [['16:45'], [1, 2, 3, 4, 5], '2026-11-27 16:45:00', '2026-11-30T16:45:00+01:00'],
    'the next of several today' => [['16:45', '09:30', '12:00'], [1, 2, 3, 4, 5], '2026-11-25 09:30:00', '2026-11-25T12:00:00+01:00'],
    'a week ahead, with one weekday' => [['08:00'], [3], '2026-11-25 08:00:00', '2026-12-02T08:00:00+01:00'],
]);

test('a change counts from now on, and the same values again change nothing', function () {
    $schedule = HoldingPriceSchedule::factory()->create(['handled_until' => osloTime('2026-11-24 17:00:00')]);

    Carbon::setTestNow(osloTime('2026-11-25 18:00:00'));

    $schedule->reschedule(['16:45'], [5, 4, 3, 2, 1]);

    expect(inOslo($schedule->handled_until))->toBe('2026-11-24T17:00:00+01:00')
        ->and($schedule->dueTime(now())?->toIso8601String())->toBe('2026-11-25T16:45:00+01:00');

    $schedule->reschedule(['16:45', '10:00', '16:45'], [5, '3', 1, 5]);
    $schedule->refresh();

    expect($schedule->times)->toBe(['10:00', '16:45'])
        ->and($schedule->weekdays)->toBe([1, 3, 5])
        ->and(inOslo($schedule->handled_until))->toBe('2026-11-25T18:00:00+01:00')
        ->and($schedule->dueTime(now()))->toBeNull()
        ->and($schedule->nextTime(now())?->toIso8601String())->toBe('2026-11-27T10:00:00+01:00')
        ->and($schedule->dueTime(osloTime('2026-11-27 10:00:00'))?->toIso8601String())->toBe('2026-11-27T10:00:00+01:00');
});

test('the due check starts the update at a scheduled time, and only once', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->for($user)->create([
        'ticker' => 'EKSEMPEL.OL',
        'price' => '10.0000',
        'quantity' => '10.000000',
        'value' => '100.00',
    ]);
    $schedule = HoldingPriceSchedule::for($user);

    fakeYahooPrices(['EKSEMPEL.OL' => 12.5]);

    Carbon::setTestNow(osloTime('2026-11-25 16:44:00'));
    $this->artisan('holdings:update-prices --due')
        ->doesntExpectOutputToContain('oppdatert')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect($holding->fresh()->price)->toBe('10.0000');

    Carbon::setTestNow(osloTime('2026-11-25 16:45:02'));
    $this->artisan('holdings:update-prices --due')
        ->expectsOutputToContain('Bruker '.$user->id.': kursplanen passerte 25.11.2026 16:45.')
        ->expectsOutputToContain('1 oppdatert, 0 hoppet over.')
        ->assertSuccessful();

    $holding->refresh();
    $schedule->refresh();

    expect($holding->price)->toBe('12.5000')
        ->and($holding->value)->toBe('125.00')
        ->and(inOslo($holding->price_updated_at))->toBe('2026-11-25T16:45:02+01:00')
        ->and(inOslo($schedule->handled_until))->toBe('2026-11-25T16:45:00+01:00')
        ->and(inOslo($schedule->last_run_at))->toBe('2026-11-25T16:45:02+01:00');

    Http::assertSentCount(1);

    foreach (['2026-11-25 16:45:40', '2026-11-25 16:46:00', '2026-11-25 23:59:00', '2026-11-26 16:44:00'] as $later) {
        Carbon::setTestNow(osloTime($later));
        $this->artisan('holdings:update-prices --due')
            ->doesntExpectOutputToContain('oppdatert')
            ->assertSuccessful();
    }

    Http::assertSentCount(1);

    Carbon::setTestNow(osloTime('2026-11-26 16:45:00'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    Http::assertSentCount(2);
});

test('a time that passed while the app was asleep runs at the first check after it', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->for($user)->create(['ticker' => 'EKSEMPEL.OL', 'quantity' => '2.000000']);
    $schedule = HoldingPriceSchedule::for($user);

    fakeYahooPrices(['EKSEMPEL.OL' => 50]);

    // No check ran on Wednesday, Thursday or Friday. The app wakes on Saturday morning.
    Carbon::setTestNow(osloTime('2026-11-28 09:12:00'));
    $this->artisan('holdings:update-prices --due')
        ->expectsOutputToContain('kursplanen passerte 27.11.2026 16:45.')
        ->assertSuccessful();

    Carbon::setTestNow(osloTime('2026-11-28 09:13:00'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    expect($holding->fresh()->value)->toBe('100.00')
        ->and(inOslo($holding->fresh()->price_updated_at))->toBe('2026-11-28T09:12:00+01:00')
        ->and(inOslo($schedule->fresh()->last_run_at))->toBe('2026-11-28T09:12:00+01:00');

    Http::assertSentCount(1);
});

test('the due check gives a user without a schedule the default one, from now on', function () {
    $holding = Holding::factory()->create(['ticker' => 'EKSEMPEL.OL', 'price' => '10.0000']);

    fakeYahooPrices(['EKSEMPEL.OL' => 12.5]);

    // The first check comes ten seconds after 16:45, so that time has passed before the schedule exists.
    Carbon::setTestNow(osloTime('2026-11-25 16:45:10'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    $schedule = HoldingPriceSchedule::query()->sole();

    expect($schedule->user_id)->toBe($holding->user_id)
        ->and($schedule->times)->toBe(['16:45'])
        ->and($schedule->weekdays)->toBe([1, 2, 3, 4, 5])
        ->and($schedule->last_run_at)->toBeNull();

    Http::assertNothingSent();

    Carbon::setTestNow(osloTime('2026-11-26 16:45:00'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    expect($holding->fresh()->price)->toBe('12.5000');
});

test('each user follows their own schedule', function () {
    $afternoonUser = User::factory()->create();
    $thursdayUser = User::factory()->create();
    $afternoonHolding = Holding::factory()->for($afternoonUser)->create(['ticker' => 'EKSEMPEL.OL', 'price' => '10.0000']);
    $thursdayHolding = Holding::factory()->for($thursdayUser)->create(['ticker' => 'PROVE.OL', 'price' => '10.0000']);

    HoldingPriceSchedule::for($afternoonUser);
    HoldingPriceSchedule::for($thursdayUser)->reschedule(['09:30'], [4]);

    fakeYahooPrices(['EKSEMPEL.OL' => 20, 'PROVE.OL' => 30]);

    Carbon::setTestNow(osloTime('2026-11-25 16:45:00'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    expect($afternoonHolding->fresh()->price)->toBe('20.0000')
        ->and($thursdayHolding->fresh()->price)->toBe('10.0000');

    Carbon::setTestNow(osloTime('2026-11-26 09:30:00'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    expect($thursdayHolding->fresh()->price)->toBe('30.0000')
        ->and(inOslo($thursdayHolding->fresh()->price_updated_at))->toBe('2026-11-26T09:30:00+01:00')
        ->and(inOslo($afternoonHolding->fresh()->price_updated_at))->toBe('2026-11-25T16:45:00+01:00');

    Http::assertSentCount(2);
});

test('users who are due at the same time share one request for the same ticker', function () {
    $holdings = Holding::factory()->count(2)->create(['ticker' => 'EKSEMPEL.OL', 'price' => '10.0000']);

    foreach ($holdings as $holding) {
        HoldingPriceSchedule::for($holding->user);
    }

    fakeYahooPrices(['EKSEMPEL.OL' => 20]);

    Carbon::setTestNow(osloTime('2026-11-25 16:45:00'));
    $this->artisan('holdings:update-prices --due')
        ->expectsOutputToContain('2 oppdatert, 0 hoppet over.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

test('the command without --due updates everything now and leaves the schedules alone', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->for($user)->create(['ticker' => 'EKSEMPEL.OL', 'price' => '10.0000']);
    $schedule = HoldingPriceSchedule::for($user);

    fakeYahooPrices(['EKSEMPEL.OL' => 20]);

    $this->artisan('holdings:update-prices')->assertSuccessful();

    expect($holding->fresh()->price)->toBe('20.0000')
        ->and($schedule->fresh()->last_run_at)->toBeNull()
        ->and($schedule->fresh()->dueTime(osloTime('2026-11-25 16:45:00')))->not->toBeNull();
});

test('the due check is scheduled every minute', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'holdings:update-prices'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events[0]->command)->toEndWith('holdings:update-prices --due')
        ->and($events[0]->expression)->toBe('* * * * *');
});

test('the price schedule api requires a token', function () {
    $this->getJson('/api/holdings/price-schedule')->assertUnauthorized();
    $this->patchJson('/api/holdings/price-schedule', ['times' => ['09:30']])->assertUnauthorized();

    expect(HoldingPriceSchedule::query()->count())->toBe(0);
});

test('the price schedule starts as weekdays at 16:45', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/holdings/price-schedule')
        ->assertOk()
        ->assertExactJson([
            'times' => ['16:45'],
            'weekdays' => [1, 2, 3, 4, 5],
            'timezone' => 'Europe/Oslo',
            'next_run_at' => '2026-11-25T15:45:00.000000Z',
            'last_run_at' => null,
        ]);
});

test('patch changes the times and the weekdays, together or one at a time', function () {
    $user = User::factory()->create();
    $other = HoldingPriceSchedule::for(User::factory()->create());
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/holdings/price-schedule', [
            'times' => ['16:45', '09:30', '16:45'],
            'weekdays' => [7, 1, '3', 1],
        ])
        ->assertOk()
        ->assertExactJson([
            'times' => ['09:30', '16:45'],
            'weekdays' => [1, 3, 7],
            'timezone' => 'Europe/Oslo',
            'next_run_at' => '2026-11-25T15:45:00.000000Z',
            'last_run_at' => null,
        ]);

    $this->withToken($token)
        ->patchJson('/api/holdings/price-schedule', ['weekdays' => [6]])
        ->assertOk()
        ->assertJsonPath('times', ['09:30', '16:45'])
        ->assertJsonPath('weekdays', [6])
        ->assertJsonPath('next_run_at', '2026-11-28T08:30:00.000000Z');

    $this->withToken($token)
        ->patchJson('/api/holdings/price-schedule', ['times' => ['07:05']])
        ->assertOk()
        ->assertJsonPath('times', ['07:05'])
        ->assertJsonPath('weekdays', [6]);

    $this->withToken($token)
        ->getJson('/api/holdings/price-schedule')
        ->assertOk()
        ->assertJsonPath('times', ['07:05'])
        ->assertJsonPath('weekdays', [6])
        ->assertJsonPath('next_run_at', '2026-11-28T06:05:00.000000Z');

    $other->refresh();

    expect($other->times)->toBe(['16:45'])
        ->and($other->weekdays)->toBe([1, 2, 3, 4, 5])
        ->and(HoldingPriceSchedule::query()->count())->toBe(2);
});

test('the price schedule api shows when the schedule last started the update', function () {
    $user = User::factory()->create();
    HoldingPriceSchedule::for($user);

    Http::fake();

    Carbon::setTestNow(osloTime('2026-11-25 16:45:07'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/holdings/price-schedule')
        ->assertOk()
        ->assertJsonPath('last_run_at', '2026-11-25T15:45:07.000000Z')
        ->assertJsonPath('next_run_at', '2026-11-26T15:45:00.000000Z');

    Http::assertNothingSent();
});

test('the price schedule api rejects malformed times and weekdays', function (string $field, mixed $value, string $error, string $message) {
    $user = User::factory()->create();
    $schedule = HoldingPriceSchedule::for($user);
    $schedule->reschedule(['09:30', '16:45'], [1, 3, 5]);

    Carbon::setTestNow(osloTime('2026-11-25 13:00:00'));

    $this->withToken($user->createToken('test')->plainTextToken)
        ->patchJson('/api/holdings/price-schedule', [
            'times' => ['08:00'],
            'weekdays' => [2],
            $field => $value,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$error => $message]);

    $schedule->refresh();

    expect($schedule->times)->toBe(['09:30', '16:45'])
        ->and($schedule->weekdays)->toBe([1, 3, 5])
        ->and(inOslo($schedule->handled_until))->toBe('2026-11-25T12:00:00+01:00');
})->with([
    'a time without a leading zero' => ['times', ['9:30'], 'times.0', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'a time with seconds' => ['times', ['16:45:00'], 'times.0', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'an hour past 23' => ['times', ['24:00'], 'times.0', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'a minute past 59' => ['times', ['16:60'], 'times.0', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'a time with a dot' => ['times', ['16.45'], 'times.0', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'a time that is a number' => ['times', [1645], 'times.0', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'a bad time after a good one' => ['times', ['16:45', 'kveld'], 'times.1', 'Klokkeslett skrives som TT:MM, for eksempel 16:45.'],
    'no times' => ['times', [], 'times', 'Oppgi minst ett klokkeslett.'],
    'times as null' => ['times', null, 'times', 'The times field must be an array.'],
    'times as one string' => ['times', '16:45', 'times', 'The times field must be an array.'],
    'times with keys' => ['times', ['evening' => '16:45'], 'times', 'The times field must be a list.'],
    'more than 24 times' => ['times', array_map(fn (int $minute): string => sprintf('10:%02d', $minute), range(0, 24)), 'times', 'Oppgi høyst 24 klokkeslett.'],
    'weekday zero' => ['weekdays', [0], 'weekdays.0', 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.'],
    'weekday eight' => ['weekdays', [8], 'weekdays.0', 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.'],
    'a weekday by name' => ['weekdays', ['mandag'], 'weekdays.0', 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.'],
    'a weekday with decimals' => ['weekdays', [1.5], 'weekdays.0', 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.'],
    'a weekday that is true' => ['weekdays', [true], 'weekdays.0', 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.'],
    'a bad weekday after a good one' => ['weekdays', [1, 9], 'weekdays.1', 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.'],
    'no weekdays' => ['weekdays', [], 'weekdays', 'Velg minst én ukedag.'],
    'weekdays as one number' => ['weekdays', 3, 'weekdays', 'The weekdays field must be an array.'],
    'more than seven weekdays' => ['weekdays', [1, 2, 3, 4, 5, 6, 7, 1], 'weekdays', 'The weekdays field must not have more than 7 items.'],
]);

test('the investments page shows the price schedule', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->assertSet('times', ['16:45'])
        ->assertSet('weekdays', ['1', '2', '3', '4', '5'])
        ->assertSee('Automatisk kursoppdatering')
        ->assertSee('Neste: onsdag 25.11.2026 kl. 16:45.')
        ->assertSee('Planen har ikke kjørt ennå.');

    expect(HoldingPriceSchedule::query()->sole()->user_id)->toBe($user->id);
});

test('the investments page shows when the schedule last ran', function () {
    $user = User::factory()->create();
    HoldingPriceSchedule::for($user);

    Http::fake();

    Carbon::setTestNow(osloTime('2026-11-27 16:45:00'));
    $this->artisan('holdings:update-prices --due')->assertSuccessful();

    Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->assertSee('Neste: mandag 30.11.2026 kl. 16:45.')
        ->assertSee('Forrige: fredag 27.11.2026 kl. 16:45.')
        ->assertDontSee('Planen har ikke kjørt ennå.');
});

test('the investments page changes the times and the weekdays', function () {
    $user = User::factory()->create();

    $page = Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->call('addTime')
        ->assertSet('times', ['16:45', ''])
        ->set('times.1', '09:30')
        ->set('weekdays', ['6', '1', '3'])
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSet('times', ['09:30', '16:45'])
        ->assertSet('weekdays', ['1', '3', '6'])
        ->assertSee('Neste: onsdag 25.11.2026 kl. 16:45.');

    $schedule = HoldingPriceSchedule::query()->where('user_id', $user->id)->sole();

    expect($schedule->times)->toBe(['09:30', '16:45'])
        ->and($schedule->weekdays)->toBe([1, 3, 6]);

    $page->call('removeTime', 1)
        ->assertSet('times', ['09:30'])
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSee('Neste: lørdag 28.11.2026 kl. 09:30.');

    expect($schedule->fresh()->times)->toBe(['09:30']);
});

test('the investments page drops a time row that was left empty', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->call('addTime')
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSet('times', ['16:45']);

    expect(HoldingPriceSchedule::for($user)->times)->toBe(['16:45']);
});

test('the investments page rejects a schedule without times or weekdays, or with a malformed time', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->call('removeTime', 0)
        ->set('weekdays', [])
        ->call('saveSchedule')
        ->assertHasErrors(['times', 'weekdays'])
        ->assertSee('Oppgi minst ett klokkeslett.')
        ->assertSee('Velg minst én ukedag.')
        ->set('weekdays', ['1'])
        ->set('times', ['25:00'])
        ->call('saveSchedule')
        ->assertHasErrors(['times.0'])
        ->assertHasNoErrors(['weekdays'])
        ->assertSee('Klokkeslett skrives som TT:MM, for eksempel 16:45.');

    $schedule = HoldingPriceSchedule::for($user);

    expect($schedule->times)->toBe(['16:45'])
        ->and($schedule->weekdays)->toBe([1, 2, 3, 4, 5]);
});

test('saving a holding does not touch the price schedule, and the other way around', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->set('name', 'Eksempel ASA')
        ->set('value', '1500.00')
        ->set('ticker', 'EKSEMPEL.OL')
        ->set('times', ['08:15'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('times', ['08:15'])
        ->set('name', '')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    expect($user->holdings()->sole()->ticker)->toBe('EKSEMPEL.OL')
        ->and(HoldingPriceSchedule::for($user)->times)->toBe(['08:15']);
});

/**
 * A moment given as Oslo time, in the app's timezone.
 */
function osloTime(string $time): Carbon
{
    return Carbon::parse($time, 'Europe/Oslo')->setTimezone(config('app.timezone'));
}

/**
 * A stored time, written as Oslo time whatever the app's timezone is.
 */
function inOslo(?CarbonInterface $time): ?string
{
    return $time?->toImmutable()->setTimezone('Europe/Oslo')->toIso8601String();
}
