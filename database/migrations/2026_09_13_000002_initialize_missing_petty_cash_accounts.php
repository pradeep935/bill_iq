<?php

use Illuminate\Database\Migrations\Migration;
use Database\Seeders\PettyCashAccountSeeder;

return new class extends Migration
{
    public function up(): void { (new PettyCashAccountSeeder)->run(); }
    public function down(): void { /* Preserve account mappings and any subsequent ledger entries. */ }
};
