<?php

use App\Actions\ClassifyFoodPurchase;
use App\Enums\PaymentMethod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('the two Brukskonto accounts have their own names', function () {
    expect(PaymentMethod::BrukskontoHegra->value)->toBe('Brukskonto Hegra')
        ->and(PaymentMethod::BrukskontoSmn->value)->toBe('Brukskonto SMN')
        ->and(PaymentMethod::tryFrom('Brukskonto'))->toBeNull()
        ->and(array_column(PaymentMethod::cases(), 'value'))->not->toContain('Brukskonto');
});

test('a hand-typed label finds the account, and a bare Brukskonto is the Hegra account', function () {
    expect(PaymentMethod::tryFromLabel('Brukskonto SMN'))->toBe(PaymentMethod::BrukskontoSmn)
        ->and(PaymentMethod::tryFromLabel(' brukskonto smn '))->toBe(PaymentMethod::BrukskontoSmn)
        ->and(PaymentMethod::tryFromLabel('brukskonto hegra'))->toBe(PaymentMethod::BrukskontoHegra)
        ->and(PaymentMethod::tryFromLabel('Brukskonto'))->toBe(PaymentMethod::BrukskontoHegra)
        ->and(PaymentMethod::tryFromLabel('BRUKSKONTO'))->toBe(PaymentMethod::BrukskontoHegra)
        ->and(PaymentMethod::tryFromLabel('Brukskonto Nordea'))->toBeNull();
});

test('the api takes the new names and turns down the shared one', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    foreach (['Brukskonto Hegra', 'Brukskonto SMN'] as $index => $method) {
        $this->withToken($token)
            ->postJson('/api/transactions', [
                'booked_on' => '2026-08-15',
                'amount' => '10.00',
                'payee' => 'Butikk '.$index,
                'category' => 'Annet',
                'payment_method' => $method,
            ])
            ->assertCreated()
            ->assertJsonPath('payment_method', $method);
    }

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-08-15',
            'amount' => '10.00',
            'payee' => 'Butikk',
            'category' => 'Annet',
            'payment_method' => 'Brukskonto',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_method']);

    $this->withToken($token)
        ->getJson('/api/transactions?payment_method='.urlencode('Brukskonto SMN'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.payee', 'Butikk 1');

    expect($user->transactions()->count())->toBe(2);
});

test('a ledger csv that says Brukskonto is booked on the Hegra account', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $this->actingAs($user);

    $csv = implode("\n", [
        'dato,belop,kategori,brukersted,betalingsmate,notat',
        '2026-03-02,129.00,Annet,Gammel linje,Brukskonto,',
        '2026-03-03,40.00,Annet,Ny linje,Brukskonto SMN,',
        '',
    ]);

    Livewire::test('pages::transactions.import')
        ->set('upload', UploadedFile::fake()->createWithContent('ledger.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    expect($user->transactions()->where('payee', 'Gammel linje')->first()->payment_method)->toBe(PaymentMethod::BrukskontoHegra)
        ->and($user->transactions()->where('payee', 'Ny linje')->first()->payment_method)->toBe(PaymentMethod::BrukskontoSmn);
});

test('a row the bank only calls Brukskonto is still a movement between own accounts', function () {
    $user = User::factory()->create();
    $classify = app(ClassifyFoodPurchase::class);

    foreach (['Brukskonto', 'Brukskonto Hegra', 'Brukskonto SMN'] as $payee) {
        $own = $classify($user, $payee);

        expect($own->category)->toBeNull()
            ->and($own->needsReview)->toBeFalse();
    }

    expect($classify($user, 'Ukjent butikk')->needsReview)->toBeTrue();
});
