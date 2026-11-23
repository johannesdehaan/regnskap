<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\AccountNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $user = $this->user($request);
        $numbers = new AccountNumbers($user);

        return response()->json(
            $user->accounts()->orderBy('name')->get()
                ->map(fn (Account $account): array => $this->withNumber($account, $numbers)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $user = $this->user($request);
        $account = $user->accounts()->create($this->payload($request));

        return response()->json($this->withNumber($account, new AccountNumbers($user)), 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $account->update($this->payload($request, updating: true));

        return response()->json($this->withNumber($account->refresh(), new AccountNumbers($this->user($request))));
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $account->delete();

        return response()->json(status: 204);
    }

    /**
     * The stored fields, and account_display_number: the number of the synced
     * bank account whose payment method has the same name as this account.
     *
     * @return array<string, mixed>
     */
    private function withNumber(Account $account, AccountNumbers $numbers): array
    {
        return [
            ...$account->attributesToArray(),
            'account_display_number' => $numbers->forName($account->name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, 'string', 'max:255'],
        ]);
    }
}
