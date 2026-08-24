<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->text('pay_notify_message')->nullable()->after('electronic_payment_enabled');
            $table->text('add_debt_notify_message')->nullable()->after('pay_notify_message');
            $table->text('renew_notify_message')->nullable()->after('add_debt_notify_message');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['pay_notify_message', 'add_debt_notify_message', 'renew_notify_message']);
        });
    }
};
