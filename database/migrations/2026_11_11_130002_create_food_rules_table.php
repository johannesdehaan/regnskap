<?php

use App\Actions\SeedFoodRules;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');
            $table->string('match_type');
            $table->string('effect');
            $table->string('category')->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->timestamps();

            $table->unique(['user_id', 'pattern', 'match_type']);
            $table->index(['user_id', 'priority']);
        });

        $seed = app(SeedFoodRules::class);

        User::query()->each(function (User $user) use ($seed): void {
            $seed($user);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_rules');
    }
};
