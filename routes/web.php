<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('inbox')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('inbox', 'pages::inbox')->name('inbox');
    Route::redirect('dashboard', '/inbox')->name('dashboard');

    Route::livewire('overview', 'pages::overview')->name('overview');

    Route::livewire('transactions', 'pages::transactions.index')->name('transactions.index');
    Route::livewire('transactions/create', 'pages::transactions.create')->name('transactions.create');
    Route::livewire('transactions/import', 'pages::transactions.import')->name('transactions.import');

    Route::livewire('mat', 'pages::mat.index')->name('mat.index');
    Route::livewire('mat/regler', 'pages::mat.rules')->name('mat.rules');

    Route::livewire('budget', 'pages::budget.index')->name('budget.index');
    Route::livewire('wealth', 'pages::wealth.index')->name('wealth.index');
    Route::livewire('investments', 'pages::investments.index')->name('investments.index');
    Route::livewire('recurring', 'pages::recurring.index')->name('recurring.index');
    Route::livewire('housing', 'pages::housing.index')->name('housing.index');
    Route::livewire('accounts', 'pages::accounts.index')->name('accounts.index');
});

require __DIR__.'/settings.php';
