<?php

namespace App\Http\Controllers\Api;

use App\Actions\ClassifyFoodPurchase;
use App\Actions\SplitTransactionLines;
use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Enums\Subcategory;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Support\AccountNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'booking_status' => ['nullable', Rule::enum(BookingStatus::class)],
        ]);

        $user = $this->user($request);
        $query = $user->transactions()->with('parent')->orderByDesc('booked_on')->orderByDesc('id');
        $bookingStatus = $validated['booking_status'] ?? null;

        // Only bank rows have a booking status, and a split bank row is the
        // parent, so this filter lists parents instead of their lines.
        if ($request->boolean('inbox')) {
            $query->inbox();
        } elseif (empty($bookingStatus)) {
            $query->excludingSplitParents();
        }

        if (! empty($bookingStatus)) {
            $query->where('booking_status', $bookingStatus);
        }

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

        $numbers = new AccountNumbers($user);

        return response()->json(
            $query->get()->map(fn (Transaction $transaction): array => $this->payload($transaction, $numbers)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $validated = $request->validate([
            'booked_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payee' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'subcategory' => ['nullable', Rule::enum(Subcategory::class)],
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
        $validated['subcategory'] = filled($validated['subcategory'] ?? null)
            ? Subcategory::from($validated['subcategory'])
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

        $category = $validated['category'];
        $subcategory = $validated['subcategory'];
        $this->ensureSubcategory(
            $category instanceof Category ? $category : null,
            $subcategory instanceof Subcategory ? $subcategory : null,
        );

        $user = $this->user($request);
        $transaction = $user->transactions()->create($validated);

        return response()->json($this->payload($transaction, new AccountNumbers($user)), 201);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', Rule::enum(Category::class)],
            'subcategory' => ['sometimes', 'nullable', Rule::enum(Subcategory::class)],
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

        if (array_key_exists('subcategory', $validated)) {
            $validated['subcategory'] = filled($validated['subcategory'])
                ? Subcategory::from($validated['subcategory'])
                : null;
        }

        if (array_key_exists('payment_method', $validated)) {
            $validated['payment_method'] = filled($validated['payment_method'])
                ? PaymentMethod::from($validated['payment_method'])
                : null;
        }

        if (array_key_exists('category', $validated) || array_key_exists('subcategory', $validated)) {
            $category = array_key_exists('category', $validated) ? $validated['category'] : $transaction->category;
            $subcategory = array_key_exists('subcategory', $validated) ? $validated['subcategory'] : $transaction->subcategory;
            $this->ensureSubcategory(
                $category instanceof Category ? $category : null,
                $subcategory instanceof Subcategory ? $subcategory : null,
            );
        }

        $transaction->update($validated);

        return response()->json($this->payload($transaction->refresh(), new AccountNumbers($this->user($request))));
    }

    /**
     * Deleting a bank row also deletes its split lines. A row the bank still
     * reports as booked inside the sync window is imported again as new.
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        $this->authorize('delete', $transaction);

        if ($transaction->parent_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'En delingslinje kan ikke slettes alene. Slett den opprinnelige transaksjonen, eller fjern delingen.',
            ]);
        }

        DB::transaction(function () use ($transaction): void {
            $transaction->children()->delete();
            $transaction->delete();
        });

        return response()->json(status: 204);
    }

    public function split(Request $request, Transaction $transaction, SplitTransactionLines $lines): JsonResponse
    {
        $this->authorize('update', $transaction);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.category' => ['required', Rule::enum(Category::class)],
            'lines.*.subcategory' => ['nullable', Rule::enum(Subcategory::class)],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.note' => ['nullable', 'string'],
        ]);

        $parent = $lines->replace($transaction, $this->splitLines($validated['lines']));

        return response()->json($this->payload($parent, new AccountNumbers($this->user($request))));
    }

    public function destroySplit(Request $request, Transaction $transaction, SplitTransactionLines $lines): JsonResponse
    {
        $this->authorize('update', $transaction);

        return response()->json($this->payload($lines->clear($transaction), new AccountNumbers($this->user($request))));
    }

    /**
     * The stored fields, and account_display_number: the number of the
     * account the row belongs to, when the bank sync knows it. Split lines
     * are included when they are loaded.
     *
     * @return array<string, mixed>
     */
    private function payload(Transaction $transaction, AccountNumbers $numbers): array
    {
        $payload = $transaction->attributesToArray();
        $payload['account_display_number'] = $numbers->forTransaction($transaction);

        if ($transaction->relationLoaded('children')) {
            $parent = $transaction->withoutRelations();

            $payload['children'] = $transaction->children
                ->map(fn (Transaction $line): array => $this->payload($line->setRelation('parent', $parent), $numbers))
                ->all();
        }

        return $payload;
    }

    private function ensureSubcategory(?Category $category, ?Subcategory $subcategory): void
    {
        if (! $subcategory instanceof Subcategory) {
            return;
        }

        if (! $category instanceof Category || $subcategory->category() !== $category) {
            throw ValidationException::withMessages([
                'subcategory' => 'Underkategorien hører ikke til kategorien.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{category: Category, subcategory: Subcategory|null, amount: string, note: string|null}>
     */
    private function splitLines(array $lines): array
    {
        $parsed = [];

        foreach ($lines as $line) {
            $category = Category::from((string) $line['category']);
            $subcategory = filled($line['subcategory'] ?? null)
                ? Subcategory::from((string) $line['subcategory'])
                : null;
            $amount = $line['amount'];
            $note = $line['note'] ?? null;

            $parsed[] = [
                'category' => $category,
                'subcategory' => $subcategory,
                'amount' => is_string($amount) ? $amount : (string) $amount,
                'note' => is_string($note) && $note !== '' ? $note : null,
            ];
        }

        return $parsed;
    }
}
