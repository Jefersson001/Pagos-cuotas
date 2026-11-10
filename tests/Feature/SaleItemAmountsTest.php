<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleItemAmountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string, mixed> */
    private function saleData(): array
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $this->actingAs($admin);
        $client = Client::factory()->for($admin, 'owner')->create();
        $product = Product::factory()->for($admin, 'owner')->create(['dealership_id' => $client->dealership_id, 'price' => '100.00', 'initial_amount' => '20.00']);

        return ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'items' => [['product_id' => $product->id, 'catalog_version' => $product->catalog_version, 'quantity' => 2, 'use_custom_amounts' => '1', 'unit_price' => '80.25', 'initial_amount' => '10.50']], 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => today()->addMonth()->toDateString()];
    }

    public function test_edited_amounts_are_saved_only_on_the_sale_and_schedule_balances_exactly(): void
    {
        $data = $this->saleData();
        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('160.50', $order->total);
        $this->assertSame('21.00', $order->initial_amount);
        $this->assertSame(8025, Money::cents($order->items->first()->unit_price));
        $this->assertSame(1050, Money::cents($order->items->first()->initial_amount));
        $this->assertSame(13950, $order->installments->where('number', '>', 0)->sum(fn ($row) => Money::cents($row->amount)));
        $product = Product::findOrFail($data['items'][0]['product_id']);
        $this->assertSame('100.00', $product->price);
        $this->assertSame('20.00', $product->initial_amount);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_or_missing_edited_amounts_cannot_create_a_sale(): void
    {
        $data = $this->saleData();
        foreach ([['0', '0'], ['10', '11'], ['-1', '0'], ['10.001', '0'], [null, '0'], ['10', null]] as [$price, $initial]) {
            $data['items'][0]['unit_price'] = $price;
            $data['items'][0]['initial_amount'] = $initial;
            $this->post(route('orders.store'), $data)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cash_products_still_require_the_entire_edited_price_up_front(): void
    {
        $data = $this->saleData();
        $product = Product::findOrFail($data['items'][0]['product_id']);
        $product->update(['pay_in_installments' => false]);
        $data['items'][0]['catalog_version'] = $product->catalog_version;
        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('0.00', $order->initial_amount);
        $this->assertSame(0, Money::cents($order->items->first()->initial_amount));
        $this->assertSame('160.50', $order->installments()->whereNotNull('order_item_id')->firstOrFail()->amount);
        $this->assertSame(0, $order->installments()->where('number', '>', 0)->count());
    }

    public function test_validation_preserves_edits_and_stale_catalog_versions_are_rejected(): void
    {
        $data = $this->saleData();
        $data['frequency'] = 'invalid';
        $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('frequency');
        $this->get(route('orders.create'))->assertOk()->assertViewHas('selectedItems', fn ($items) => $items[0]['unit_price'] === '80.25' && $items[0]['initial_amount'] === '10.50' && $items[0]['use_custom_amounts'] && $items[0]['isEditing']);
        Product::findOrFail($data['items'][0]['product_id'])->update(['price' => '110']);
        $data['frequency'] = 'monthly';
        $this->post(route('orders.store'), $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
    }
}
