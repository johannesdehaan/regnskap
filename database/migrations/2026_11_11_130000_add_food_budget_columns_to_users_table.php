<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('food_budget_amount', 12, 2)->default(1400);
            $table->unsignedSmallInteger('food_budget_window_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['food_budget_amount', 'food_budget_window_days']);
        });
    }
};
