<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_routes_are_not_available(): void
    {
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.email'));
        $this->assertFalse(Route::has('password.reset'));
        $this->assertFalse(Route::has('password.update'));

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'owner@example.com'])->assertNotFound();
        $this->get('/reset-password')->assertNotFound();
        $this->get('/reset-password/token')->assertNotFound();
        $this->post('/reset-password')->assertNotFound();
    }

    public function test_login_screen_does_not_offer_a_password_reset(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Glemt passord', false)
            ->assertDontSee('Forgot your password', false)
            ->assertDontSee('/forgot-password', false)
            ->assertDontSee('/reset-password', false);
    }
}
