<?php

namespace App\Http\Controllers\Api;

use App\Actions\SyncSparebank1Transactions;
use App\Enums\BankConnectionStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\Sparebank1Exception;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Services\Sparebank1\Sparebank1Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Sparebank1Controller extends Controller
{
    public function show(Request $request, Sparebank1Client $client): JsonResponse
    {
        $connection = $this->user($request)->bankConnection;
        $status = $connection instanceof BankConnection
            ? $connection->status
            : BankConnectionStatus::Disconnected;

        return response()->json([
            'connected' => $status === BankConnectionStatus::Connected,
            'status' => $status->value,
            'status_label' => $status->label(),
            'last_synced_at' => $this->syncedAt($connection),
            'last_sync_error' => $connection?->last_sync_error,
            'credentials_ready' => $client->configured(),
            'accounts' => $this->accounts($connection),
        ]);
    }

    public function sync(Request $request, SyncSparebank1Transactions $sync): JsonResponse
    {
        $user = $this->user($request);

        try {
            $counts = $sync($user);
        } catch (Sparebank1Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $connection = $user->bankConnection()->first();

        return response()->json([
            'imported' => $counts['imported'],
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
            'last_synced_at' => $this->syncedAt($connection),
        ]);
    }

    public function updateAccount(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorize('update', $bankAccount);

        $validated = $request->validate([
            'sync_enabled' => ['sometimes', 'boolean'],
            'payment_method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
        ]);

        if (! array_key_exists('sync_enabled', $validated) && ! array_key_exists('payment_method', $validated)) {
            throw ValidationException::withMessages([
                'sync_enabled' => 'Oppgi sync_enabled eller payment_method.',
            ]);
        }

        $updates = [];

        if (array_key_exists('sync_enabled', $validated)) {
            $updates['sync_enabled'] = $request->boolean('sync_enabled');
        }

        if (array_key_exists('payment_method', $validated)) {
            $method = $validated['payment_method'];
            $updates['payment_method'] = is_string($method) && $method !== ''
                ? PaymentMethod::from($method)
                : null;
        }

        $bankAccount->update($updates);

        return response()->json($this->accountPayload($bankAccount->refresh()));
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     account_number: string|null,
     *     display_number: string,
     *     account_type: string|null,
     *     sync_enabled: bool,
     *     payment_method: string|null
     * }>
     */
    private function accounts(?BankConnection $connection): array
    {
        if (! $connection instanceof BankConnection) {
            return [];
        }

        $rows = [];

        foreach ($connection->accounts()->orderBy('name')->get() as $account) {
            if (! $account instanceof BankAccount) {
                continue;
            }

            $rows[] = $this->accountPayload($account);
        }

        return $rows;
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     account_number: string|null,
     *     display_number: string,
     *     account_type: string|null,
     *     sync_enabled: bool,
     *     payment_method: string|null
     * }
     */
    private function accountPayload(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'account_number' => $account->account_number,
            'display_number' => $account->displayNumber(),
            'account_type' => $account->account_type,
            'sync_enabled' => $account->sync_enabled,
            'payment_method' => $account->payment_method?->value,
        ];
    }

    private function syncedAt(?BankConnection $connection): ?string
    {
        return $connection?->last_synced_at?->toISOString();
    }
}
