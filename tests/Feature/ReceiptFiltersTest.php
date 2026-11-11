<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setTime(12, 0));
    }

    private function receive(User $actor, Client $client, PaymentMethod $method, string $amount, string $reference): Receipt
    {
        return app(ReceiptService::class)->receive($actor, $client->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $method->id, 'reference' => $reference]);
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'head_office')->value('id')]);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $dealer = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $owner = User::factory()->create(['dealership_id' => $company->id, 'concessionaire_id' => $dealer->id, 'role_id' => Role::where('name', 'dealership_admin')->value('id')]);
        $client = Client::factory()->for($owner, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $dealer->id, 'name' => 'Cliente Principal', 'document' => 'DOCUMENTO-ALFA']);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia', 'active' => true]);
        $order = app(SalesService::class)->create($admin, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta de prueba', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '200.00']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);
        $pending = $this->receive($owner, $client, $method, '20.00', 'REF-PENDIENTE');
        $rejected = $this->receive($owner, $client, $method, '30.00', 'REF-RECHAZADO');
        $credit = $this->receive($admin, $client, $method, '100.00', 'REF-CREDITO');
        $partial = $this->receive($admin, $client, $method, '100.00', 'REF-PARCIAL');
        $applied = $this->receive($admin, $client, $method, '50.00', 'REF-APLICADO');
        $voided = $this->receive($admin, $client, $method, '60.00', 'REF-ANULADO');
        $old = $this->receive($admin, $client, $method, '70.00', 'REF-ANTERIOR');
        $old->update(['created_at' => now()->subMonthNoOverflow()]);
        $service = app(ReceiptService::class);
        $service->reject($head, $rejected, 'Comprobante incorrecto');
        $service->apply($admin, $partial, $order->id, ['token' => (string) Str::uuid(), 'amount' => '40.00']);
        $service->apply($admin, $applied, $order->id, ['token' => (string) Str::uuid(), 'amount' => '50.00']);
        $service->void($admin, $voided, 'Ingreso duplicado de prueba');

        return compact('head', 'admin', 'owner', 'client', 'dealer', 'method', 'order', 'pending', 'rejected', 'credit', 'partial', 'applied', 'voided', 'old');
    }

    public function test_all_payments_is_explicit_and_never_reverts_to_head_office_pending_default(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['head'])->get(route('receipts.index'))->assertOk()
            ->assertViewHas('receipts', fn ($rows) => $rows->pluck('id')->all() === [$data['pending']->id]);
        foreach ([$data['head'], $data['owner']] as $actor) {
            $this->actingAs($actor)->get(route('receipts.index', ['state' => 'all']))->assertOk()
                ->assertViewHas('receipts', fn ($rows) => $rows->total() === 6)
                ->assertSee('value="all" selected', false)
                ->assertViewHas('received', 25000)->assertViewHas('credit', 16000)->assertViewHas('pending', 2000);
        }
    }

    public function test_each_payment_state_matches_approval_and_actual_application_without_changing_balances(): void
    {
        $data = $this->portfolio();
        $expected = ['pending' => ['pending'], 'rejected' => ['rejected'], 'credit' => ['credit', 'partial'], 'partial' => ['partial'], 'applied' => ['applied'], 'void' => ['voided']];
        foreach ([$data['head'], $data['owner']] as $actor) {
            foreach ($expected as $state => $keys) {
                $ids = collect($keys)->map(fn (string $key) => $data[$key]->id)->sort()->values()->all();
                $this->actingAs($actor)->get(route('receipts.index', ['state' => $state]))->assertOk()
                    ->assertViewHas('receipts', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === $ids);
            }
        }
        $this->assertSame(11000, $data['order']->fresh()->balance_cents);
        $this->assertSame(23000, $data['client']->fresh()->credit_cents);
    }

    public function test_dates_search_client_and_dealer_filters_keep_organization_boundaries(): void
    {
        $data = $this->portfolio();
        $foreign = $this->portfolio();
        $this->actingAs($data['head']);
        foreach (['REF-PARCIAL', (string) $data['partial']->id, 'REC-'.str_pad($data['partial']->id, 6, '0', STR_PAD_LEFT), ' rec '.$data['partial']->id.' '] as $search) {
            $this->get(route('receipts.index', ['state' => 'all', 'q' => $search]))->assertOk()
                ->assertViewHas('receipts', fn ($rows) => $rows->pluck('id')->all() === [$data['partial']->id]);
        }
        foreach (['Cliente Principal', 'DOCUMENTO-ALFA'] as $search) {
            $this->get(route('receipts.index', ['state' => 'all', 'q' => $search, 'concessionaire_id' => $data['dealer']->id, 'client_id' => $data['client']->id]))->assertOk()
                ->assertViewHas('receipts', fn ($rows) => $rows->total() === 6);
        }
        $this->get(route('receipts.index', ['state' => 'all', 'client_id' => $foreign['client']->id]))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->isEmpty())->assertViewHas('received', 0);
        $this->get(route('receipts.index', ['state' => 'all', 'concessionaire_id' => $foreign['dealer']->id]))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->isEmpty());
        $this->get(route('receipts.index', ['state' => 'all', 'from' => '1900-01-01', 'to' => today()->toDateString(), 'date_mode' => 'custom']))->assertOk()
            ->assertViewHas('receipts', fn ($rows) => $rows->total() === 7)->assertViewHas('received', 32000)->assertViewHas('credit', 23000);
        $this->get(route('receipts.index', ['state' => 'incorrecto']))->assertSessionHasErrors('state');
        $this->get(route('receipts.index', ['state' => 'all', 'from' => today()->toDateString(), 'to' => today()->subDay()->toDateString()]))->assertSessionHasErrors('to');
    }

    public function test_all_filter_and_stable_order_are_preserved_between_pages(): void
    {
        $data = $this->portfolio();
        for ($number = 0; $number < 21; $number++) {
            $this->receive($data['admin'], $data['client'], $data['method'], '1.00', 'REF-PAGINACION');
        }
        $first = $this->actingAs($data['head'])->get(route('receipts.index', ['state' => 'all']))->assertOk()->viewData('receipts');
        $this->assertSame(27, $first->total());
        $this->assertStringContainsString('state=all', $first->nextPageUrl());
        $second = $this->get($first->nextPageUrl())->assertOk()->viewData('receipts');
        $this->assertCount(7, $second);
        $this->assertSame([], array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()));

    }
}
