<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('wireguard_private_key', 64)->nullable()->after('radius_secret');
            $table->string('wireguard_public_key', 64)->nullable()->after('wireguard_private_key');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['wireguard_private_key', 'wireguard_public_key']);
        });
    }
};
