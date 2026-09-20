<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaAssetsTest extends TestCase
{
    public function test_pwa_manifest_and_service_worker_are_available(): void
    {
        $manifestPath = public_path('manifest.webmanifest');
        $serviceWorkerPath = public_path('sw.js');
        $offlinePath = public_path('offline.html');

        $this->assertFileExists($manifestPath);
        $this->assertFileExists($serviceWorkerPath);
        $this->assertFileExists($offlinePath);

        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_service_worker_does_not_cache_business_routes(): void
    {
        $serviceWorker = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString(
            "request.method !== 'GET'",
            $serviceWorker,
        );
        $this->assertStringContainsString(
            "request.mode === 'navigate'",
            $serviceWorker,
        );
        $this->assertStringContainsString(
            "url.pathname.startsWith('/build/assets/')",
            $serviceWorker,
        );

        $this->assertStringNotContainsString(
            "caches.put('/transactions",
            $serviceWorker,
        );
        $this->assertStringNotContainsString(
            "caches.put('/accounts",
            $serviceWorker,
        );
        $this->assertStringNotContainsString(
            "caches.put('/teller",
            $serviceWorker,
        );
        $this->assertStringNotContainsString(
            "caches.put('/login",
            $serviceWorker,
        );
    }
}
