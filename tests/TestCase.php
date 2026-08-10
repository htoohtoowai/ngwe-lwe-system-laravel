<?php

namespace Tests;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CommissionTier;
use App\Models\Company;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  list<AccountFeature|string>|null  $features
     * @return array{0: Account, 1: Company}
     */
    protected function createCompanyAccountFixture(
        int|float|string $balance = 0,
        string $accountName = 'Wave Main',
        bool $isAgent = false,
        ?array $features = null,
    ): array {
        $company = Company::query()->create([
            'name' => 'Provider-'.uniqid('', true),
            'category' => 'Pay',
            'is_active' => true,
        ]);

        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => $accountName,
            'phone_number' => '09'.random_int(100000000, 999999999),
            'balance' => $balance,
            'is_agent' => $isAgent,
            'is_active' => true,
        ]);

        foreach ($features ?? AccountFeature::cases() as $feature) {
            AccountFeatureAssignment::query()->create([
                'account_id' => $account->id,
                'feature' => $feature instanceof AccountFeature ? $feature->value : $feature,
            ]);
        }

        return [$account, $company];
    }

    /**
     * Create final-schema tiers for every transaction feature used by tests.
     */
    protected function createCompanyTierFixtures(
        int $companyId,
        int|float|string $feeDeposit = 0,
        int|float|string $feeWithdraw = 0,
        int|float|string $commDeposit = 0,
        int|float|string $commWithdraw = 0,
    ): CommissionTier {
        $cashInTier = null;

        foreach (AccountFeature::cases() as $feature) {
            $usesWithdrawValues = in_array($feature, [
                AccountFeature::CashOut,
                AccountFeature::ReceiveMoney,
            ], true);

            $tier = CommissionTier::query()->create([
                'company_id' => $companyId,
                'feature' => $feature->value,
                'amount_from' => 1,
                'amount_to' => 999_999_999_999,
                'fee_type' => 'FIXED',
                'fee_amount' => $usesWithdrawValues ? $feeWithdraw : $feeDeposit,
                'comm_type' => 'FIXED',
                'comm_amount' => $usesWithdrawValues ? $commWithdraw : $commDeposit,
                'additional_fee_type' => 'FIXED',
                'additional_fee_amount' => 0,
                'is_active' => true,
            ]);

            if ($feature === AccountFeature::CashIn) {
                $cashInTier = $tier;
            }
        }

        return $cashInTier;
    }

    protected function skipIfDatabaseUnavailable(string $scope = 'database tests'): void
    {
        $connection = $this->databaseEnvironmentValue('DB_CONNECTION', 'sqlite');

        if ($connection === 'sqlite') {
            if (! extension_loaded('pdo_sqlite')) {
                $this->markTestSkipped("pdo_sqlite is not enabled for {$scope}.");
            }

            return;
        }

        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            if (! extension_loaded('pdo_mysql')) {
                $this->markTestSkipped("pdo_mysql is not enabled for {$scope}.");
            }

            $host = $this->databaseEnvironmentValue('DB_HOST', '127.0.0.1');
            $port = $this->databaseEnvironmentValue('DB_PORT', '3306');
            $database = $this->databaseEnvironmentValue('DB_DATABASE', 'laravel');
            $username = $this->databaseEnvironmentValue('DB_USERNAME', 'root');
            $password = $this->databaseEnvironmentValue('DB_PASSWORD', '');

            try {
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $pdo->query('SELECT 1');
            } catch (Throwable $exception) {
                $this->markTestSkipped("MySQL database is not available for {$scope}: {$exception->getMessage()}");
            }

            return;
        }

        $this->markTestSkipped("Unsupported test database connection [{$connection}] for {$scope}.");
    }

    private function databaseEnvironmentValue(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}
