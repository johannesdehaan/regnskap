<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holding;
use App\Models\HoldingPriceSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingPriceScheduleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holding::class);

        return $this->respond(HoldingPriceSchedule::for($this->user($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holding::class);

        $validated = $request->validate(
            HoldingPriceSchedule::rules('sometimes'),
            HoldingPriceSchedule::messages(),
        );

        $schedule = HoldingPriceSchedule::for($this->user($request));

        $schedule->reschedule(
            $validated['times'] ?? $schedule->times,
            $validated['weekdays'] ?? $schedule->weekdays,
        );

        return $this->respond($schedule);
    }

    private function respond(HoldingPriceSchedule $schedule): JsonResponse
    {
        return response()->json([
            'times' => $schedule->times,
            'weekdays' => $schedule->weekdays,
            'timezone' => HoldingPriceSchedule::TIMEZONE,
            'next_run_at' => $schedule->nextTime(now()),
            'last_run_at' => $schedule->last_run_at,
        ]);
    }
}
