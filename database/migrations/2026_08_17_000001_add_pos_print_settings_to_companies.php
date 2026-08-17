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
            if (!Schema::hasColumn('companies', 'default_print_format')) {
                $table->string('default_print_format', 20)->default('a4');
            }
            if (!Schema::hasColumn('companies', 'thermal_paper_width')) {
                $table->string('thermal_paper_width', 20)->default('80mm');
            }
            if (!Schema::hasColumn('companies', 'auto_print_after_payment')) {
                $table->boolean('auto_print_after_payment')->default(false);
            }
            if (!Schema::hasColumn('companies', 'show_logo_on_thermal_receipt')) {
                $table->boolean('show_logo_on_thermal_receipt')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            foreach (['show_logo_on_thermal_receipt', 'auto_print_after_payment', 'thermal_paper_width', 'default_print_format'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
