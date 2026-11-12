<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_freight')->default(false);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('is_freight')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('is_freight');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_freight');
        });
    }
};
