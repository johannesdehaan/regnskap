<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->string('external_id', 512)->nullable();
            $table->date('booked_on');
            $table->decimal('amount', 12, 2);
            $table->string('payee');
            $table->text('note')->nullable();
            $table->string('type_text')->nullable();
            $table->string('remote_account_number')->nullable();
            $table->string('suggested_category')->nullable();
            $table->boolean('counts_as_food')->default(false);
            $table->timestamp('last_seen_at');

            $table->index(['user_id', 'booked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_bank_transactions');
    }
};
