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
        DB::table('dealerships')->whereNull('parent_id')->where('organization_type', 'concessionaire')->update(['organization_type' => 'head_office']);
    }

    /** Classification corrections remain in place when rolling back. */
    public function down(): void {}
};
