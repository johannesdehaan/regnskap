<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PendingTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $rows = $this->user($request)->pendingBankTransactions()
            ->orderByDesc('booked_on')
            ->orderByDesc('id')
            ->get();

        return response()->json($rows);
    }
}
