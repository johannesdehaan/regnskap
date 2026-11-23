<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two accounts were both called "Brukskonto": the one at Hegra Sparebank,
 * which is entered by hand, and the one at SpareBank 1 SMN, which the bank
 * sync fills. They become "Brukskonto Hegra" and "Brukskonto SMN".
 *
 * A row belongs to the SMN account when it came from a synced bank account
 * that is mapped to Brukskonto, or is a split line of such a row. Everything
 * else that says Brukskonto is the Hegra account. The mapping in
 * bank_accounts decides, so no account number is written here.
 *
 * Running up() again changes nothing.
 */
return new class extends Migration
{
    private const SHARED = 'Brukskonto';

    private const HEGRA = 'Brukskonto Hegra';

    private const SMN = 'Brukskonto SMN';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->moveSyncedRowsToSmn();
            $this->renameTheRestToHegra();
            $this->listSmnNextToHegra();
        });
    }

    /**
     * Both names go back to "Brukskonto". The bank account rows keep their
     * link, so up() sorts synced rows into SMN again. A row that was typed in
     * as Brukskonto SMN has no such link and would come back as Hegra.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (['transactions', 'bank_accounts', 'recurring_costs'] as $table) {
                DB::table($table)
                    ->whereIn('payment_method', [self::HEGRA, self::SMN])
                    ->update(['payment_method' => self::SHARED]);
            }

            // An SMN row next to a Hegra row is the one up() added. One that
            // stands alone was typed in, and is kept under the shared name.
            $listsHegra = DB::table('accounts')->where('name', self::HEGRA)->distinct()->pluck('user_id');

            DB::table('accounts')->where('name', self::SMN)->whereIn('user_id', $listsHegra)->delete();
            DB::table('accounts')->whereIn('name', [self::HEGRA, self::SMN])->update(['name' => self::SHARED]);
        });
    }

    private function moveSyncedRowsToSmn(): void
    {
        // An account that already has the new name counts too, so rows it
        // still has under the old name are sorted the same way.
        $accountIds = DB::table('bank_accounts')
            ->whereIn('payment_method', [self::SHARED, self::SMN])
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return;
        }

        DB::table('transactions')
            ->whereIn('bank_account_id', $accountIds)
            ->where('payment_method', self::SHARED)
            ->update(['payment_method' => self::SMN]);

        // Split lines have no bank account of their own; the bank row they
        // were split from has it.
        $lineIds = DB::table('transactions as line')
            ->join('transactions as bank_row', 'bank_row.id', '=', 'line.parent_id')
            ->whereIn('bank_row.bank_account_id', $accountIds)
            ->where('line.payment_method', self::SHARED)
            ->pluck('line.id');

        foreach ($lineIds->chunk(500) as $chunk) {
            DB::table('transactions')
                ->whereIn('id', $chunk)
                ->update(['payment_method' => self::SMN]);
        }

        DB::table('bank_accounts')
            ->whereIn('id', $accountIds)
            ->where('payment_method', self::SHARED)
            ->update(['payment_method' => self::SMN]);
    }

    private function renameTheRestToHegra(): void
    {
        foreach (['transactions', 'recurring_costs'] as $table) {
            DB::table($table)
                ->where('payment_method', self::SHARED)
                ->update(['payment_method' => self::HEGRA]);
        }

        DB::table('accounts')
            ->where('name', self::SHARED)
            ->update(['name' => self::HEGRA]);
    }

    /**
     * Nothing requires a row in accounts for each payment method; the list is
     * kept by hand. A user who lists the Hegra account there and has a synced
     * SMN account gets the SMN account listed too, with the same type.
     */
    private function listSmnNextToHegra(): void
    {
        $userIds = DB::table('bank_accounts')
            ->where('payment_method', self::SMN)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $type = DB::table('accounts')
                ->where('user_id', $userId)
                ->where('name', self::HEGRA)
                ->value('type');

            $listed = DB::table('accounts')
                ->where('user_id', $userId)
                ->where('name', self::SMN)
                ->exists();

            if (! is_string($type) || $listed) {
                continue;
            }

            DB::table('accounts')->insert([
                'user_id' => $userId,
                'name' => self::SMN,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
