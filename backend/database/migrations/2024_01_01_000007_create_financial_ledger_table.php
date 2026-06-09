<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('session_id')->nullable()
                  ->constrained('service_sessions')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('transaction_hash', 64)->unique(); // idempotency key
            $table->enum('allocation_type', ['operations', 'charity', 'missions']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger');
    }
};
