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
                'address' => fn () => $table->text('address')->nullable(),
                'logo_path' => fn () => $table->string('logo_path')->nullable(),
                'phone' => fn () => $table->string('phone', 40)->nullable(),
                'email' => fn () => $table->string('email')->nullable(),
                'bank_name' => fn () => $table->string('bank_name')->nullable(),
                'bank_account_number' => fn () => $table->string('bank_account_number', 60)->nullable(),
                'bank_ifsc' => fn () => $table->string('bank_ifsc', 30)->nullable(),
                'bank_account_holder' => fn () => $table->string('bank_account_holder')->nullable(),
                'invoice_terms' => fn () => $table->text('invoice_terms')->nullable(),
                'show_logo_on_invoice' => fn () => $table->boolean('show_logo_on_invoice')->default(true),
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
            foreach (['show_logo_on_invoice', 'invoice_terms', 'bank_account_holder', 'bank_ifsc', 'bank_account_number', 'bank_name', 'email', 'phone', 'logo_path', 'address'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
