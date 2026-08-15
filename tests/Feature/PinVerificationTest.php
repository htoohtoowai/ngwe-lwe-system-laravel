<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PinVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class PinVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('PIN verification tests');
        parent::setUp();
    }

    public function test_pin_verifier_accepts_correct_pin(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        app(PinVerifier::class)->verify($user, '2222');
        $this->assertTrue(true);
    }

    public function test_pin_verifier_rejects_incorrect_pin(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $this->expectException(InvalidArgumentException::class);
        app(PinVerifier::class)->verify($user, '9999');
    }
}
