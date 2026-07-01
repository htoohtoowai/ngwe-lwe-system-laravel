<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Throwable;

abstract class TestCase extends BaseTestCase
{
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
