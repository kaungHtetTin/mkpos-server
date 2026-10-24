<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Check legacy identities before MySQL starts committing schema changes.
        // Historical receipts were credited to book #2 by the running system.
        if (!DB::table('office_bank_books')->where('id', 2)->exists()
            || !DB::table('office_book_settings')->where('id', 1)->exists()) {
            throw new RuntimeException('Office financial settings or legacy bank book #2 is missing. Restore the existing financial configuration before migrating.');
        }
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('role', 30)->default('admin');
        });
        DB::table('platform_admins')->update(['role' => 'super_admin']);
        Schema::create('office_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('action', 200)->index();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('office_founders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('share_bps');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $settings = DB::table('office_book_settings')->where('id', 1)->first();
        foreach ([1 => 'one', 2 => 'two'] as $id => $key) {
            DB::table('office_founders')->insert(['id' => $id, 'name' => $settings->{'founder_'.$key.'_name'}, 'share_bps' => $settings->{'founder_'.$key.'_bps'}, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('office_book_entries', fn (Blueprint $table) => $table->unsignedBigInteger('founder_id')->nullable()->change());
        Schema::table('office_bank_books', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        Schema::table('office_book_settings', fn (Blueprint $table) => $table->foreignId('subscription_account_id')->nullable()->constrained('office_bank_books')->restrictOnDelete());
        DB::table('office_book_settings')->where('id', 1)->update(['subscription_account_id' => 2]);
        Schema::table('subscription_payments', fn (Blueprint $table) => $table->foreignId('account_id')->nullable()->constrained('office_bank_books')->restrictOnDelete());
        DB::table('subscription_payments')->whereNull('account_id')->update(['account_id' => 2]);
        Schema::create('platform_branding', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('primary_color', 7)->default('#0e684e');
            $table->string('logo_path')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->timestamps();
        });
        DB::table('platform_branding')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // The old application credits every receipt to #2 and knows only two
        // founders. Do not silently discard financial meaning on rollback.
        if (DB::table('subscription_payments')->whereNotNull('account_id')->where('account_id', '!=', 2)->exists()
            || DB::table('office_book_settings')->where('id', 1)->where('subscription_account_id', '!=', 2)->exists()
            || DB::table('office_founders')->where('id', '>', 2)->exists()) {
            throw new RuntimeException('Rollback would discard bank-book routing or founder history. Keep this migration and deploy a forward fix instead.');
        }
        $settings = DB::table('office_book_settings')->where('id', 1)->first();
        foreach ([1 => 'one', 2 => 'two'] as $id => $key) {
            $founder = DB::table('office_founders')->where('id', $id)->first();
            if (!$founder || !$founder->is_active || $founder->name !== $settings->{'founder_'.$key.'_name'}
                || $founder->share_bps !== $settings->{'founder_'.$key.'_bps'}) {
                throw new RuntimeException('Rollback would discard updated founder settings. Keep this migration and deploy a forward fix instead.');
            }
        }
        Schema::dropIfExists('platform_branding');
        Schema::table('subscription_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));
        Schema::table('office_book_settings', fn (Blueprint $table) => $table->dropConstrainedForeignId('subscription_account_id'));
        Schema::table('office_bank_books', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::dropIfExists('office_founders');
        Schema::dropIfExists('office_activity_logs');
        Schema::table('platform_admins', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
