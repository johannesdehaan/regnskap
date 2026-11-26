<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\HoldingPriceScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When the prices of a user's holdings are fetched: at each of the times, on
 * each of the weekdays. The times are HH:MM in Europe/Oslo, and the weekdays
 * are numbered from 1 for Monday to 7 for Sunday.
 *
 * handled_until is the latest scheduled time the update has started for. It is
 * also moved to the present when the schedule is created or changed, so a time
 * that passed before the change does not run.
 *
 * @property int $id
 * @property int $user_id
 * @property list<string> $times
 * @property list<int> $weekdays
 * @property CarbonImmutable $handled_until
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'times', 'weekdays', 'handled_until', 'last_run_at'])]
class HoldingPriceSchedule extends Model
{
    /** @use HasFactory<HoldingPriceScheduleFactory> */
    use HasFactory;

    public const TIMEZONE = 'Europe/Oslo';

    /**
     * Oslo Børs has closed for the day by 16:45.
     */
    public const DEFAULT_TIMES = ['16:45'];

    public const DEFAULT_WEEKDAYS = [1, 2, 3, 4, 5];

    public const TIME_PATTERN = '/\A([01][0-9]|2[0-3]):[0-5][0-9]\z/';

    public const MAX_TIMES = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times' => 'array',
            'weekdays' => 'array',
            'handled_until' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * The user's schedule. A user who has none gets the default one, from now on.
     */
    public static function for(User $user): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'times' => self::DEFAULT_TIMES,
                'weekdays' => self::DEFAULT_WEEKDAYS,
                'handled_until' => now()->startOfSecond(),
            ],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $presence = 'required'): array
    {
        return [
            'times' => [$presence, 'array', 'list', 'min:1', 'max:'.self::MAX_TIMES],
            'times.*' => ['string', 'regex:'.self::TIME_PATTERN],
            'weekdays' => [$presence, 'array', 'list', 'min:1', 'max:7'],
            // The integer rule alone lets true through as 1.
            'weekdays.*' => ['numeric', 'integer', 'between:1,7'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $time = 'Klokkeslett skrives som TT:MM, for eksempel 16:45.';
        $weekday = 'Ukedager er hele tall fra 1 for mandag til 7 for søndag.';

        return [
            'times.required' => 'Oppgi minst ett klokkeslett.',
            'times.min' => 'Oppgi minst ett klokkeslett.',
            'times.max' => 'Oppgi høyst '.self::MAX_TIMES.' klokkeslett.',
            'times.*.string' => $time,
            'times.*.regex' => $time,
            'weekdays.required' => 'Velg minst én ukedag.',
            'weekdays.min' => 'Velg minst én ukedag.',
            'weekdays.*.numeric' => $weekday,
            'weekdays.*.integer' => $weekday,
            'weekdays.*.between' => $weekday,
        ];
    }

    /**
     * Store new times and weekdays, sorted and without duplicates. When they
     * differ from the stored ones, the schedule counts from now on.
     *
     * @param  array<mixed>  $times
     * @param  array<mixed>  $weekdays
     */
    public function reschedule(array $times, array $weekdays): void
    {
        $times = array_values(array_unique(array_map(strval(...), $times)));
        $weekdays = array_values(array_unique(array_map(intval(...), $weekdays)));

        sort($times);
        sort($weekdays);

        $this->times = $times;
        $this->weekdays = $weekdays;

        if ($this->isDirty(['times', 'weekdays'])) {
            $this->handled_until = now()->startOfSecond();
        }

        $this->save();
    }

    /**
     * The latest scheduled time that has passed and that the update has not
     * started for. Earlier times that were missed are not run one by one.
     */
    public function dueTime(CarbonInterface $now): ?CarbonImmutable
    {
        $today = $this->dayOf($now);

        for ($daysBack = 0; $daysBack <= 7; $daysBack++) {
            foreach (array_reverse($this->timesOn($today->subDays($daysBack))) as $time) {
                if ($time->lessThanOrEqualTo($now)) {
                    return $time->greaterThan($this->handled_until) ? $time : null;
                }
            }
        }

        return null;
    }

    /**
     * The first scheduled time after now.
     */
    public function nextTime(CarbonInterface $now): ?CarbonImmutable
    {
        $today = $this->dayOf($now);

        for ($daysAhead = 0; $daysAhead <= 7; $daysAhead++) {
            foreach ($this->timesOn($today->addDays($daysAhead)) as $time) {
                if ($time->greaterThan($now)) {
                    return $time;
                }
            }
        }

        return null;
    }

    /**
     * Mark a scheduled time as handled. Of several callers with the same time
     * only one gets true, so the update starts once for it.
     */
    public function claim(CarbonInterface $time): bool
    {
        // Eloquent stores the wall time as written, so the time has to be in the app's timezone first.
        $handledUntil = $time->toImmutable()->setTimezone(config('app.timezone'));

        $claimed = self::query()
            ->whereKey($this->id)
            ->where('handled_until', '<', $handledUntil)
            ->update(['handled_until' => $handledUntil, 'last_run_at' => now()->startOfSecond()]);

        if ($claimed === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private function dayOf(CarbonInterface $now): CarbonImmutable
    {
        return $now->toImmutable()->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * The scheduled times on one day, earliest first. None when the day is not one of the weekdays.
     *
     * @return list<CarbonImmutable>
     */
    private function timesOn(CarbonImmutable $day): array
    {
        if (! in_array($day->dayOfWeekIso, $this->weekdays, true)) {
            return [];
        }

        $times = $this->times;

        sort($times);

        // Parsing the date and the time together gives one moment also in the
        // hour that comes twice when the clocks go back.
        return array_map(
            fn (string $time): CarbonImmutable => CarbonImmutable::parse($day->format('Y-m-d').' '.$time, self::TIMEZONE),
            $times,
        );
    }
}
