<?php

namespace App\Http\Controllers\Api;

use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use App\Http\Controllers\Controller;
use App\Models\FoodRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FoodRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FoodRule::class);

        $rules = $this->user($request)->foodRules()
            ->orderByDesc('priority')
            ->orderBy('pattern')
            ->get();

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FoodRule::class);

        $rule = $this->user($request)->foodRules()->create($this->payload($request));

        return response()->json($rule, 201);
    }

    public function update(Request $request, FoodRule $foodRule): JsonResponse
    {
        $this->authorize('update', $foodRule);

        $foodRule->update($this->payload($request, updating: true));

        return response()->json($foodRule->refresh());
    }

    public function destroy(FoodRule $foodRule): JsonResponse
    {
        $this->authorize('delete', $foodRule);

        $foodRule->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $validated = $request->validate([
            'pattern' => [$required, 'string', 'max:255'],
            'match_type' => ['sometimes', Rule::enum(FoodRuleMatch::class)],
            'effect' => [$required, Rule::enum(FoodRuleEffect::class)],
            'category' => ['sometimes', 'nullable', Rule::enum(Category::class)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        if (array_key_exists('match_type', $validated)) {
            $validated['match_type'] = FoodRuleMatch::from($validated['match_type']);
        } elseif (! $updating) {
            $validated['match_type'] = FoodRuleMatch::Contains;
        }

        if (array_key_exists('effect', $validated)) {
            $validated['effect'] = FoodRuleEffect::from($validated['effect']);
        }

        if (array_key_exists('category', $validated)) {
            $validated['category'] = filled($validated['category'])
                ? Category::from($validated['category'])
                : null;
        }

        if (! $updating && ! array_key_exists('priority', $validated)) {
            $validated['priority'] = 110;
        }

        return $validated;
    }
}
