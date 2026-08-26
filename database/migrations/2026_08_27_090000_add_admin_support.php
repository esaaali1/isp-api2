<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('username');
        });

        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamps();
        });

        // حساب الإدارة الوحيد — دخول ثابت للوحة الإدارة العامة، بلا اشتراك ينتهي.
        DB::table('agents')->updateOrInsert(
            ['username' => 'admin@essa'],
            [
                'name' => 'الإدارة',
                'password' => '100200300',
                'is_admin' => true,
                'mikrotik_port' => 8728,
                'balance' => 0,
                'electronic_payment_enabled' => false,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYears(50)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('agents')->where('username', 'admin@essa')->delete();

        Schema::dropIfExists('agent_logs');

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
