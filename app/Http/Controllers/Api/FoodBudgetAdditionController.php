<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FoodBudgetAddition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FoodBudgetAdditionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FoodBudgetAddition::class);

        $additions = $this->user($request)->foodBudgetAdditions()
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return response()->json($additions);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FoodBudgetAddition::class);

        $addition = $this->user($request)->foodBudgetAdditions()->create($this->payload($request));

        return response()->json($addition, 201);
    }

    public function update(Request $request, FoodBudgetAddition $foodBudgetAddition): JsonResponse
    {
        $this->authorize('update', $foodBudgetAddition);

        $foodBudgetAddition->update($this->payload($request, $foodBudgetAddition));

        return response()->json($foodBudgetAddition->refresh());
    }

    public function destroy(FoodBudgetAddition $foodBudgetAddition): JsonResponse
    {
        $this->authorize('delete', $foodBudgetAddition);

        $foodBudgetAddition->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, ?FoodBudgetAddition $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $validated = $request->validate([
            'label' => [$required, 'string', 'max:100'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d'],
            'amount_per_day' => [$required, 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $startsOn = $validated['starts_on'] ?? $existing?->starts_on->toDateString();
        $endsOn = $validated['ends_on'] ?? $existing?->ends_on->toDateString();

        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages([
                'ends_on' => 'Sluttdatoen kan ikke være før startdatoen.',
            ]);
        }

        if (array_key_exists('amount_per_day', $validated)) {
            $validated['amount_per_day'] = number_format((float) $validated['amount_per_day'], 2, '.', '');
        }

        return $validated;
    }
}
