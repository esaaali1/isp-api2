<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->after('radius_secret');
            $table->boolean('electronic_payment_enabled')->default(false)->after('balance');
        });

        Schema::create('package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('package');
            $table->unsignedBigInteger('price')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'package']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_prices');

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['balance', 'electronic_payment_enabled']);
        });
    }
};
