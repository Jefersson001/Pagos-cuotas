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
        Schema::create('dealerships', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 40)->unique();
            $t->string('phone', 40)->nullable();
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $principal = DB::table('dealerships')->insertGetId(['name' => 'Concesionario principal', 'code' => 'PRINCIPAL', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['admin', 'user', 'dealership_admin'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role]);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('dealership_id')->nullable()->constrained();
        });
        $globalRoles = DB::table('roles')->where('name', 'admin')->pluck('id');
        DB::table('users')->where(fn ($q) => $q->whereNotIn('role_id', $globalRoles)->orWhereNull('role_id'))->update(['dealership_id' => $principal]);
        foreach (['clients', 'orders', 'payments', 'products', 'payment_methods'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($principal) {
                $t->foreignId('dealership_id')->default($principal)->constrained();
            });
        }
        Schema::table('payment_methods', function (Blueprint $t) {
            $t->dropUnique(['name']);
            $t->unique(['dealership_id', 'name']);
        });
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->foreignId('dealership_id')->nullable()->constrained();
        });
        DB::table('audit_logs')->update(['dealership_id' => $principal]);
        Schema::create('dealership_settings', function (Blueprint $t) {
            $t->foreignId('dealership_id')->constrained();
            $t->string('key');
            $t->text('value');
            $t->primary(['dealership_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dealerships')->count() > 1) {
            throw new RuntimeException('No se puede retirar la segmentación con varios concesionarios. Respalde y consolide los datos primero.');
        }
        Schema::dropIfExists('dealership_settings');
        Schema::table('payment_methods', function (Blueprint $t) {
            $t->dropUnique(['dealership_id', 'name']);
            $t->unique('name');
        });
        foreach (['audit_logs', 'payment_methods', 'products', 'payments', 'orders', 'clients', 'users'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('dealership_id');
            });
        }
        Schema::dropIfExists('dealerships');
        DB::table('roles')->where('name', 'dealership_admin')->whereNotIn('id', DB::table('users')->select('role_id')->whereNotNull('role_id'))->delete();
    }
};
