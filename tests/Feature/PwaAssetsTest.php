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
        $this->assertFileExists(public_path('apple-touch-icon.png'));
        $this->assertFileExists(public_path('pwa-icon-192.png'));
        $this->assertFileExists(public_path('pwa-icon-512.png'));
        $this->assertFileExists(public_path('pwa-icon-maskable-512.png'));

        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Doctor Phone', $manifest['name']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#ffffff', $manifest['theme_color']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_pwa_shell_has_ios_safe_area_support(): void
    {
        $blade = (string) file_get_contents(
            resource_path('views/app.blade.php'),
        );
        $layout = (string) file_get_contents(
            resource_path('js/layouts/BankLayout.vue'),
        );

        $this->assertStringContainsString('viewport-fit=cover', $blade);
        $this->assertStringContainsString('apple-mobile-web-app-capable', $blade);
        $this->assertStringContainsString('min-h-dvh', $layout);
        $this->assertStringContainsString('100dvh', $layout);
        $this->assertStringContainsString('safe-area-inset-top', $layout);
        $this->assertStringContainsString('safe-area-inset-bottom', $layout);
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
        $this->assertStringContainsString(
            "key.startsWith(CACHE_PREFIX)",
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
