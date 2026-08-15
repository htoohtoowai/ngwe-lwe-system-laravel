<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TellerHistoryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('teller Inertia page tests');
        parent::setUp();
    }

    public function test_teller_transaction_pages_are_inertia_web_pages(): void
    {
        $teller = User::factory()->create(['role' => 'teller']);
        $pages = [
            '/transactions/cash-in' => 'transactions/CashIn',
            '/transactions/cash-out' => 'transactions/CashOut',
            '/transactions/transfer' => 'transactions/Transfer',
            '/transactions/exchange' => 'transactions/Exchange',
        ];

        foreach ($pages as $uri => $component) {
            $this->actingAs($teller)->get($uri)->assertOk()->assertInertia(
                fn (Assert $page) => $page->component($component)
            );
        }
    }

    public function test_admin_and_cashier_cannot_open_teller_transaction_entry_pages(): void
    {
        foreach (['admin', 'cashier'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/transactions/cash-in')->assertRedirect($role === 'admin' ? '/admin' : '/cashier');
        }
    }
}
