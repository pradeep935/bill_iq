<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $name) {
            if (!Schema::hasTable($name) || Schema::hasColumn($name, 'gst_type')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->string('gst_type', 20)->nullable()->after('gstin');
            });
            // Existing parties: a GSTIN means registered (regular); composition has to be chosen by the user.
            DB::table($name)->whereNotNull('gstin')->where('gstin', '!=', '')->update(['gst_type' => 'regular']);
            DB::table($name)->whereNull('gst_type')->update(['gst_type' => 'unregistered']);
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $name) {
            if (Schema::hasColumn($name, 'gst_type')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('gst_type'));
            }
        }
    }
};
