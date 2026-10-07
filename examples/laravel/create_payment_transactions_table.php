<?php

// database/migrations/2026_01_01_000000_create_payment_transactions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');                    // an Order, an Invoice...
            $table->string('provider', 20);               // payme | click | uzum | paynet | octo
            $table->string('external_id', 100);           // the provider's transaction id
            $table->decimal('amount', 15, 2);
            $table->tinyInteger('state');                 // Uzpay\TransactionState
            $table->tinyInteger('reason')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();  // Unix milliseconds, as Payme counts
            $table->unsignedBigInteger('perform_time')->nullable();
            $table->unsignedBigInteger('cancel_time')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['provider', 'create_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
