<?php

use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ngwe-lwe:backup {--dir= : Backup output directory}', function (DatabaseBackupService $backups) {
    $path = $backups->create($this->option('dir'));
    $this->info("Backup created: {$path}");
})->purpose('Create a point-in-time Ngwe Lwe database backup');
