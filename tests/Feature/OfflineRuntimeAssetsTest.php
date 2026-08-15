<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class OfflineRuntimeAssetsTest extends TestCase
{
    public function test_browser_runtime_sources_do_not_depend_on_remote_assets(): void
    {
        $files = [
            base_path('vite.config.ts'),
            ...$this->browserSourceFiles(base_path('resources/css')),
            ...$this->browserSourceFiles(base_path('resources/js')),
            ...$this->browserSourceFiles(base_path('resources/views')),
        ];

        $forbiddenPatterns = [
            '/@fonts\b/',
            '/laravel-vite-plugin\/fonts/',
            '/(?<![A-Za-z])(?:https?:)?\/\/(?!localhost\b|127\.0\.0\.1\b|\[::1\])[^\'"\s)]+/i',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach ($forbiddenPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    "Remote runtime asset dependency found in {$file} using {$pattern}",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function browserSourceFiles(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
        );

        foreach ($iterator as $file) {
            if (
                ! $file instanceof SplFileInfo ||
                ! $file->isFile() ||
                ! preg_match('/\.(blade\.php|css|js|ts|vue)$/', $file->getFilename())
            ) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
