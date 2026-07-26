<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DatabaseBackupService
{
    public function create(?string $directory = null): string
    {
        $directory ??= storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/ngwe-lwe-backup-'.now()->format('Ymd-His').'.sql';
        $driver = DB::getDriverName();
        $sql = "-- Ngwe Lwe database backup\n";
        $sql .= '-- Created at: '.now()->toISOString()."\n";
        $sql .= "-- Driver: {$driver}\n\n";

        foreach ($this->tables($driver) as $table) {
            $sql .= $this->tableDdl($driver, $table);
            $sql .= $this->tableData($table);
        }

        File::put($path, $sql);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function tables(string $driver): array
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->sort()
                ->values()
                ->all();
        }

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        }

        return [];
    }

    private function tableDdl(string $driver, string $table): string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $ddl = (string) ($row['Create Table'] ?? array_values($row)[1] ?? '');

            return "DROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n";
        }

        if ($driver === 'sqlite') {
            $row = DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
            $ddl = (string) ($row->sql ?? '');

            return "DROP TABLE IF EXISTS \"{$table}\";\n{$ddl};\n\n";
        }

        return "-- DDL unsupported for {$table}\n\n";
    }

    private function tableData(string $table): string
    {
        $out = '';
        DB::table($table)->orderByRaw('1')->chunk(500, function ($rows) use (&$out, $table): void {
            foreach ($rows as $row) {
                $data = (array) $row;
                $columns = collect(array_keys($data))
                    ->map(fn (string $column): string => '`'.str_replace('`', '``', $column).'`')
                    ->implode(', ');
                $values = collect(array_values($data))
                    ->map(fn (mixed $value): string => $this->literal($value))
                    ->implode(', ');
                $out .= "INSERT INTO `{$table}` ({$columns}) VALUES ({$values});\n";
            }
        });

        return $out."\n";
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::getPdo()->quote((string) $value);
    }
}
