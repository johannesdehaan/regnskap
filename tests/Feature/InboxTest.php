<?php

use App\Enums\Category;
use App\Enums\Confidence;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests are redirected from the inbox', function () {
    $this->get(route('inbox'))
        ->assertRedirect(route('login'));
});

test('users only see their own transactions in the inbox', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'payee' => 'Min butikk',
        'category' => null,
        'booked_on' => '2026-01-10',
    ]);
    Transaction::factory()->for($other)->create([
        'payee' => 'Hemmelig sted',
        'category' => null,
        'booked_on' => '2026-01-10',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertSee('Min butikk')
        ->assertDontSee('Hemmelig sted');
});

test('users cannot categorize another users transaction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = Transaction::factory()->for($other)->create([
        'category' => null,
        'payee' => 'Fremmed',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->set('transactionId', $foreign->id)
        ->call('assign', Category::Annet->value)
        ->assertForbidden();

    expect($foreign->fresh()->category)->toBeNull();
});

test('a user can categorize a transaction', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create([
        'payee' => 'Rema 1000 Test',
        'category' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->call('assign', Category::Dagligvarer->value)
        ->assertHasNoErrors();

    expect($transaction->fresh()->category)->toBe(Category::Dagligvarer);
    expect($user->transactions()->inbox()->count())->toBe(0);
});

test('a split creates two children whose amounts sum to the original', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create([
        'amount' => '100.00',
        'payee' => 'Kiwi Test',
        'category' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->set('splitAmount', '40.00')
        ->set('splitCategoryA', Category::Dagligvarer->value)
        ->set('splitCategoryB', Category::BollerOgBrus->value)
        ->call('split')
        ->assertHasNoErrors();

    $children = $transaction->fresh()->children;

    expect($children)->toHaveCount(2);
    expect(number_format((float) $children->sum('amount'), 2, '.', ''))->toBe('100.00');
    expect($children->pluck('category')->all())->toEqual([
        Category::Dagligvarer,
        Category::BollerOgBrus,
    ]);
    expect($user->transactions()->inbox()->count())->toBe(0);
});

test('csv import creates uncategorized transactions and skips duplicates', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $this->actingAs($user);

    $csv = implode("\n", [
        'Dato,Beløp,Tekst',
        '2026-01-15,199.00,Vinmonopolet Test',
        '2026-01-15,199.00,Vinmonopolet Test',
        '2026-01-16,50.00,AtB Billett',
        '',
    ]);

    Livewire::test('pages::transactions.import')
        ->set('upload', UploadedFile::fake()->createWithContent('import.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    expect($user->transactions()->count())->toBe(2);
    expect($user->transactions()->whereNull('category')->count())->toBe(2);
    expect($user->transactions()->pluck('payee')->sort()->values()->all())->toBe([
        'AtB Billett',
        'Vinmonopolet Test',
    ]);
});

test('inbox category buttons render labels instead of blade directives', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'payee' => 'Rema 1000 Test',
        'category' => null,
        'needs_review' => true,
    ]);

    CategoryRule::factory()->create([
        'payee' => 'Rema',
        'category' => Category::Dagligvarer,
        'confidence' => Confidence::Opplagt,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('inbox'));

    $response->assertOk();

    $content = $response->getContent();

    expect($content)->not->toContain('@if')
        ->and($content)->not->toContain('@endif')
        ->and(substr_count($content, 'data-suggested="1"'))->toBe(1);

    $response->assertSee('Forslag');

    foreach (Category::cases() as $category) {
        $response->assertSee($category->value, false);
        expect(
            str_contains($content, "assign('{$category->value}')")
            || str_contains($content, "assign(&#039;{$category->value}&#039;)")
        )->toBeTrue();
    }
});

test('a suggestion is shown for opplagt rules but not for uklart', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->create([
        'payee' => 'AtB Billett',
        'category' => null,
        'booked_on' => '2026-02-01',
    ]);

    CategoryRule::factory()->create([
        'payee' => 'AtB',
        'category' => Category::OffentligTransport,
        'confidence' => Confidence::Opplagt,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertSee('Forslag')
        ->assertSee(Category::OffentligTransport->value);

    CategoryRule::query()->delete();

    CategoryRule::factory()->create([
        'payee' => 'AtB',
        'category' => Category::OffentligTransport,
        'confidence' => Confidence::Uklart,
    ]);

    Livewire::test('pages::inbox')
        ->assertDontSee('Forslag');
});
