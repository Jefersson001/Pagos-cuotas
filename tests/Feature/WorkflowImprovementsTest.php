<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'head_office')->value('id')]);
        $contact = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'dealership_admin')->value('id'), 'concessionaire_id' => $contact->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $contact->id]);
        $customer = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'customer')->value('id'), 'client_id' => $client->id, 'concessionaire_id' => $contact->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Caja', 'active' => true]);
        $order = app(SalesService::class)->create($dealer, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta de prueba', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '120.00']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => today()->addMonth()->toDateString()]);

        return compact('company', 'head', 'contact', 'dealer', 'client', 'customer', 'method', 'order');
    }

    private function receive(array $data, string $amount, bool $related = false, ?string $reference = null): Receipt
    {
        return app(ReceiptService::class)->receive($data['dealer'], $data['client']->id, ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $data['method']->id, 'source_order_id' => $related ? $data['order']->id : null, 'reference' => $reference]);
    }

    public function test_cash_and_installment_products_have_one_canonical_price_and_retain_general_information(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->post(route('products.store'), ['name' => 'Servicio contado', 'pay_in_installments' => 0, 'cash_price' => '75.50', 'description' => 'Datos generales', 'active' => 0])->assertSessionHasNoErrors();
        $cash = Product::where('name', 'Servicio contado')->firstOrFail();
        $this->assertSame('75.50', $cash->price);
        $this->assertSame($cash->price, $cash->cash_price);
        $this->assertSame('Datos generales', $cash->description);
        $this->assertFalse($cash->active);
        $this->post(route('products.store'), ['name' => 'Cuotas', 'pay_in_installments' => 1, 'price' => '100.00', 'cash_price' => '0.01', 'initial_amount' => '20.00', 'installment_count' => 4])->assertSessionHasNoErrors();
        $this->assertSame('100.00', Product::where('name', 'Cuotas')->firstOrFail()->cash_price);
    }

    public function test_client_confirmed_total_excludes_pending_and_rejected_payments_and_hides_invalid_edit_links(): void
    {
        $data = $this->portfolio();
        $pending = $this->receive($data, '50.00');
        $rejected = $this->receive($data, '20.00');
        $approved = $this->receive($data, '30.00');
        app(ReceiptService::class)->reject($data['head'], $rejected, 'Comprobante incorrecto');
        app(ReceiptService::class)->approve($data['head'], $approved);
        $this->actingAs($data['dealer'])->get(route('clients.show', $data['client']))->assertOk()
            ->assertSee('<small>Pagos recibidos confirmados</small><strong>$30.00</strong>', false)
            ->assertSee(route('receipts.edit', $pending), false)->assertDontSee(route('receipts.edit', $approved), false)->assertDontSee(route('receipts.edit', $rejected), false);
    }

    public function test_private_proof_is_scoped_and_cannot_be_replaced_after_approval(): void
    {
        Storage::fake('local');
        $data = $this->portfolio();
        $receipt = $this->receive($data, '40.00', true);
        $this->actingAs($data['dealer'])->post(route('receipts.proof.store', $receipt), ['proof' => UploadedFile::fake()->create('pago.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $path = $receipt->fresh()->proof_path;
        Storage::disk('local')->assertExists($path);
        $this->actingAs($data['head'])->get(route('receipts.proof', $receipt))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($data['customer'])->get(route('portal.receipt-proof', $receipt))->assertOk();
        $this->post(route('receipts.proof.store', $receipt), ['proof' => UploadedFile::fake()->create('otro.pdf', 20, 'application/pdf')])->assertForbidden();
        $foreign = $this->portfolio();
        $this->actingAs($foreign['head'])->get(route('receipts.proof', $receipt))->assertForbidden();
        $this->actingAs($foreign['customer'])->get(route('portal.receipt-proof', $receipt))->assertForbidden();
        $this->actingAs($data['dealer'])->post(route('receipts.proof.store', $receipt), ['proof' => UploadedFile::fake()->create('ejecutable.php', 1, 'application/x-httpd-php')])->assertSessionHasErrors('proof');
        app(ReceiptService::class)->approve($data['head'], $receipt);
        $this->post(route('receipts.proof.store', $receipt), ['proof' => UploadedFile::fake()->create('nuevo.pdf', 20, 'application/pdf')])->assertForbidden();
        $this->assertSame($path, $receipt->fresh()->proof_path);
    }

    public function test_approval_detects_changed_distribution_and_warns_about_duplicate_references(): void
    {
        $data = $this->portfolio();
        $receipt = $this->receive($data, '40.00', true, 'REF-IGUAL');
        $other = $this->receive($data, '100.00', false, 'REF-IGUAL');
        $service = app(ReceiptService::class);
        $service->approve($data['head'], $other);
        $service->apply($data['dealer'], $other->fresh(), $data['order']->id, ['token' => (string) Str::uuid(), 'amount' => '100.00']);
        $this->actingAs($data['head'])->get(route('receipts.show', $receipt))->assertOk()->assertViewHas('duplicateReference', true)->assertViewHas('approvalPreview', fn ($preview) => $preview['applied_cents'] === 2000 && $preview['credit_cents'] === 2000 && $preview['changed']);
        $this->post(route('receipts.approve', $receipt), ['expected_applied_cents' => 4000])->assertSessionHasErrors('amount');
        $this->assertSame('pending', $receipt->fresh()->approval_status);
        $this->post(route('receipts.approve', $receipt), ['expected_applied_cents' => 2000])->assertSessionHasNoErrors();
        $this->assertSame(0, $data['order']->fresh()->balance_cents);
        $this->assertSame(2000, $data['client']->fresh()->credit_cents);
    }

    public function test_operating_contacts_cannot_change_type_or_move_their_history_to_another_dealer(): void
    {
        $data = $this->portfolio();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $other = Client::factory()->concessionaire()->create(['dealership_id' => $data['company']->id]);
        $this->actingAs($admin)->put(route('clients.update', $data['client']), ['name' => $data['client']->name, 'customer_kind' => 'dealership'])->assertSessionHasErrors('customer_kind');
        $this->put(route('clients.update', $data['client']), ['name' => $data['client']->name, 'customer_kind' => 'person', 'concessionaire_id' => $other->id])->assertSessionHasErrors('concessionaire_id');
        $this->assertSame($data['contact']->id, $data['client']->fresh()->concessionaire_id);
    }
}
