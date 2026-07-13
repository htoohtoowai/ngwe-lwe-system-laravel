<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerEnvironmentTest extends TestCase
{
    public function test_docker_env_example_declares_required_runtime_values(): void
    {
        $env = file_get_contents(__DIR__.'/../../.env.example');

        $this->assertIsString($env);

        foreach ([
            'APP_KEY=',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DOCKER_DB_HOST=mysql',
            'DOCKER_DB_DATABASE=ngwe_lwe_laravel',
            'NGWE_LWE_AUTH_SECRET=',
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

    public function test_docker_startup_guard_requires_auth_and_realtime_values(): void
    {
        $script = file_get_contents(__DIR__.'/../../docker/ensure-env.sh');

        $this->assertIsString($script);

        foreach ([
            'require_env APP_KEY',
            'require_env DB_CONNECTION',
            'require_env DB_HOST',
            'require_env DB_DATABASE',
            'require_env DB_USERNAME',
            'require_env NGWE_LWE_AUTH_SECRET',
            'require_env REVERB_APP_ID',
            'require_env REVERB_APP_KEY',
            'require_env REVERB_APP_SECRET',
            'require_env VITE_REVERB_APP_KEY',
            'NGWE_LWE_AUTH_SECRET must be at least 32 characters.',
            'VITE_REVERB_APP_KEY must match REVERB_APP_KEY',
            'APP_DEBUG must be false when APP_ENV=production.',
        ] as $expectedLine) {
            $this->assertStringContainsString($expectedLine, $script);
        }
    }
}
