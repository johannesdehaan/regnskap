<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')->where('category', 'O')->update(['category' => null]);
        DB::table('food_rules')->where('category', 'O')->update(['category' => null]);
        DB::table('category_rules')->where('category', 'O')->delete();
    }

    public function down(): void
    {
        // Placeholder category is not restored.
    }
};
