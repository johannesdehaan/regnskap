<?php

namespace App\Http\Controllers\Api;

use App\Actions\ClassifyFoodPurchase;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $validated = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'category' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
            'inbox' => ['nullable'],
        ]);

        $query = $this->user($request)->transactions()->orderByDesc('booked_on')->orderByDesc('id');

        if (! empty($validated['month'])) {
            $start = $validated['month'].'-01';
            $end = Carbon::parse($start)->endOfMonth()->toDateString();
            $query->whereDate('booked_on', '>=', $start)->whereDate('booked_on', '<=', $end);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (! empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        if (! empty($validated['q'])) {
            $term = '%'.trim($validated['q']).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('payee', 'like', $term)->orWhere('note', 'like', $term);
            });
        }

        if ($request->boolean('inbox')) {
            $query->inbox();
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $validated = $request->validate([
            'booked_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payee' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string'],
            'needs_review' => ['sometimes', 'boolean'],
            'exclude_from_budget' => ['sometimes', 'boolean'],
        ]);

        $validated['amount'] = number_format(abs((float) $validated['amount']), 2, '.', '');
        $explicitCategory = filled($validated['category'] ?? null);
        $validated['category'] = $explicitCategory
            ? Category::from($validated['category'])
            : null;
        $validated['payment_method'] = filled($validated['payment_method'] ?? null)
            ? PaymentMethod::from($validated['payment_method'])
            : null;

        if (! $explicitCategory || ! array_key_exists('needs_review', $validated)) {
            $classification = app(ClassifyFoodPurchase::class)(
                $this->user($request),
                $validated['payee'],
                $validated['note'] ?? null,
            );

            if (! $explicitCategory) {
                $validated['category'] = $classification->category;
            }

            if (! array_key_exists('needs_review', $validated)) {
                $validated['needs_review'] = $explicitCategory ? false : $classification->needsReview;
            }
        }

        $transaction = $this->user($request)->transactions()->create($validated);

        return response()->json($transaction, 201);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', Rule::enum(Category::class)],
            'payment_method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
            'note' => ['sometimes', 'nullable', 'string'],
            'needs_review' => ['sometimes', 'boolean'],
            'exclude_from_budget' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('category', $validated)) {
            $validated['category'] = filled($validated['category'])
                ? Category::from($validated['category'])
                : null;
        }

        if (array_key_exists('payment_method', $validated)) {
            $validated['payment_method'] = filled($validated['payment_method'])
                ? PaymentMethod::from($validated['payment_method'])
                : null;
        }

        $transaction->update($validated);

        return response()->json($transaction->refresh());
    }
}
