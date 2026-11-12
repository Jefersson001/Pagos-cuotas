<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealership_id')->constrained('dealerships')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['dealership_id', 'name']);
            $table->index(['dealership_id', 'active']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('sales_event_id')->nullable()->constrained('sales_events');
        });
        foreach (DB::table('dealerships')->orderBy('id')->pluck('id') as $companyId) {
            $id = DB::table('sales_events')->insertGetId(['dealership_id' => $companyId, 'name' => 'Jornada normal', 'is_default' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('orders')->where('dealership_id', $companyId)->update(['sales_event_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_event_id');
        });
        Schema::dropIfExists('sales_events');
    }
};
