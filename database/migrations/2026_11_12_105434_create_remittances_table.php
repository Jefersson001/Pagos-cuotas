<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('dealership_id')->constrained('dealerships');
            $table->foreignId('concessionaire_id')->constrained('clients');
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->date('payment_date');
            $table->string('reference');
            $table->decimal('amount', 14, 2);
            $table->string('proof_path');
            $table->string('status')->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('approval_proof_path')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
        Schema::table('receipts', function (Blueprint $table): void {
            $table->string('collection_channel')->default('head_office')->index();
            $table->foreignId('collecting_concessionaire_id')->nullable()->constrained('clients');
            $table->foreignId('remittance_id')->nullable()->constrained('remittances');
        });
        Schema::create('remittance_receipt', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remittance_id')->constrained('remittances');
            $table->foreignId('receipt_id')->constrained('receipts');
            $table->unique(['remittance_id', 'receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_receipt');
        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('remittance_id');
            $table->dropConstrainedForeignId('collecting_concessionaire_id');
            $table->dropColumn('collection_channel');
        });
        Schema::dropIfExists('remittances');
    }
};
