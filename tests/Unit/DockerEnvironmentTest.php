<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerEnvironmentTest extends TestCase
{
    public function test_docker_env_example_declares_required_runtime_values(): void
    {
        $env = file_get_contents(__DIR__.'/../../.env.example');

        $this->assertIsString($env);

        $this->assertStringNotContainsString('NGWE_LWE_AUTH_SECRET', $env);

        foreach ([
            'APP_KEY=',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DOCKER_DB_HOST=mysql',
            'DOCKER_DB_DATABASE=ngwe_lwe_laravel',
            'REVERB_APP_ID=',
            'REVERB_APP_KEY=',
            'REVERB_APP_SECRET=',
            'VITE_REVERB_APP_KEY=',
            'DOCKER_REVERB_HOST=reverb',
            'DOCKER_REVERB_PORT=8080',
            'DOCKER_VITE_REVERB_HOST=localhost',
            'DOCKER_VITE_REVERB_PORT=8080',
        ] as $expectedLine) {
            $this->assertStringContainsString($expectedLine, $env);
        }
    }

    public function test_docker_startup_guard_requires_application_and_realtime_values(): void
    {
        $script = file_get_contents(__DIR__.'/../../docker/ensure-env.sh');

        $this->assertIsString($script);
        $this->assertStringNotContainsString('NGWE_LWE_AUTH_SECRET', $script);

        foreach ([
            'require_env APP_KEY',
            'require_env DB_CONNECTION',
            'require_env DB_HOST',
            'require_env DB_DATABASE',
            'require_env DB_USERNAME',
            'require_env REVERB_APP_ID',
            'require_env REVERB_APP_KEY',
            'require_env REVERB_APP_SECRET',
            'require_env VITE_REVERB_APP_KEY',
            'VITE_REVERB_APP_KEY must match REVERB_APP_KEY',
            'APP_DEBUG must be false when APP_ENV=production.',
        ] as $expectedLine) {
            $this->assertStringContainsString($expectedLine, $script);
        }
    }

    public function test_phpunit_defaults_to_sqlite_and_keeps_optional_mysql_integration_config(): void
    {
        $compose = file_get_contents(__DIR__.'/../../docker-compose.yml');
        $phpunit = file_get_contents(__DIR__.'/../../phpunit.xml');
        $mysqlPhpunit = file_get_contents(__DIR__.'/../../phpunit.mysql.xml');

        $this->assertIsString($compose);
        $this->assertIsString($phpunit);
        $this->assertIsString($mysqlPhpunit);

        $this->assertStringContainsString('name="DB_CONNECTION" value="sqlite"', $phpunit);
        $this->assertStringContainsString('name="DB_DATABASE" value=":memory:"', $phpunit);

        $this->assertStringContainsString('mysql-test:', $compose);
        $this->assertStringContainsString('profiles: ["test"]', $compose);
        $this->assertStringContainsString('MYSQL_TEST_HOST_PORT:-3308', $compose);
        $this->assertStringContainsString('name="DB_PORT" value="3308"', $mysqlPhpunit);
        $this->assertStringContainsString('name="DB_DATABASE" value="ngwe_lwe_laravel_test"', $mysqlPhpunit);
        $this->assertStringContainsString('name="DB_USERNAME" value="ngwe_lwe_test"', $mysqlPhpunit);
    }
}
