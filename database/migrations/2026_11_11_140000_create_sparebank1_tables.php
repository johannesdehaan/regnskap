<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('fin_inst')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('token_type')->default('Bearer');
            $table->string('scope')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('status');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_key');
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->string('account_type')->nullable();
            $table->string('currency_code', 3)->default('NOK');
            $table->string('payment_method')->nullable();
            $table->boolean('sync_enabled')->default(false);
            $table->timestamps();

            $table->unique(['bank_connection_id', 'external_key']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('external_id', 512)->nullable()->after('user_id');
            $table->foreignId('bank_account_id')->nullable()->after('external_id')->constrained('bank_accounts')->nullOnDelete();
            $table->string('booking_status')->nullable()->after('bank_account_id');
            $table->char('dedupe_key', 64)->nullable()->after('booking_status');

            $table->unique(['user_id', 'external_id']);
            $table->unique(['user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'external_id']);
            $table->dropUnique(['user_id', 'dedupe_key']);
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn(['external_id', 'booking_status', 'dedupe_key']);
        });

        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('bank_connections');
    }
};
