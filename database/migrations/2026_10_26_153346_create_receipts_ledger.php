<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('token')->unique();
            $t->foreignId('client_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('dealership_id')->constrained();
            $t->foreignId('recorded_by')->constrained('users');
            $t->foreignId('payment_method_id')->constrained();
            $t->foreignId('source_order_id')->nullable()->constrained('orders');
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->text('void_reason')->nullable();
            $t->timestamps();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('receipt_id')->nullable()->constrained();
        });
        DB::table('payments')->orderBy('id')->chunkById(250, function ($payments) {
            foreach ($payments as $payment) {
                $receipt = array_intersect_key((array) $payment, array_flip(['token', 'user_id', 'dealership_id', 'recorded_by', 'payment_method_id', 'amount', 'reference', 'notes', 'voided_at', 'voided_by', 'void_reason', 'created_at', 'updated_at']));
                $receipt['id'] = $payment->id;
                $receipt['client_id'] = DB::table('orders')->where('id', $payment->order_id)->value('client_id');
                $receipt['source_order_id'] = $payment->order_id;
                $id = DB::table('receipts')->insertGetId($receipt);
                DB::table('payments')->where('id', $payment->id)->update(['receipt_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restaure un respaldo completo para revertir el libro de pagos sin perder saldos a favor.');
    }
};
