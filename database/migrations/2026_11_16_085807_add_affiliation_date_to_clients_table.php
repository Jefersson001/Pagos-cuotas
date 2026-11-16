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
        Schema::table('clients', function (Blueprint $table): void {
            $table->date('affiliation_date')->nullable();
        });

        DB::table('clients')->where('customer_kind', 'person')->update([
            'affiliation_date' => DB::raw('(SELECT DATE(MIN(created_at)) FROM orders WHERE orders.client_id = clients.id)'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('affiliation_date');
        });
    }
};
