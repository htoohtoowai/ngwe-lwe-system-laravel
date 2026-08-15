<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientVaultDenominationException;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaultLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('vault ledger tests');
        parent::setUp();
    }

    public function test_vault_balance_is_in_minus_out(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [10000 => 5, 5000 => 4], $owner->id);
        $vault->recordBulk('vault_out', [10000 => 2], $owner->id);

        $balance = $vault->getVaultBalance();
        $this->assertSame(3, $balance[10000]);
        $this->assertSame(4, $balance[5000]);
    }

    public function test_vault_cannot_go_negative(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [10000 => 1], $owner->id);

        $this->expectException(InsufficientVaultDenominationException::class);
        $vault->recordBulk('vault_out', [10000 => 2], $owner->id);
    }

    public function test_admin_vault_page_uses_session_auth_not_api_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/vault')->assertOk();
    }
}
