<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hsn_masters MODIFY description TEXT NOT NULL');

        Schema::table('hsn_masters', function (Blueprint $table) {
            $table->string('verification_status', 30)->default('verified')->after('taxability')->index();
        });
    }

    public function down(): void
    {
        Schema::table('hsn_masters', function (Blueprint $table) {
            $table->dropColumn('verification_status');
        });
    }
};
