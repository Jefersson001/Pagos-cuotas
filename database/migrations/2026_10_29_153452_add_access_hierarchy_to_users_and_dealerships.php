<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealerships', function (Blueprint $table): void {
            $table->string('organization_type', 24)->default('concessionaire');
            $table->foreignId('parent_id')->nullable()->constrained('dealerships')->restrictOnDelete();
        });
        DB::table('dealerships')->where('code', 'PRINCIPAL')->update(['organization_type' => 'head_office']);
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->unique()->constrained('clients')->restrictOnDelete();
        });
        foreach (['admin', 'head_office', 'dealership_admin', 'customer'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::table('dealerships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('organization_type');
        });
    }
};
