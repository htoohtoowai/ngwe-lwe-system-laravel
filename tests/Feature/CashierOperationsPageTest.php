<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashierOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cashier Inertia page tests');
        parent::setUp();
    }

    public function test_cashier_dashboard_and_vault_pages_are_session_protected_inertia_pages(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->get('/cashier')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('cashier/Dashboard')
        );
        $this->actingAs($cashier)->get('/cashier/main-vault-denomination-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('cashier/VaultStock')
        );
    }
}
