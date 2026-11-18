<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 30)->unique();
            $table->unsignedInteger('profile_id')->default(0);
            $table->text('photo_url')->nullable();
            $table->string('provider', 24)->default('email');
            $table->string('google_id')->nullable()->unique();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_account_verified')->default(false);
            $table->boolean('is_guest')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_guest']);
            $table->dropUnique(['google_id']);
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username', 'profile_id', 'photo_url', 'provider',
                'google_id', 'is_verified', 'is_account_verified', 'is_guest',
            ]);
        });
    }
};
