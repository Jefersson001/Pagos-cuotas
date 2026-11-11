<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    private function actor(string $role = 'admin', ?Dealership $company = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'dealership_id' => $company?->id]);
    }

    private function client(User $owner): Client
    {
        return Client::create(['name' => 'Titular de prueba', 'phone' => '04121234567', 'document' => (string) Str::uuid(), 'user_id' => $owner->id, 'dealership_id' => $owner->dealership_id, 'active' => true]);
    }

    private function sale(User $owner, Client $client): Order
    {
        return app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Pedido', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '100.00']], 'initial_amount' => '20.00', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);
    }

    private function receive(User $owner, Client $client): Receipt
    {
        return app(ReceiptService::class)->receive($owner, $client->id, ['token' => (string) Str::uuid(), 'amount' => '60.00', 'payment_method_id' => PaymentMethod::where('dealership_id', $client->dealership_id)->value('id'), 'reference' => 'UNIQUE-REFERENCE']);
    }

    public function test_master_rows_open_read_only_and_offer_separate_edit_actions(): void
    {
        $company = Dealership::firstOrFail();
        $admin = $this->actor();
        $product = Product::factory()->create(['dealership_id' => $company->id, 'user_id' => $admin->id]);
        $method = PaymentMethod::where('dealership_id', $company->id)->firstOrFail();
        $this->actingAs($admin);
        foreach ([
            ['products.index', 'products.show', 'products.edit', $product],
            ['users', 'users.show', 'users.edit', $admin],
            ['dealerships.index', 'dealerships.show', 'dealerships.edit', $company],
            ['payment-methods.index', 'payment-methods.show', 'payment-methods.edit', $method],
        ] as [$index, $show, $edit, $record]) {
            $this->get(route($index))->assertOk()->assertSee('data-row-link="'.route($show, $record).'"', false)->assertSee(route($edit, $record), false);
            $this->withSession(['_old_input' => ['name' => 'UNSAVED-INFORMATION']])->get(route($show, $record))->assertOk()->assertSee('Solo lectura')->assertSee('class="record-fields" disabled', false)->assertSee('Duplicar')->assertSee('Eliminar registro')->assertDontSee('UNSAVED-INFORMATION');
            $this->get(route($edit, $record))->assertOk()->assertDontSee('class="record-fields" disabled', false)->assertSee('data-record-status', false);
        }
    }

    public function test_client_duplicate_is_an_unsaved_draft_without_reusing_the_document(): void
    {
        $owner = $this->actor('dealership_admin', Dealership::firstOrFail());
        $client = $this->client($owner);

        $this->actingAs($owner)->get(route('records.duplicate', ['type' => 'client', 'id' => $client->id]))
            ->assertRedirect(route('clients.create'))->assertSessionHasInput('document', '')->assertSessionHasInput('name', 'Titular de prueba (copia)');
        $this->assertDatabaseCount('clients', 1);
        $this->post(route('clients.store'), ['name' => 'Copia', 'document' => '', 'duplicate_record' => 'client', 'active' => 1])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_user_duplicate_does_not_copy_credentials_or_client_access(): void
    {
        $admin = $this->actor();
        $source = $this->actor('dealership_admin', Dealership::firstOrFail());
        $count = User::count();

        $this->actingAs($admin)->get(route('records.duplicate', ['type' => 'user', 'id' => $source->id]))
            ->assertRedirect(route('users.create'))->assertSessionHasInput('active', 0)
            ->assertSessionMissing('_old_input.email')->assertSessionMissing('_old_input.username')
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.client_id');
        $this->assertDatabaseCount('users', $count);
    }

    public function test_product_duplicate_reuses_the_photo_but_not_the_unique_sku(): void
    {
        $admin = $this->actor();
        $product = Product::factory()->create(['dealership_id' => Dealership::firstOrFail()->id, 'user_id' => $admin->id, 'product_type' => 'general', 'name' => 'Producto original', 'sku' => 'UNIQUE-SKU', 'image_path' => 'products/example.webp']);

        $this->actingAs($admin)->get(route('records.duplicate', ['type' => 'product', 'id' => $product->id]))->assertRedirect(route('products.create'))->assertSessionHasInput('duplicate_product_id', $product->id)->assertSessionMissing('_old_input.sku');
        $this->get(route('products.create'))->assertOk()->assertViewHas('product', fn ($draft) => ! $draft->exists && $draft->image_path === 'products/example.webp');
        $this->post(route('products.store'), ['product_type' => 'general', 'name' => 'Producto copia', 'cash_price' => '90.00', 'price' => '100.00', 'initial_amount' => '20.00', 'installment_count' => 5, 'active' => 1, 'dealership_id' => $product->dealership_id, 'duplicate_product_id' => $product->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Producto copia', 'image_path' => 'products/example.webp', 'sku' => null]);
    }

    public function test_posted_sales_and_receipts_edit_only_descriptive_fields_and_duplicate_without_money(): void
    {
        $owner = $this->actor('dealership_admin', Dealership::firstOrFail());
        $client = $this->client($owner);
        $order = $this->sale($owner, $client);
        $receipt = $this->receive($owner, $client);
        $this->actingAs($owner);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Solo lectura')->assertSee('data-record-status', false)->assertDontSee('action="'.route('orders.update', $order).'"', false);
        $this->get(route('orders.edit', $order))->assertOk()->assertSee('name="notes"', false);
        $this->get(route('receipts.show', $receipt))->assertOk()->assertSee('Solo lectura')->assertDontSee('name="order_id"', false);
        $this->get(route('receipts.edit', $receipt))->assertOk()->assertSee('name="reference"', false);

        $this->put(route('orders.update', $order), ['notes' => 'Nueva nota', 'total' => '0.01', 'client_id' => 999])->assertSessionHasNoErrors();
        $this->put(route('receipts.update', $receipt), ['reference' => 'REF-CORRECTED', 'notes' => 'Nota de pago', 'amount' => '0.01', 'client_id' => 999])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $order->fresh()->total);
        $this->assertSame('Nueva nota', $order->fresh()->notes);
        $this->assertSame('60.00', $receipt->fresh()->amount);
        $this->assertSame($client->id, $receipt->fresh()->client_id);
        $this->get(route('records.duplicate', ['type' => 'receipt', 'id' => $receipt->id]))->assertRedirect(route('receipts.create'))->assertSessionMissing('_old_input.reference')->assertSessionMissing('_old_input.token')->assertSessionMissing('_old_input.order_id');
        $this->get(route('records.duplicate', ['type' => 'order', 'id' => $order->id]))->assertRedirect(route('orders.create'))->assertSessionMissing('_old_input.token');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_deleting_a_receipt_voids_its_applications_and_restores_the_debt(): void
    {
        $owner = $this->actor('dealership_admin', Dealership::firstOrFail());
        $client = $this->client($owner);
        $order = $this->sale($owner, $client);
        $receipt = $this->receive($owner, $client);
        $this->approvePendingReceipts();
        app(ReceiptService::class)->apply($owner, $receipt, $order->id, ['token' => (string) Str::uuid(), 'amount' => '40.00']);
        $this->assertSame(6000, $order->fresh()->balance_cents);

        $this->actingAs($this->actor())->delete(route('records.destroy', ['type' => 'receipt', 'id' => $receipt->id]))->assertRedirect(route('receipts.show', $receipt))->assertSessionHasNoErrors();
        $this->assertNotNull($receipt->fresh()->voided_at);
        $this->assertSame(10000, $order->fresh()->balance_cents);
        $this->assertSame(0, $client->fresh()->credit_cents);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'void_receipt', 'entity' => 'receipt', 'entity_id' => $receipt->id]);
        $this->put(route('receipts.update', $receipt), ['reference' => 'OVERWRITE'])->assertUnprocessable();
        $this->assertSame('UNIQUE-REFERENCE', $receipt->fresh()->reference);
    }

    public function test_deletion_protects_related_clients_users_and_paid_sales_but_removes_an_unpaid_sale(): void
    {
        $admin = $this->actor();
        $owner = $this->actor('dealership_admin', Dealership::firstOrFail());
        $client = $this->client($owner);
        $unpaid = $this->sale($owner, $client);
        $paid = $this->sale($owner, $client);
        $receipt = $this->receive($owner, $client);
        $this->approvePendingReceipts();
        app(ReceiptService::class)->apply($owner, $receipt, $paid->id, ['token' => (string) Str::uuid(), 'amount' => '40.00']);
        $this->actingAs($admin);
        foreach ([['client', $client->id], ['order', $paid->id], ['user', $owner->id], ['user', $admin->id]] as [$type, $id]) {
            $this->delete(route('records.destroy', compact('type', 'id')))->assertSessionHasErrors('record');
        }
        $this->assertModelExists($client);
        $this->assertModelExists($paid);
        $this->assertModelExists($owner);
        $this->delete(route('records.destroy', ['type' => 'order', 'id' => $unpaid->id]))->assertRedirect(route('orders.index'));
        $this->assertModelMissing($unpaid);
        $this->assertDatabaseMissing('installment_schedules', ['order_id' => $unpaid->id]);
    }

    public function test_unreferenced_clients_users_and_companies_can_be_deleted_with_audit(): void
    {
        $admin = $this->actor();
        $owner = $this->actor('dealership_admin', Dealership::firstOrFail());
        $client = $this->client($owner);
        $emptyUser = $this->actor('dealership_admin', $owner->dealership);
        $company = Dealership::factory()->create();

        $this->actingAs($admin)->delete(route('records.destroy', ['type' => 'client', 'id' => $client->id]))->assertSessionHasNoErrors();
        $this->delete(route('records.destroy', ['type' => 'user', 'id' => $emptyUser->id]))->assertSessionHasNoErrors();
        $this->delete(route('records.destroy', ['type' => 'company', 'id' => $company->id]))->assertSessionHasNoErrors();
        $this->assertModelMissing($client);
        $this->assertModelMissing($emptyUser);
        $this->assertSoftDeleted($company);
        $this->get(route('dealerships.show', $company))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete', 'entity' => 'dealership', 'entity_id' => $company->id]);
    }

    public function test_head_office_cannot_manage_organizations_or_retail_accounts(): void
    {
        $hq = Dealership::factory()->create(['organization_type' => 'head_office']);
        $actor = $this->actor('head_office', $hq);
        $foreign = Dealership::factory()->create();
        $this->actingAs($actor)->get(route('dealerships.create'))->assertForbidden();
        $this->post(route('dealerships.store'), ['name' => 'Sucursal propia', 'code' => 'OWN-BRANCH', 'active' => 1, 'parent_id' => $foreign->id])->assertForbidden();
        $this->assertDatabaseMissing('dealerships', ['code' => 'OWN-BRANCH']);
        $this->get(route('dealerships.show', $foreign))->assertForbidden();
        $this->get(route('dealerships.edit', $foreign))->assertForbidden();
        $this->post(route('clients.store'), ['name' => 'No permitido', 'customer_kind' => 'person'])->assertSessionHasErrors('customer_kind');
        $this->get(route('users.create'))->assertOk()->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === ['dealership_admin']);
        $this->get(route('records.duplicate', ['type' => 'company', 'id' => $foreign->id]))->assertForbidden();
        $this->delete(route('records.destroy', ['type' => 'company', 'id' => $foreign->id]))->assertForbidden();
        $this->post(route('dealerships.store'), ['name' => 'Escalada', 'code' => 'BAD', 'organization_type' => 'head_office', 'active' => 1])->assertForbidden();
        $this->assertDatabaseMissing('dealerships', ['code' => 'BAD']);
    }

    public function test_record_actions_reject_foreign_records_and_read_only_customer_accounts(): void
    {
        $company = Dealership::firstOrFail();
        $owner = $this->actor('dealership_admin', $company);
        $client = $this->client($owner);
        $foreign = Product::factory()->create(['dealership_id' => Dealership::factory()->create()->id]);
        $this->actingAs($owner)->get(route('records.duplicate', ['type' => 'product', 'id' => $foreign->id]))->assertForbidden();
        $this->delete(route('records.destroy', ['type' => 'product', 'id' => $foreign->id]))->assertForbidden();
        $customer = $this->actor('customer', $company);
        $customer->update(['client_id' => $client->id]);
        $this->actingAs($customer)->get(route('records.duplicate', ['type' => 'client', 'id' => $client->id]))->assertForbidden();
        $this->delete(route('records.destroy', ['type' => 'client', 'id' => $client->id]))->assertForbidden();
        $this->get(route('portal.account'))->assertOk()->assertDontSee('Eliminar registro')->assertDontSee('Duplicar');
        $this->assertModelExists($client);
        $this->assertModelExists($foreign);
    }
}
