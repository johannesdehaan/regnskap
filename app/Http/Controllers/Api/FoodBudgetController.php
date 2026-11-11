<?php

namespace App\Http\Controllers\Api;

use App\Actions\BuildFoodBudget;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodBudgetController extends Controller
{
    public function show(Request $request, BuildFoodBudget $budget): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        return response()->json($budget($this->user($request)));
    }

    public function update(Request $request, BuildFoodBudget $budget): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:62'],
        ]);

        $user = $this->user($request);

        if (array_key_exists('amount', $validated)) {
            $user->food_budget_amount = number_format((float) $validated['amount'], 2, '.', '');
        }

        if (array_key_exists('window_days', $validated)) {
            $user->food_budget_window_days = (int) $validated['window_days'];
        }

        $user->save();

        return response()->json($budget($user->refresh()));
    }
}
