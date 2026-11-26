<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->string('ticker', 32)->nullable();
            $table->string('isin', 12)->nullable();
            $table->timestamp('price_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->dropColumn(['ticker', 'isin', 'price_updated_at']);
        });
    }
};
