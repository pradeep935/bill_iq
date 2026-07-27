<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->string('name');
                $table->string('type', 30)->default('cash');
                $table->boolean('is_default')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });

            foreach (['Cash' => 'cash', 'UPI' => 'upi', 'Card' => 'card', 'Bank Transfer' => 'bank_transfer'] as $name => $type) {
                DB::table('payment_methods')->insert([
                    'business_id' => 1,
                    'name' => $name,
                    'type' => $type,
                    'is_default' => $type === 'cash',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Preserve payment methods.
    }
};
