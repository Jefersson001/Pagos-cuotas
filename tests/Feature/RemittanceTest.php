<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Remittance;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\RemittanceService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemittanceTest extends TestCase
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
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia', 'active' => true]);
        $clients = Client::factory()->count(2)->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id, 'whatsapp' => '584121234567']);
        $orders = $clients->map(fn (Client $client) => app(SalesService::class)->create($dealer, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta con remesa', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '400']], 'initial_amount' => '100', 'installment_count' => 3, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]));
        $receipts = $orders->map(fn (Order $order, int $index) => app(ReceiptService::class)->receive($dealer, $order->client_id, ['token' => (string) Str::uuid(), 'amount' => $index === 0 ? '80' : '100', 'payment_method_id' => $method->id, 'payment_date' => today()->toDateString(), 'collection_channel' => 'concessionaire', 'source_order_id' => $order->id, 'installment_id' => $order->installments()->where('number', 0)->value('id'), 'reference' => 'CLIENT-'.$order->id]));
        $values = ['token' => (string) Str::uuid(), 'concessionaire_id' => $branch->id, 'payment_method_id' => $method->id, 'receipt_ids' => $receipts->modelKeys(), 'amount' => '180', 'payment_date' => today()->toDateString(), 'reference' => (string) Str::uuid()];

        return compact('company', 'head', 'branch', 'dealer', 'method', 'clients', 'orders', 'receipts', 'values');
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->create('transferencia.pdf', 2, 'application/pdf');
    }

    /** @param array<string,mixed> $data */
    private function submit(array $data): Remittance
    {
        $this->actingAs($data['dealer'])->post(route('remittances.store'), $data['values'] + ['proof' => $this->proof()])->assertSessionHasNoErrors();

        return Remittance::where('token', $data['values']['token'])->firstOrFail();
    }

    /** @return array<int,int> */
    private function expected(Remittance $remittance): array
    {
        return app(RemittanceService::class)->preview($remittance)->map(fn (array $preview): int => $preview['applied_cents'])->all();
    }

    public function test_reported_balances_are_separate_and_batch_approval_confirms_every_original_destination_once(): void
    {
        $data = $this->portfolio();
        $report = $data['orders'][0]->fresh()->reportedSummary();
        $this->assertSame(40000, $data['orders'][0]->fresh()->balance_cents);
        $this->assertSame(8000, $report['reported_cents']);
        $this->assertSame(32000, $report['remaining_cents']);
        $this->assertSame(0, $data['receipts'][0]->fresh()->available_cents);
        $this->actingAs($data['dealer'])->get(route('orders.show', $data['orders'][0]))->assertOk()->assertSee('Cobros reportados pendientes')->assertSee('Abono reportado');
        $remittance = $this->submit($data);
        $this->assertDatabaseCount('receipts', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->get(route('remittances.show', $remittance))->assertOk()->assertSee('Esperando confirmación');
        $this->get(route('remittances.index'))->assertOk()->assertViewHas('heldCents', 0)->assertViewHas('sentCents', 18000);
        $this->actingAs($data['head'])->get(route('remittances.show', $remittance))->assertOk()->assertSee('Aprobar remesa');
        $expected = $this->expected($remittance);
        $this->post(route('remittances.approve', $remittance), ['expected_applied_cents' => $expected, 'approval_proof' => $this->proof()])->assertSessionHasNoErrors();
        $this->assertSame('approved', $remittance->fresh()->status);
        $this->assertSame(32000, $data['orders'][0]->fresh()->balance_cents);
        $this->assertSame(30000, $data['orders'][1]->fresh()->balance_cents);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('receipts', 2);
        $this->assertSame(0, $data['orders'][0]->fresh()->reportedSummary()['reported_cents']);
        $this->assertSame($remittance->fresh()->approval_proof_path, $data['receipts'][0]->fresh()->approval_proof_path);
        $this->post(route('remittances.approve', $remittance), ['expected_applied_cents' => $expected, 'approval_proof' => $this->proof()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles('remittance-proofs'));
        $this->get(route('remittances.proof', ['remittance' => $remittance, 'side' => 'approval']))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_rejection_releases_membership_but_preserves_reported_money_and_history_for_resubmission(): void
    {
        $data = $this->portfolio();
        $remittance = $this->submit($data);
        $this->actingAs($data['head'])->post(route('remittances.reject', $remittance), ['reason' => 'Comprobante bancario incorrecto'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $remittance->fresh()->status);
        foreach ($data['receipts'] as $receipt) {
            $this->assertNull($receipt->fresh()->remittance_id);
            $this->assertSame('pending', $receipt->fresh()->approval_status);
        }
        $this->assertSame(8000, $data['orders'][0]->fresh()->reportedSummary()['covered_cents']);
        $this->assertSame(40000, $data['orders'][0]->fresh()->balance_cents);
        $data['values']['token'] = (string) Str::uuid();
        $second = $this->submit($data);
        $this->assertNotSame($remittance->id, $second->id);
        $this->assertSame(2, $data['receipts'][0]->remittances()->count());
        $this->assertSame(2, $remittance->receipts()->count());
        $this->assertDatabaseCount('receipts', 2);
    }

    public function test_batch_preview_distributes_overlapping_reports_sequentially_and_preserves_surplus_as_credit(): void
    {
        $data = $this->portfolio();
        $original = $data['receipts'][0];
        $extra = $original->replicate(['token']);
        $extra->forceFill(['token' => (string) Str::uuid(), 'amount' => '100.00', 'intended_amount' => '100.00', 'collection_channel' => 'concessionaire'])->save();
        $data['values']['receipt_ids'][] = $extra->id;
        $data['values']['amount'] = '280';
        $remittance = $this->submit($data);
        $expected = $this->expected($remittance);
        $this->assertSame(2000, $expected[$extra->id]);
        $this->assertSame(20000, array_sum($expected));
        $this->actingAs($data['head'])->post(route('remittances.approve', $remittance), ['approval_proof' => $this->proof(), 'expected_applied_cents' => $expected])->assertSessionHasNoErrors();
        $this->assertSame(8000, $extra->fresh()->available_cents);
        $this->assertSame(30000, $data['orders'][0]->fresh()->balance_cents);
        $this->assertDatabaseCount('receipts', 3);
        $this->assertDatabaseCount('payments', 3);
    }

    public function test_unrelated_collected_money_becomes_credit_only_after_batch_approval(): void
    {
        $data = $this->portfolio();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        $extra = app(ReceiptService::class)->receive($admin, $data['clients'][0]->id, ['token' => (string) Str::uuid(), 'amount' => '50', 'payment_method_id' => $data['method']->id, 'collection_channel' => 'concessionaire']);
        $this->assertSame('pending', $extra->approval_status);
        $this->assertSame(0, $extra->available_cents);
        $data['values']['receipt_ids'][] = $extra->id;
        $data['values']['amount'] = '230';
        $remittance = $this->submit($data);
        $this->actingAs($data['head'])->post(route('remittances.approve', $remittance), ['approval_proof' => $this->proof(), 'expected_applied_cents' => $this->expected($remittance)])->assertSessionHasNoErrors();
        $this->assertSame(5000, $extra->fresh()->available_cents);
        $this->assertSame(32000, $data['orders'][0]->fresh()->balance_cents);
        $this->assertDatabaseCount('receipts', 3);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_wrong_totals_duplicate_membership_and_foreign_portfolios_are_rejected_without_files_or_new_income(): void
    {
        $data = $this->portfolio();
        $foreign = $this->portfolio();
        $this->actingAs($data['dealer'])->post(route('remittances.store'), array_replace($data['values'], ['amount' => '179', 'proof' => $this->proof()]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('remittances', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('remittance-proofs'));
        $this->post(route('remittances.store'), array_replace($data['values'], ['receipt_ids' => $foreign['receipts']->modelKeys(), 'proof' => $this->proof()]))->assertSessionHasErrors('receipt_ids');
        $remittance = $this->submit($data);
        $this->post(route('remittances.store'), $data['values'] + ['proof' => $this->proof()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remittances', 1);
        $this->post(route('remittances.store'), array_replace($data['values'], ['token' => (string) Str::uuid(), 'reference' => 'ANOTHER', 'proof' => $this->proof()]))->assertSessionHasErrors('receipt_ids');
        $this->actingAs($foreign['head'])->get(route('remittances.show', $remittance))->assertForbidden();
        $this->post(route('remittances.approve', $remittance), ['approval_proof' => $this->proof(), 'expected_applied_cents' => $this->expected($remittance)])->assertForbidden();
        $customer = User::factory()->create(['dealership_id' => $data['company']->id, 'concessionaire_id' => $data['branch']->id, 'client_id' => $data['clients'][0]->id, 'role_id' => Role::where('name', 'customer')->value('id')]);
        $this->actingAs($customer)->get(route('remittances.show', $remittance))->assertForbidden();
        $this->actingAs($data['head'])->get(route('remittances.create'))->assertForbidden();
    }

    public function test_stale_or_cancelled_sale_rolls_back_the_entire_batch_and_cleans_approval_proof(): void
    {
        $data = $this->portfolio();
        $remittance = $this->submit($data);
        $expected = $this->expected($remittance);
        $data['orders'][1]->update(['cancelled' => true]);
        $this->actingAs($data['head'])->post(route('remittances.approve', $remittance), ['approval_proof' => $this->proof(), 'expected_applied_cents' => $expected])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);
        foreach ($data['receipts'] as $receipt) {
            $this->assertSame('pending', $receipt->fresh()->approval_status);
            $this->assertNull($receipt->fresh()->approval_proof_path);
        }
        $this->assertNull($remittance->fresh()->approval_proof_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('remittance-proofs'));
    }

    public function test_collected_money_cannot_be_approved_or_voided_individually_inside_a_remittance_and_batch_void_restores_every_balance(): void
    {
        $data = $this->portfolio();
        $receipt = $data['receipts'][0];
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['approval_proof' => $this->proof()])->assertSessionHasErrors('amount');
        $this->post(route('receipts.reject', $receipt), ['reason' => 'Debe resolverse mediante remesa'])->assertSessionHasErrors('amount');
        $remittance = $this->submit($data);
        $this->actingAs($data['dealer'])->post(route('receipts.void', $receipt), ['reason' => 'No debe anularse individualmente'])->assertSessionHasErrors('amount');
        $this->actingAs($data['head'])->post(route('remittances.approve', $remittance), ['approval_proof' => $this->proof(), 'expected_applied_cents' => $this->expected($remittance)])->assertSessionHasNoErrors();
        $this->post(route('receipts.void', $receipt), ['reason' => 'No debe anularse individualmente'])->assertSessionHasErrors('amount');
        $this->post(route('remittances.void', $remittance), ['reason' => 'Transferencia duplicada de prueba'])->assertSessionHasNoErrors();
        $this->assertSame('void', $remittance->fresh()->status);
        foreach ($data['orders'] as $order) {
            $this->assertSame(40000, $order->fresh()->balance_cents);
            $this->assertSame(0, $order->fresh()->reportedSummary()['reported_cents']);
        }
        foreach ($data['receipts'] as $row) {
            $this->assertNotNull($row->fresh()->voided_at);
            $this->assertSame(0, $row->fresh()->available_cents);
        }
    }

    public function test_a_method_used_only_for_a_remittance_cannot_be_deleted(): void
    {
        $data = $this->portfolio();
        $transferMethod = PaymentMethod::create(['dealership_id' => $data['company']->id, 'name' => 'Banco remesas', 'active' => true]);
        $data['values']['payment_method_id'] = $transferMethod->id;
        $this->submit($data);
        $this->actingAs($data['head'])->delete(route('payment-methods.destroy', $transferMethod))->assertSessionHasErrors('method');
        $this->assertModelExists($transferMethod);
    }

    public function test_overlapping_reports_do_not_reduce_collectible_balance_twice_and_reminders_exclude_covered_commitments(): void
    {
        $data = $this->portfolio();
        $original = $data['receipts'][0];
        $extra = $original->replicate(['token']);
        $extra->forceFill(['token' => (string) Str::uuid(), 'amount' => '100.00', 'intended_amount' => '100.00', 'collection_channel' => 'head_office'])->save();
        $summary = $data['orders'][0]->fresh()->reportedSummary();
        $this->assertSame(18000, $summary['reported_cents']);
        $this->assertSame(10000, $summary['covered_cents']);
        $this->assertSame(30000, $summary['remaining_cents']);
        $this->actingAs($data['dealer'])->get(route('collections', ['filter' => 'reported', 'client' => $data['clients'][0]->name]))->assertOk()->assertSee('Ver / Validar')->assertDontSee('data-message=', false);
        $customer = User::factory()->create(['dealership_id' => $data['company']->id, 'concessionaire_id' => $data['branch']->id, 'client_id' => $data['clients'][0]->id, 'role_id' => Role::where('name', 'customer')->value('id')]);
        $this->actingAs($customer)->get(route('portal.order', $data['orders'][0]))->assertOk()->assertSee('Restante por cobrar considerando reportes')->assertSee('180.00')->assertSee('300.00');
    }
}
