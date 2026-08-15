<?php

namespace Tests;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\AgentCommissionTier;
use App\Models\ProviderFeeTier;
use App\Models\Company;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

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
            'account_type' => 'PAY',
            'account_identifier' => '09'.random_int(100000000, 999999999),
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
     * @param  list<AccountFeature|string>|null  $features
     */
    protected function assignAccountFeatures(Account $account, ?array $features = null): Account
    {
        foreach ($features ?? AccountFeature::cases() as $feature) {
            AccountFeatureAssignment::query()->updateOrCreate([
                'account_id' => $account->id,
                'feature' => $feature instanceof AccountFeature ? $feature->value : $feature,
            ], []);
        }

        return $account->load('featureAssignments');
    }

    /**
     * Create final-schema customer-fee and agent-commission tiers used by tests.
     */
    protected function createCompanyTierFixtures(
        int $companyId,
        int|float|string $cashInFee = 0,
        int|float|string $cashOutFee = 0,
        int|float|string $outCommission = 0,
        int|float|string $inCommission = 0,
        int|float|string $sendMoneyFee = 0,
        int|float|string $receiveMoneyFee = 0,
    ): ProviderFeeTier {
        $configs = [
            AccountFeature::CashIn->value => [$cashInFee],
            AccountFeature::CashOut->value => [$cashOutFee],
            AccountFeature::SendMoney->value => [$sendMoneyFee],
            AccountFeature::ReceiveMoney->value => [$receiveMoneyFee],
        ];

        $cashInTier = null;

        foreach ($configs as $feature => [$feeAmount]) {
            $feeTier = ProviderFeeTier::query()->create([
                'company_id' => $companyId,
                'feature' => $feature,
                'amount_from' => 1,
                'amount_to' => 999_999_999_999,
                'fee_type' => 'FIXED',
                'fee_value' => $feeAmount,
                'additional_fee_type' => 'FIXED',
                'additional_fee_value' => 0,
                'is_active' => true,
            ]);

            if ($feature === AccountFeature::CashIn->value) {
                $cashInTier = $feeTier;
            }
        }

        AgentCommissionTier::query()->create([
            'company_id' => $companyId,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'commission_type' => 'FIXED',
            'out_commission_value' => $outCommission,
            'in_commission_value' => $inCommission,
            'is_active' => true,
        ]);

        return $cashInTier;
    }

    protected function skipIfDatabaseUnavailable(string $scope = 'database tests'): void
    {
        $connection = $this->databaseEnvironmentValue('DB_CONNECTION', 'sqlite');

        if ($connection === 'sqlite') {
            if (! extension_loaded('pdo_sqlite')) {
                $this->fail("pdo_sqlite is required for {$scope}. Enable the SQLite PDO extension or run the optional MySQL suite with phpunit.mysql.xml.");
            }

            return;
        }

        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            if (! extension_loaded('pdo_mysql')) {
                $this->fail("pdo_mysql is required for {$scope} when using the MySQL test configuration.");
            }

            $host = $this->databaseEnvironmentValue('DB_HOST', '127.0.0.1');
            $port = $this->databaseEnvironmentValue('DB_PORT', '3308');
            $database = $this->databaseEnvironmentValue('DB_DATABASE', 'ngwe_lwe_laravel_test');
            $username = $this->databaseEnvironmentValue('DB_USERNAME', 'ngwe_lwe_test');
            $password = $this->databaseEnvironmentValue('DB_PASSWORD', 'ngwe_lwe_test_secret');

            try {
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $pdo->query('SELECT 1');
            } catch (Throwable $exception) {
                $this->fail(
                    "MySQL test database is not available for {$scope}. ".
                    "Start it with: docker compose --profile test up -d --wait mysql-test. ".
                    "Connection error: {$exception->getMessage()}"
                );
            }

            return;
        }

        $this->fail("Unsupported test database connection [{$connection}] for {$scope}.");
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
