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
        if (! Schema::hasIndex('clients', 'clients_commercial_owner_index')) {
            Schema::table('clients', fn (Blueprint $table) => $table->index('user_id', 'clients_commercial_owner_index'));
        }
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'document']);
            $table->string('import_reference', 40)->nullable();
            $table->unsignedInteger('list_number')->nullable();
            $table->string('business_type', 80)->default('CONCESIONARIO');
            $table->string('condition', 80)->nullable();
            $table->unsignedInteger('refrigerators')->nullable();
            $table->date('refrigerators_assigned_at')->nullable();
            $table->date('listed_started_at')->nullable();
            $table->text('commercial_registry')->nullable();
            $table->string('registry_jurisdiction')->nullable();
            $table->date('registry_date')->nullable();
            $table->string('registry_number', 80)->nullable();
            $table->string('registry_volume', 80)->nullable();
            $table->date('started_at')->nullable();
            $table->text('legal_representative')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->unique(['dealership_id', 'import_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('clients')->select('user_id', 'document')->groupBy('user_id', 'document')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('No se puede retirar la ficha comercial con sucursales que comparten RIF.');
        }
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['dealership_id', 'import_reference']);
            $table->dropColumn(['import_reference', 'list_number', 'business_type', 'condition', 'refrigerators', 'refrigerators_assigned_at', 'listed_started_at', 'commercial_registry', 'registry_jurisdiction', 'registry_date', 'registry_number', 'registry_volume', 'started_at', 'legal_representative', 'city', 'state']);
            $table->unique(['user_id', 'document']);
        });
    }
};
