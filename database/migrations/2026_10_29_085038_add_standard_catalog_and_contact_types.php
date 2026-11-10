<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('customer_kind', 20)->default('person')->index();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type', 20)->default('general');
            $table->string('sku', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['product_type', 'sku', 'description', 'image_path']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('customer_kind'));
    }
};
