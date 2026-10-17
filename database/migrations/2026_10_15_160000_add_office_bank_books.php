<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_bank_books', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->timestamps();
        });
        foreach ([1 => 'Kaung Htet Tin', 2 => 'Min Htet Kyaw', 3 => 'VISA'] as $id => $name) {
            DB::table('office_bank_books')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('office_book_entries', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained('office_bank_books')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('office_bank_books')->restrictOnDelete();
            $table->timestamp('bank_assigned_at')->nullable();
            $table->foreignId('bank_assigned_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->index(['account_id', 'currency', 'voided_at']);
            $table->index(['to_account_id', 'currency', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::table('office_book_entries', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'currency', 'voided_at']);
            $table->dropIndex(['to_account_id', 'currency', 'voided_at']);
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('to_account_id');
            $table->dropConstrainedForeignId('bank_assigned_by_admin_id');
            $table->dropColumn('bank_assigned_at');
        });
        Schema::dropIfExists('office_bank_books');
    }
};
