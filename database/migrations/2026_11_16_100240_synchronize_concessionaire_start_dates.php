<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('clients')->where('customer_kind', 'dealership')->whereNotNull('created_at')
            ->update(['started_at' => DB::raw('DATE(created_at)')]);
    }

    /**
     * The former manually entered dates cannot be reconstructed.
     */
    public function down(): void {}
};
