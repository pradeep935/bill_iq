<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tally_connections', function (Blueprint $table) {
            $table->string('connector_token_hash', 64)->nullable()->index()->after('pairing_token_hash');
            $table->timestamp('connector_token_issued_at')->nullable()->after('pairing_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('tally_connections', function (Blueprint $table) {
            $table->dropIndex(['connector_token_hash']);
            $table->dropColumn(['connector_token_hash', 'connector_token_issued_at']);
        });
    }
};
