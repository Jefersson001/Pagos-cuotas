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
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 60)->nullable();
            $table->string('model')->nullable();
            $table->string('transmission', 20)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 20)->nullable();
            $table->decimal('cash_price', 14, 2)->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
        });
        DB::table('products')->update(['model' => DB::raw('name'), 'cash_price' => DB::raw('price')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['brand', 'model', 'transmission', 'year', 'color', 'cash_price', 'installment_count']);
        });
    }
};
