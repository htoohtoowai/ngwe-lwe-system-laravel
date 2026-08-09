<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CashFloatAssignment;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ServiceType;
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
        $this->assertContains('operation', (new ServiceType)->getFillable());
        $this->assertContains('company_id', (new Account)->getFillable());
        $this->assertContains('is_fee_account', (new Account)->getFillable());
        $this->assertContains('is_agent', (new Account)->getFillable());
        $this->assertContains('feature', (new AccountFeatureAssignment)->getFillable());
        $this->assertContains('customer_fee', (new Transaction)->getFillable());
        $this->assertContains('feature', (new CommissionTier)->getFillable());
        $this->assertContains('fee_amount', (new CommissionTier)->getFillable());
        $this->assertContains('fee_amount_deposit', (new CommissionTier)->getFillable());
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
