<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('access_role_id')->nullable()->after('role')
                ->constrained('access_roles')->nullOnDelete();
            $table->index(['business_id', 'access_role_id']);
        });

        DB::statement("ALTER TABLE users MODIFY role ENUM('owner','manager','cashier','staff') NOT NULL DEFAULT 'owner'");
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'staff')->delete();
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_role_id');
        });
        Schema::dropIfExists('access_roles');
        DB::statement("ALTER TABLE users MODIFY role ENUM('owner','manager','cashier') NOT NULL DEFAULT 'owner'");
    }
};
