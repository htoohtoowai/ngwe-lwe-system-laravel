<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CashFloatAssignment;
use App\Models\AgentCommissionEntry;
use App\Models\AgentCommissionTier;
use App\Models\ProviderFeeTier;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransferFeeTier;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class NgweLweModelTest extends TestCase
{
    public function test_user_model_hides_password_and_pin_hash(): void
    {
        $user = new User;

        $this->assertContains('password', $user->getHidden());
        $this->assertContains('pin_hash', $user->getHidden());
    }

    public function test_money_transfer_models_define_expected_fillable_fields(): void
    {
        $this->assertContains('category', (new Company)->getFillable());
        $this->assertContains('company_id', (new Account)->getFillable());
        $this->assertContains('account_type', (new Account)->getFillable());
        $this->assertContains('account_identifier', (new Account)->getFillable());
        $this->assertContains('is_fee_account', (new Account)->getFillable());
        $this->assertContains('is_agent', (new Account)->getFillable());
        $this->assertContains('feature', (new AccountFeatureAssignment)->getFillable());
        $this->assertContains('customer_fee', (new Transaction)->getFillable());
        $this->assertNotContains('commission_amount', (new Transaction)->getFillable());
        $this->assertNotContains('receive_commission_amount', (new Transaction)->getFillable());
        $this->assertNotContains('payout_commission_amount', (new Transaction)->getFillable());
        $this->assertContains('feature', (new ProviderFeeTier)->getFillable());
        $this->assertContains('fee_value', (new ProviderFeeTier)->getFillable());
        $this->assertContains('out_commission_value', (new AgentCommissionTier)->getFillable());
        $this->assertContains('in_commission_value', (new AgentCommissionTier)->getFillable());
        $this->assertContains('transaction_id', (new AgentCommissionEntry)->getFillable());
        $this->assertContains('direction', (new AgentCommissionEntry)->getFillable());
        $this->assertContains('company_from_id', (new TransferFeeTier)->getFillable());
        $this->assertContains('return_denominations_json', (new CashFloatAssignment)->getFillable());
    }

    public function test_decimal_and_array_casts_are_defined_for_financial_models(): void
    {
        $this->assertSame('decimal:2', (new Account)->getCasts()['balance']);
        $this->assertSame('array', (new Transaction)->getCasts()['change_denominations']);
        $this->assertSame('array', (new CashFloatAssignment)->getCasts()['return_denominations_json']);
    }
}
