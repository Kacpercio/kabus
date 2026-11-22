<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * escrow_transfers is a ledger of every outgoing escrow movement (vendor
     * payouts and buyer refunds). Each row is created inside the same locked
     * database transaction that changes the order status, so a given order can
     * never schedule the same money twice. The transfer itself is sent later by
     * the escrow:process command in two steps (prepare, then relay), which makes
     * it safe to retry after a crash or an RPC timeout.
     */
    public function up(): void
    {
        Schema::create('escrow_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->onDelete('cascade');
            $table->string('type'); // vendor_payout | buyer_refund
            $table->uuid('recipient_id');
            $table->foreign('recipient_id')->references('id')->on('users')->onDelete('cascade');
            // Part of the escrowed funds (in XMR) this transfer settles.
            $table->decimal('gross_amount_xmr', 18, 12);
            // Amount actually sent to the recipient (gross minus commission).
            $table->decimal('amount_xmr', 18, 12);
            $table->string('address')->nullable();
            // pending | awaiting_address | processing | sent
            $table->string('status')->default('pending');
            $table->string('tx_hash')->nullable();
            $table->longText('tx_metadata')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'locked_until']);
            $table->index(['order_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('unconfirmed_received_xmr', 18, 12)->default(0);
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['unconfirmed_received_xmr', 'cancelled_at']);
        });

        Schema::dropIfExists('escrow_transfers');
    }
};
