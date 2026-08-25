<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('category', 40)->default('system')->after('action');
            $table->string('module', 80)->default('system')->after('category');
            $table->string('status', 20)->default('success')->after('module');
            $table->string('actor_name')->nullable()->after('user_id');
            $table->string('actor_role', 40)->nullable()->after('actor_name');
            $table->text('description')->nullable()->after('entity_id');
            $table->json('old_values')->nullable()->after('details');
            $table->json('new_values')->nullable()->after('old_values');
            $table->json('changed_fields')->nullable()->after('new_values');
            $table->string('ip_address', 64)->nullable()->after('changed_fields');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->string('route')->nullable()->after('user_agent');
            $table->string('http_method', 12)->nullable()->after('route');
            $table->uuid('request_id')->nullable()->after('http_method');
            $table->text('failure_reason')->nullable()->after('request_id');

            $table->index(['category', 'created_at'], 'activity_logs_category_created_idx');
            $table->index(['module', 'created_at'], 'activity_logs_module_created_idx');
            $table->index(['action', 'created_at'], 'activity_logs_action_created_idx');
            $table->index(['status', 'created_at'], 'activity_logs_status_created_idx');
            $table->index(['entity_type', 'entity_id'], 'activity_logs_entity_idx');
            $table->index('request_id', 'activity_logs_request_id_idx');
        });


        // Preserve older audit evidence while making it useful in the new
        // enterprise filters. Historical request metadata cannot be recreated,
        // but actor snapshots/category/module can be backfilled safely.
        $users = DB::table('users')
            ->get(['id', 'full_name', 'username', 'role'])
            ->keyBy('id');

        DB::table('activity_logs')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($users): void {
                foreach ($rows as $row) {
                    $action = (string) ($row->action ?? '');
                    $entity = (string) ($row->entity_type ?? 'system');
                    $actor = $row->user_id !== null ? $users->get($row->user_id) : null;

                    $category = match (true) {
                        str_contains($action, 'login'),
                        str_contains($action, 'logout'),
                        str_contains($action, 'password'),
                        str_contains($action, 'pin') => 'authentication',
                        $action === 'balance_adjust', str_contains($action, 'adjustment') => 'financial',
                        $entity === 'transaction' => 'transactions',
                        str_contains($entity, 'vault'), str_contains($entity, 'float') => 'vault_float',
                        in_array($entity, ['user', 'role', 'permission'], true) => 'users_permissions',
                        in_array($entity, ['account', 'company', 'exchange_rate', 'provider_fee_tier', 'agent_commission_tier', 'transfer_fee_tier'], true) => 'master_data',
                        default => 'system',
                    };

                    DB::table('activity_logs')->where('id', $row->id)->update([
                        'actor_name' => $actor?->full_name ?? $actor?->username,
                        'actor_role' => $actor?->role,
                        'category' => $category,
                        'module' => $entity !== '' ? $entity : 'system',
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('activity_logs')->whereNull('user_id')->delete();

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_category_created_idx');
            $table->dropIndex('activity_logs_module_created_idx');
            $table->dropIndex('activity_logs_action_created_idx');
            $table->dropIndex('activity_logs_status_created_idx');
            $table->dropIndex('activity_logs_entity_idx');
            $table->dropIndex('activity_logs_request_id_idx');

            $table->dropColumn([
                'category',
                'module',
                'status',
                'actor_name',
                'actor_role',
                'description',
                'old_values',
                'new_values',
                'changed_fields',
                'ip_address',
                'user_agent',
                'route',
                'http_method',
                'request_id',
                'failure_reason',
            ]);

            // Existing installations created user_id as NOT NULL. The down
            // migration restores that contract after the enterprise audit
            // metadata has been removed.
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
