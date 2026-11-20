<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now()->utc();

        DB::table('users')->updateOrInsert(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => '$2y$12$Eitwtkv9vnpYeLrY0dgbZunbEG9yF9Fz9op5GJc0jkGSobOfAYdte',
                'email_verified_at' => $now,
                'is_admin' => true,
                'balance_nano' => 25_000_000_000,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        DB::table('users')->where('email', 'test@example.com')->delete();
    }
};
