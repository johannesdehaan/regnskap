<?php

use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a ledger csv keeps category payment method and note', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $this->actingAs($user);

    $csv = implode("\n", [
        'dato,belop,kategori,brukersted,betalingsmate,notat',
        '2026-03-02,129.00,Dagligvarer,Testbutikk,Kredittkort,Melk og brød',
        '2026-03-03,40.00,Ukjent post,Rar butikk,Kredittkort,Skal i innboks',
        '',
    ]);

    Livewire::test('pages::transactions.import')
        ->set('upload', UploadedFile::fake()->createWithContent('ledger.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    $kept = $user->transactions()->where('payee', 'Testbutikk')->first();
    $unknown = $user->transactions()->where('payee', 'Rar butikk')->first();

    expect($kept)->not->toBeNull()
        ->and($kept->category)->toBe(Category::Dagligvarer)
        ->and($kept->payment_method)->toBe(PaymentMethod::Kredittkort)
        ->and($kept->note)->toBe('Melk og brød')
        ->and($unknown->category)->toBeNull()
        ->and($unknown->note)->toBe('Skal i innboks')
        ->and($user->transactions()->inbox()->count())->toBe(1);
});

test('a bank csv without kategori stays uncategorized', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $this->actingAs($user);

    $csv = implode("\n", [
        'Dato,Beløp,Tekst',
        '2026-03-04,75.00,AtB Testbillett',
        '',
    ]);

    Livewire::test('pages::transactions.import')
        ->set('upload', UploadedFile::fake()->createWithContent('bank.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    $row = $user->transactions()->first();

    expect($user->transactions()->count())->toBe(1)
        ->and($row->category)->toBeNull()
        ->and($row->payee)->toBe('AtB Testbillett')
        ->and($user->transactions()->inbox()->count())->toBe(1);
});

test('ledger import still skips duplicates on date amount and payee', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $this->actingAs($user);

    $csv = implode("\n", [
        'dato,belop,kategori,brukersted,betalingsmate,notat',
        '2026-03-05,20.00,Annet,Duplikat Test,Wise,første',
        '2026-03-05,20.00,Annet,Duplikat Test,Wise,andre',
        '',
    ]);

    Livewire::test('pages::transactions.import')
        ->set('upload', UploadedFile::fake()->createWithContent('dupes.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    expect($user->transactions()->count())->toBe(1)
        ->and($user->transactions()->first()->note)->toBe('første');
});
