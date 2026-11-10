<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('installment_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('number')->nullable()->change();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('installment_schedules')->whereNotNull('order_item_id')->exists()) {
            throw new RuntimeException('No se puede revertir mientras existan conceptos de pago completo.');
        }
        Schema::table('installment_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_item_id');
            $table->unsignedInteger('number')->nullable(false)->change();
        });
    }
};
