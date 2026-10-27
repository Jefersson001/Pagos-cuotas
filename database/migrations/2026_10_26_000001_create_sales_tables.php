<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->constrained();
            $t->string('username')->nullable()->unique();
            $t->boolean('active')->default(true);
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('name');
            $t->string('document');
            $t->string('phone');
            $t->string('whatsapp')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'document']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('name');
            $t->decimal('price', 14, 2);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('token')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('client_id')->constrained();
            $t->string('description');
            $t->decimal('total', 14, 2);
            $t->decimal('initial_amount', 14, 2)->default(0);
            $t->string('frequency');
            $t->text('notes')->nullable();
            $t->boolean('cancelled')->default(false);
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->string('description');
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
        });
        Schema::create('installment_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->unsignedInteger('number');
            $t->date('due_date')->index();
            $t->decimal('amount', 14, 2);
            $t->unique(['order_id', 'number']);
        });
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('token')->unique();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('recorded_by')->constrained('users');
            $t->foreignId('payment_method_id')->constrained();
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_initial')->default(false);
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->text('void_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained();
            $t->foreignId('installment_schedule_id')->constrained();
            $t->decimal('amount', 14, 2);
            $t->unique(['payment_id', 'installment_schedule_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('action');
            $t->string('entity');
            $t->unsignedBigInteger('entity_id');
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'payment_allocations', 'payments', 'payment_methods', 'installment_schedules', 'order_items', 'orders', 'products', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn(['username', 'active', 'last_login_at']);
        });
        Schema::dropIfExists('roles');
    }
};
