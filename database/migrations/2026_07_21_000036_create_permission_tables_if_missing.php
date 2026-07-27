<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('module', 80)->index();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('role_id')->index();
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'permission_id']);
            });
        }

        $this->seedBatchPermissions();
    }

    public function down(): void
    {
        $names = $this->batchPermissionNames();

        if (Schema::hasTable('role_permissions') && Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', $names)->delete();
        }
    }

    private function seedBatchPermissions(): void
    {
        $names = $this->batchPermissionNames();

        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['module' => 'batch_expiry', 'description' => ucwords(str_replace(['batch.', '_'], ['', ' '], $name)), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        foreach ([1, 2] as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    private function batchPermissionNames(): array
    {
        return [
            'batch.view',
            'batch.create',
            'batch.edit_draft',
            'batch.view_ledger',
            'batch.print_label',
            'batch.export',
            'batch.block',
            'batch.unblock',
            'batch.quarantine',
            'batch.release_quarantine',
            'batch.transfer',
            'batch.split',
            'batch.merge',
            'batch.view_cost',
            'batch.view_audit',
        ];
    }
};
