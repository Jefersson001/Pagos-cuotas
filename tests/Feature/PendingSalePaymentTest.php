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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingSalePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    /** @return array<string,mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $branch = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Banco', 'active' => true]);
        $order = app(SalesService::class)->create($dealer, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta de prueba', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '400']], 'initial_amount' => '100', 'installment_count' => 3, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);

        return compact('head', 'dealer', 'client', 'method', 'order');
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function payment(array $data, array $changes = []): array
    {
        return array_replace(['token' => (string) Str::uuid(), 'payment_source' => 'new', 'amount' => '400', 'payment_date' => today()->toDateString(), 'payment_method_id' => $data['method']->id, 'reference' => (string) Str::uuid(), 'proof' => UploadedFile::fake()->create('comprobante.pdf', 1, 'application/pdf')], $changes);
    }

    public function test_total_reports_block_a_second_total_even_with_a_new_token_or_another_collection_channel(): void
    {
        foreach (['head_office', 'concessionaire'] as $channel) {
            $data = $this->portfolio();
            $values = $this->payment($data, ['collection_channel' => $channel]);
            $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), $values)->assertSessionHasNoErrors();
            $count = Receipt::count();
            $this->post(route('orders.pay', $data['order']), $this->payment($data, ['allow_credit' => 1, 'collection_channel' => $channel === 'head_office' ? 'concessionaire' : 'head_office']))->assertSessionHasErrors('amount');
            $this->assertDatabaseCount('receipts', $count);
            $this->assertSame(40000, $data['order']->fresh()->balance_cents);
            $this->assertSame(0, $data['order']->fresh()->unreportedBalanceCents());
            $this->get(route('orders.show', $data['order']))->assertOk()->assertSee('El saldo completo ya está reportado.')->assertDontSee('id="payment-dialog"', false);
        }
    }

    public function test_partial_reports_reserve_only_their_amount_and_an_automatic_payment_cannot_repeat_a_targeted_quota(): void
    {
        $data = $this->portfolio();
        $initial = $data['order']->installments()->where('number', 0)->firstOrFail();
        $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '40', 'installment_id' => $initial->id]))->assertSessionHasNoErrors();
        $this->assertSame(6000, $data['order']->fresh()->unreportedBalanceCents($initial->id));
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '61', 'installment_id' => $initial->id, 'allow_credit' => 1]))->assertSessionHasErrors('amount');
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '40', 'installment_id' => $initial->id]))->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '20', 'installment_id' => $initial->id]))->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '100', 'installment_id' => $initial->id]))->assertSessionHasErrors('amount');
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '301']))->assertSessionHasErrors('amount');
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '300']))->assertSessionHasNoErrors();
        $this->assertSame(0, $data['order']->fresh()->unreportedBalanceCents());
        $this->assertDatabaseCount('receipts', 4);
        $this->assertDatabaseCount('payments', 0);
        $this->assertCount(4, Storage::disk('local')->allFiles('receipt-proofs'));
    }

    public function test_automatic_reports_reserve_installments_and_rejection_releases_them(): void
    {
        $data = $this->portfolio();
        $initial = $data['order']->installments()->where('number', 0)->firstOrFail();
        $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '150']))->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '100', 'installment_id' => $initial->id]))->assertSessionHasErrors('amount');
        $receipt = Receipt::firstOrFail();
        $this->actingAs($data['head'])->post(route('receipts.reject', $receipt), ['reason' => 'El pago reportado era incorrecto'])->assertSessionHasNoErrors();
        $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '100', 'installment_id' => $initial->id]))->assertSessionHasNoErrors();
    }

    public function test_the_same_partial_payment_reference_cannot_be_reported_again_but_another_payment_can(): void
    {
        $data = $this->portfolio();
        $values = $this->payment($data, ['amount' => '50']);
        $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), $values)->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $data['order']), array_replace($values, ['token' => (string) Str::uuid(), 'proof' => UploadedFile::fake()->create('otro.pdf', 1, 'application/pdf')]))->assertSessionHasErrors('amount');
        $this->post(route('orders.pay', $data['order']), $this->payment($data, ['amount' => '50']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('receipts', 2);
        $this->assertSame(30000, $data['order']->fresh()->unreportedBalanceCents());
        $this->actingAs($data['head'])->post(route('receipts.approve', Receipt::where('token', $values['token'])->firstOrFail()), ['approval_proof' => UploadedFile::fake()->create('banco.pdf', 1, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), array_replace($values, ['token' => (string) Str::uuid(), 'proof' => UploadedFile::fake()->create('otro.pdf', 1, 'application/pdf')]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('receipts', 2);
    }

    public function test_existing_credit_cannot_cover_a_balance_already_reserved_by_a_pending_payment(): void
    {
        $data = $this->portfolio();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $credit = app(ReceiptService::class)->receive($admin, $data['client']->id, $this->payment($data, ['amount' => '400']));
        $this->actingAs($data['dealer'])->post(route('orders.pay', $data['order']), $this->payment($data, ['new_payment' => 1]))->assertSessionHasNoErrors();
        $this->post(route('orders.pay', $data['order']), ['token' => (string) Str::uuid(), 'payment_source' => 'credit', 'amount' => '400'])->assertSessionHasErrors('amount');
        $this->assertSame(40000, $credit->fresh()->available_cents);
        $this->assertDatabaseCount('payments', 0);
    }
}
