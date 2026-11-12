<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    /** @return array{head: User, dealer: User, client: Client, method: PaymentMethod, freight: Product, product: Product, order: Order} */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'head_office')->value('id')]);
        $branch = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id, 'role_id' => Role::where('name', 'dealership_admin')->value('id')]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia', 'active' => true]);
        $product = Product::factory()->create(['dealership_id' => $company->id, 'price' => '400', 'initial_amount' => '100', 'installment_count' => 3, 'uses_freight' => true]);
        $freight = Product::factory()->create(['dealership_id' => $company->id, 'product_type' => 'service', 'name' => 'Transporte de vehículo', 'price' => '50', 'initial_amount' => '50', 'is_freight' => true, 'pay_in_installments' => false]);
        $order = app(SalesService::class)->create($dealer, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'installment_count' => 3, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);

        return compact('head', 'dealer', 'client', 'method', 'freight', 'product', 'order');
    }

    /** @param array<string, mixed> $data */
    private function payInstallment(array $data, int $number, bool $approve = true, string $amount = '100.00'): Receipt
    {
        $installment = $data['order']->installments()->where('number', $number)->firstOrFail();
        $receipt = app(ReceiptService::class)->receive($data['dealer'], $data['client']->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $data['method']->id, 'payment_date' => today()->toDateString(), 'source_order_id' => $data['order']->id, 'installment_id' => $installment->id]);
        if ($approve) {
            $this->approve($data['head'], $receipt);
        }

        return $receipt->fresh();
    }

    private function approve(User $head, Receipt $receipt): void
    {
        $this->actingAs($head)->post(route('receipts.approve', $receipt), ['approval_proof' => UploadedFile::fake()->create('ingreso.pdf', 2, 'application/pdf')])->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $data */
    private function ready(array $data): void
    {
        foreach ([0, 1, 2] as $number) {
            $this->payInstallment($data, $number);
        }
    }

    public function test_service_flag_is_saved_only_for_services_and_freight_is_excluded_from_initial_sale_selection(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->post(route('products.store'), ['product_type' => 'service', 'name' => 'Flete especial', 'price' => '90', 'is_freight' => 1, 'pay_in_installments' => 1])->assertSessionHasNoErrors();
        $created = Product::where('name', 'Flete especial')->firstOrFail();
        $this->assertTrue($created->is_freight);
        $this->assertFalse($created->pay_in_installments);
        $this->put(route('products.update', $created), ['product_type' => 'general', 'name' => 'Producto común', 'price' => '90', 'is_freight' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($created->fresh()->is_freight);
        $this->actingAs($data['dealer'])->getJson(route('products.options', ['client_id' => $data['client']->id]))->assertOk()->assertJsonMissing(['id' => $data['freight']->id])
            ->assertJsonFragment(['id' => $data['product']->id, 'catalog_version' => $data['product']->fresh()->catalog_version]);
        $this->post(route('orders.store'), ['token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'items' => [['product_id' => $data['freight']->id, 'quantity' => 1]], 'installment_count' => 1, 'frequency' => 'monthly', 'first_due' => today()->toDateString()])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_uses_freight_is_allowed_for_general_products_and_vehicles_but_not_services(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head']);
        foreach (['general', 'vehicle', 'service'] as $type) {
            $this->post(route('products.store'), ['product_type' => $type, 'name' => 'Prueba '.$type, 'brand' => 'Marca', 'model' => 'Modelo', 'price' => '100', 'uses_freight' => 1])->assertSessionHasNoErrors();
            $product = Product::latest('id')->firstOrFail();
            $this->assertSame($type !== 'service', $product->uses_freight);
            if ($type === 'general') {
                $this->put(route('products.update', $product), ['product_type' => 'general', 'name' => 'Sin flete', 'price' => '100'])->assertSessionHasNoErrors();
                $this->assertFalse($product->fresh()->uses_freight);
            }
        }
    }

    public function test_mixed_sale_requires_at_least_one_qualifying_product_and_checks_the_current_catalog_flag(): void
    {
        $data = $this->portfolio();
        $other = Product::factory()->create(['dealership_id' => $data['product']->dealership_id, 'price' => '25', 'initial_amount' => '25', 'pay_in_installments' => false, 'uses_freight' => false]);
        $data['order'] = app(SalesService::class)->create($data['dealer'], ['token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'items' => [['product_id' => $data['product']->id, 'quantity' => 1], ['product_id' => $other->id, 'quantity' => 1]], 'installment_count' => 3, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);
        $this->ready($data);
        $data['product']->update(['uses_freight' => false]);
        $this->assertFalse($data['order']->fresh()->canAddFreight());
        $this->actingAs($data['dealer'])->get(route('orders.show', $data['order']))->assertOk()->assertDontSee('name="freight_product_id"', false);
        $this->post(route('orders.freight.store', $data['order']), ['freight_product_id' => $data['freight']->id, 'freight_amount' => '50'])->assertSessionHasErrors('freight_product_id');
        $data['product']->update(['uses_freight' => true]);
        $this->assertTrue($data['order']->fresh()->canAddFreight());
        $this->get(route('orders.show', $data['order']))->assertOk()->assertSee('name="freight_product_id"', false);
        $data['product']->update(['product_type' => 'service']);
        $this->assertFalse($data['order']->fresh()->canAddFreight());
        $data['product']->update(['product_type' => 'vehicle']);
        $this->assertTrue($data['order']->fresh()->canAddFreight());
    }

    public function test_penultimate_installment_must_be_fully_paid_with_approved_payments(): void
    {
        $data = $this->portfolio();
        $values = ['freight_product_id' => $data['freight']->id, 'freight_amount' => '75.55'];
        $this->actingAs($data['dealer'])->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasErrors('freight_product_id');
        $this->payInstallment($data, 2, true, '50.00');
        $this->assertFalse($data['order']->fresh()->canAddFreight());
        $pending = $this->payInstallment($data, 2, false, '50.00');
        $this->assertFalse($data['order']->fresh()->canAddFreight());
        $this->actingAs($data['dealer'])->get(route('orders.show', $data['order']))->assertOk()->assertDontSee('name="freight_product_id"', false);
        $this->approve($data['head'], $pending);
        $this->assertTrue($data['order']->fresh()->canAddFreight());
        $this->actingAs($data['dealer'])->get(route('orders.show', $data['order']))->assertOk()->assertSee('name="freight_product_id"', false);
        app(ReceiptService::class)->void($data['head'], $pending, 'Ingreso incorrecto para verificar la habilitación');
        $this->assertFalse($data['order']->fresh()->canAddFreight());
        $this->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasErrors('freight_product_id');
    }

    public function test_freight_preserves_agreed_price_and_can_be_paid_with_the_last_installment_after_approval(): void
    {
        $data = $this->portfolio();
        $this->ready($data);
        $regularAmounts = $data['order']->installments()->whereNull('order_item_id')->pluck('amount', 'id')->all();
        $values = ['freight_product_id' => $data['freight']->id, 'freight_amount' => '75.55'];
        $this->actingAs($data['dealer'])->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasNoErrors();
        $order = $data['order']->fresh();
        $item = $order->items->firstWhere('is_freight', true);
        $freightSchedule = $order->installments->firstWhere('order_item_id', $item->id);
        $this->assertSame(47555, Money::cents($order->total));
        $this->assertSame(17555, $order->balance_cents);
        $this->assertSame($regularAmounts, $order->installments()->whereNull('order_item_id')->pluck('amount', 'id')->all());
        $this->assertSame(7555, Money::cents($freightSchedule->amount));
        $this->assertSame('Flete: Transporte de vehículo', $freightSchedule->label);
        $this->assertTrue($freightSchedule->due_date->equalTo($order->installments->firstWhere('number', 3)->due_date));
        $this->assertSame($freightSchedule->id, $order->installments->last()->id);
        $this->assertSame(47555, $order->installments->sum(fn (InstallmentSchedule $row): int => Money::cents($row->amount)));
        $data['freight']->update(['name' => 'Otro nombre', 'price' => '999', 'is_freight' => false]);
        $this->assertSame('Transporte de vehículo', $item->fresh()->description);
        $this->assertSame(7555, Money::cents($item->fresh()->unit_price));
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Flete: Transporte de vehículo')->assertDontSee('name="freight_product_id"', false);
        $receipt = app(ReceiptService::class)->receive($data['dealer'], $data['client']->id, ['token' => (string) Str::uuid(), 'amount' => '175.55', 'payment_method_id' => $data['method']->id, 'source_order_id' => $order->id, 'payment_date' => today()->toDateString()]);
        $this->assertSame(17555, $order->fresh()->balance_cents);
        $this->approve($data['head'], $receipt);
        $this->assertSame(0, $order->fresh()->balance_cents);
        $this->assertSame(0, $freightSchedule->fresh()->remaining_cents);
        $this->assertSame(0, $order->installments()->where('number', 3)->first()->remaining_cents);
        $this->assertSame(0, $receipt->fresh()->available_cents);
    }

    public function test_retries_do_not_duplicate_freight_and_invalid_products_amounts_and_roles_are_rejected(): void
    {
        $data = $this->portfolio();
        $this->ready($data);
        $values = ['freight_product_id' => $data['freight']->id, 'freight_amount' => '75.55'];
        $this->actingAs($data['head'])->post(route('orders.freight.store', $data['order']), $values)->assertForbidden();
        $foreign = $this->portfolio();
        $this->actingAs($foreign['dealer'])->post(route('orders.freight.store', $data['order']), $values)->assertForbidden();
        $this->actingAs($data['dealer']);
        foreach ([$foreign['freight']->id, $data['order']->items->first()->product_id] as $productId) {
            $this->post(route('orders.freight.store', $data['order']), array_replace($values, ['freight_product_id' => $productId]))->assertSessionHasErrors('freight_product_id');
        }
        foreach (['0', '-1', '1.234', '9999999999.99'] as $amount) {
            $this->post(route('orders.freight.store', $data['order']), array_replace($values, ['freight_amount' => $amount]))->assertSessionHasErrors('freight_amount');
        }
        $data['freight']->update(['active' => false]);
        $this->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasErrors('freight_product_id');
        $data['freight']->update(['active' => true]);
        $data['order']->update(['cancelled' => true]);
        $this->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasErrors('freight_product_id');
        $data['order']->update(['cancelled' => false]);
        $this->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasNoErrors();
        $this->post(route('orders.freight.store', $data['order']), $values)->assertSessionHasNoErrors();
        $this->post(route('orders.freight.store', $data['order']), array_replace($values, ['freight_amount' => '80']))->assertSessionHasErrors('freight_product_id');
        $this->assertSame(1, $data['order']->items()->where('is_freight', true)->count());
        $this->assertSame(5, $data['order']->installments()->count());
        $this->assertSame(47555, Money::cents($data['order']->fresh()->total));
    }
}
