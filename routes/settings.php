<?php

use App\Http\Controllers\Sparebank1Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/agent', 'pages::settings.agent')->name('settings.agent');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/bank', 'pages::settings.bank')->name('settings.bank');
    Route::get('sparebank1/connect', [Sparebank1Controller::class, 'connect'])->name('sparebank1.connect');
    Route::get('sparebank1/callback', [Sparebank1Controller::class, 'callback'])->name('sparebank1.callback');

    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
