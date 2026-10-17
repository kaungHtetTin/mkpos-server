<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subscription_approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan_name');
            $table->timestamp('created_at');
            $table->index(['business_id', 'id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscription_approval_events');
    }
};
