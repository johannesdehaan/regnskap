<?php

use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('unauthenticated api requests receive 401', function () {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/transactions')->assertUnauthorized();
    $this->postJson('/api/transactions', [
        'booked_on' => '2026-08-15',
        'amount' => '10.00',
        'payee' => 'Test',
    ])->assertUnauthorized();
});

test('a token can create a transaction for that user', function () {
    $user = User::factory()->unverified()->create();
    $token = $user->createToken('Personlig regnskapsfører')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email);

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-08-15',
            'amount' => '129.00',
            'payee' => 'Rema Test',
            'category' => 'Dagligvarer',
            'payment_method' => 'Kredittkort',
            'note' => 'Melk',
        ])
        ->assertCreated()
        ->assertJsonPath('payee', 'Rema Test')
        ->assertJsonPath('amount', '129.00')
        ->assertJsonPath('category', 'Dagligvarer');

    expect($user->transactions()->count())->toBe(1)
        ->and($user->transactions()->first()->user_id)->toBe($user->id)
        ->and($user->transactions()->first()->category)->toBe(Category::Dagligvarer);
});

test('a token cannot see another users rows', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'payee' => 'Min butikk',
        'category' => Category::Dagligvarer,
    ]);
    $theirs = Transaction::factory()->for($other)->create([
        'payee' => 'Fremmed butikk',
        'category' => null,
    ]);

    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/transactions')
        ->assertOk()
        ->assertJsonFragment(['payee' => 'Min butikk'])
        ->assertJsonMissing(['payee' => 'Fremmed butikk']);

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$theirs->id, [
            'category' => 'Annet',
        ])
        ->assertForbidden();

    expect($theirs->fresh()->category)->toBeNull();
});

test('a token can delete its own transaction', function () {
    $user = User::factory()->create();
    $mine = Transaction::factory()->for($user)->create(['payee' => 'Skal bort']);
    $kept = Transaction::factory()->for($user)->create(['payee' => 'Skal bli']);

    $this->deleteJson('/api/transactions/'.$mine->id)->assertUnauthorized();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->deleteJson('/api/transactions/'.$mine->id)
        ->assertNoContent();

    expect($mine->fresh())->toBeNull()
        ->and($kept->fresh())->not->toBeNull();
});

test('deleting a split transaction also deletes its lines', function () {
    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create(['amount' => '100.00', 'payee' => 'Delt kvittering']);
    $other = Transaction::factory()->for($user)->create(['payee' => 'En annen rad']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'amount' => '60.00'],
                ['category' => 'Annet', 'amount' => '40.00'],
            ],
        ])
        ->assertOk();

    $lineIds = $parent->children()->pluck('id');

    expect($lineIds)->toHaveCount(2);

    $this->withToken($token)
        ->deleteJson('/api/transactions/'.$parent->id)
        ->assertNoContent();

    expect($parent->fresh())->toBeNull()
        ->and(Transaction::query()->whereIn('id', $lineIds)->exists())->toBeFalse()
        ->and($user->transactions()->pluck('id')->all())->toBe([$other->id]);
});

test('a split line cannot be deleted on its own', function () {
    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create(['amount' => '100.00']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'amount' => '60.00'],
                ['category' => 'Annet', 'amount' => '40.00'],
            ],
        ])
        ->assertOk();

    $this->withToken($token)
        ->deleteJson('/api/transactions/'.$parent->children()->first()->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction']);

    expect($parent->children()->count())->toBe(2);
});

test('a token cannot delete another users transaction, and a missing one is 404', function () {
    $user = User::factory()->create();
    $theirs = Transaction::factory()->for(User::factory())->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->deleteJson('/api/transactions/'.$theirs->id)
        ->assertForbidden();

    $this->withToken($token)
        ->deleteJson('/api/transactions/999999')
        ->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
});

test('the booking status filter lists bank rows left as reserved, split ones included', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $reserved = Transaction::factory()->for($user)->create([
        'payee' => 'Gammel reservasjon',
        'booking_status' => BookingStatus::Pending,
    ]);
    $reservedSplit = Transaction::factory()->for($user)->create([
        'payee' => 'Gammel delt reservasjon',
        'amount' => '100.00',
        'booking_status' => BookingStatus::Pending,
    ]);
    Transaction::factory()->for($user)->create([
        'payee' => 'Bokført rad',
        'booking_status' => BookingStatus::Booked,
    ]);
    Transaction::factory()->for($user)->create(['payee' => 'Manuell rad']);
    Transaction::factory()->for(User::factory())->create([
        'payee' => 'Fremmed reservasjon',
        'booking_status' => BookingStatus::Pending,
    ]);

    $this->withToken($token)
        ->postJson('/api/transactions/'.$reservedSplit->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'amount' => '60.00'],
                ['category' => 'Annet', 'amount' => '40.00'],
            ],
        ])
        ->assertOk();

    $ids = $this->withToken($token)
        ->getJson('/api/transactions?booking_status=pending')
        ->assertOk()
        ->assertJsonCount(2)
        ->json('*.id');

    expect($ids)->toEqualCanonicalizing([$reserved->id, $reservedSplit->id]);

    $this->withToken($token)
        ->getJson('/api/transactions?booking_status=kanskje')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['booking_status']);
});

test('categorized ledger csv import via api keeps category', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $csv = implode("\n", [
        'dato,belop,kategori,brukersted,betalingsmate,notat',
        '2026-03-02,129.00,Dagligvarer,Testbutikk,Kredittkort,Melk og brød',
        '',
    ]);

    $this->withToken($token)
        ->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('ledger.csv', $csv),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('imported', 1);

    $row = $user->transactions()->where('payee', 'Testbutikk')->first();

    expect($row)->not->toBeNull()
        ->and($row->category)->toBe(Category::Dagligvarer)
        ->and($row->payment_method)->toBe(PaymentMethod::Kredittkort)
        ->and($row->note)->toBe('Melk og brød');
});

test('revoking a token stops access', function () {
    $user = User::factory()->create();
    $access = $user->createToken('Personlig regnskapsfører');
    $token = $access->plainTextToken;

    $this->withToken($token)->getJson('/api/me')->assertOk();

    $access->accessToken->delete();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});
