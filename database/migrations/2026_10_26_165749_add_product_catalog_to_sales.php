<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('initial_amount', 14, 2)->default(0);
            $t->boolean('active')->default(true);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('product_id')->nullable()->constrained();
            $t->decimal('initial_amount', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('product_id');
            $t->dropColumn('initial_amount');
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['initial_amount', 'active']));
    }
};
