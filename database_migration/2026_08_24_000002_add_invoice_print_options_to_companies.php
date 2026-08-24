<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            foreach ([
                'a4_print_options' => fn () => $table->json('a4_print_options')->nullable(),
                'thermal_print_options' => fn () => $table->json('thermal_print_options')->nullable(),
                'thermal_footer_text' => fn () => $table->string('thermal_footer_text')->nullable(),
            ] as $column => $definition) {
                if (!Schema::hasColumn('companies', $column)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            foreach (['thermal_footer_text', 'thermal_print_options', 'a4_print_options'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
