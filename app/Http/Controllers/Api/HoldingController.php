<?php

namespace App\Http\Controllers\Api;

use App\Actions\RefreshHoldingPrices;
use App\Enums\HoldingType;
use App\Http\Controllers\Controller;
use App\Models\Holding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HoldingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holding::class);

        return response()->json(
            $this->user($request)->holdings()->orderBy('name')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Holding::class);

        $holding = $this->user($request)->holdings()->create($this->payload($request));

        return response()->json($holding->refresh(), 201);
    }

    public function update(Request $request, Holding $holding): JsonResponse
    {
        $this->authorize('update', $holding);

        $holding->update($this->payload($request, updating: true));

        return response()->json($holding->refresh());
    }

    public function destroy(Holding $holding): JsonResponse
    {
        $this->authorize('delete', $holding);

        $holding->delete();

        return response()->json(status: 204);
    }

    public function updatePrices(Request $request, RefreshHoldingPrices $refresh): JsonResponse
    {
        $this->authorize('viewAny', Holding::class);

        $result = $refresh(
            $this->user($request)->holdings()->whereNotNull('ticker')->orderBy('name')->get(),
        );

        $skipped = [];

        foreach ($result['skipped'] as $row) {
            $skipped[] = [
                'id' => $row['holding']->id,
                'name' => $row['holding']->name,
                'ticker' => $row['holding']->ticker,
                'message' => $row['message'],
            ];
        }

        return response()->json([
            'updated' => $result['updated'],
            'skipped' => $skipped,
        ]);
    }

    public function updatePrice(Holding $holding, RefreshHoldingPrices $refresh): JsonResponse
    {
        $this->authorize('update', $holding);

        if ($holding->ticker === null) {
            throw ValidationException::withMessages([
                'ticker' => 'Beholdningen har ingen ticker.',
            ]);
        }

        $result = $refresh([$holding]);

        if ($result['skipped'] !== []) {
            return response()->json([
                'message' => $result['skipped'][0]['message'],
            ], 422);
        }

        return response()->json($holding);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'value' => [$required, 'numeric', 'min:0'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'type' => [$updating ? 'sometimes' : 'required', Rule::enum(HoldingType::class)],
            'ticker' => ['sometimes', 'nullable', 'string', 'max:'.Holding::TICKER_MAX_LENGTH, 'regex:'.Holding::TICKER_PATTERN],
            'isin' => ['sometimes', 'nullable', 'string', 'regex:'.Holding::ISIN_PATTERN],
            'price_updated_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('value', $validated)) {
            $validated['value'] = number_format((float) $validated['value'], 2, '.', '');
        }

        if (array_key_exists('price', $validated)) {
            $validated['price'] = $validated['price'] === null || $validated['price'] === ''
                ? null
                : number_format((float) $validated['price'], 4, '.', '');
        }

        if (array_key_exists('quantity', $validated)) {
            $validated['quantity'] = $validated['quantity'] === null || $validated['quantity'] === ''
                ? null
                : number_format((float) $validated['quantity'], 6, '.', '');
        }

        if (array_key_exists('type', $validated)) {
            $validated['type'] = HoldingType::from($validated['type']);
        }

        foreach (['ticker', 'isin'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = Holding::code($validated[$field]);
            }
        }

        if (isset($validated['price_updated_at'])) {
            // Eloquent stores the wall time as written, so an offset in the input has to be applied first.
            $validated['price_updated_at'] = Carbon::parse($validated['price_updated_at'])
                ->setTimezone(config('app.timezone'));
        }

        return $validated;
    }
}
