<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
        $dealer = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $owner = User::factory()->create(['role_id' => Role::where('name', 'dealership_admin')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $dealer->id]);
        $client = Client::factory()->for($owner, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $dealer->id]);
        $customer = User::factory()->create(['role_id' => Role::where('name', 'customer')->value('id'), 'dealership_id' => $company->id, 'client_id' => $client->id, 'concessionaire_id' => $dealer->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Caja', 'active' => true]);
        $order = app(SalesService::class)->create($owner, ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'description' => 'Venta por aprobar', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '400.00']], 'initial_amount' => '100.00', 'installment_count' => 3, 'frequency' => 'monthly', 'first_due' => today()->toDateString()]);

        return compact('company', 'head', 'dealer', 'owner', 'client', 'customer', 'method', 'order');
    }

    /** @return array<string, int|string> */
    private function payment(array $data, string $amount = '150.00'): array
    {
        return ['payment_date' => today()->toDateString(), 'token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'payment_method_id' => $data['method']->id, 'amount' => $amount];
    }

    public function test_portfolio_shortcuts_filter_accounts_without_exposing_other_concessionaires(): void
    {
        $data = $this->portfolio();
        $this->portfolio();
        $empty = Client::factory()->for($data['owner'], 'owner')->create(['dealership_id' => $data['company']->id, 'concessionaire_id' => $data['dealer']->id]);
        $data['order']->installments()->where('number', 0)->update(['due_date' => today()->subDay()]);
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();

        $this->actingAs($data['owner']);
        foreach (['debt', 'overdue', 'credit', 'due_soon', 'received', 'sales'] as $filter) {
            $this->get(route('clients.index', ['account_state' => $filter, 'customer_kind' => 'person']))->assertOk()
                ->assertViewHas('clients', fn ($clients) => $clients->modelKeys() === [$data['client']->id]);
        }
        $this->get(route('clients.index', ['account_state' => 'credit', 'q' => $empty->name]))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->isEmpty());
        $this->get(route('clients.index', ['account_state' => 'unknown']))->assertSessionHasErrors('account_state');
        $this->get(route('workspace.customers'))->assertOk()
            ->assertSee(route('clients.index', ['customer_kind' => 'person', 'account_state' => 'overdue']));
        $this->actingAs($data['customer'])->get(route('clients.index', ['account_state' => 'credit']))->assertForbidden();
        $this->actingAs($data['head'])->get(route('clients.index', ['account_state' => 'credit']))->assertOk()
            ->assertViewHas('clients', fn ($clients) => $clients->modelKeys() === [$data['dealer']->id]);
    }

    public function test_head_office_home_keeps_scoped_pending_count_without_summary_panels(): void
    {
        $data = $this->portfolio();
        $foreign = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $this->actingAs($foreign['owner'])->post(route('receipts.store'), $this->payment($foreign))->assertSessionHasNoErrors();

        $this->actingAs($data['head'])->get(route('home'))->assertOk()
            ->assertViewHas('pendingPayments', 1)
            ->assertSee('Revisar pagos')
            ->assertDontSee('Pagos por revisar')->assertDontSee('Cartera por concesionario');
        $this->actingAs($data['owner'])->get(route('home'))->assertOk()
            ->assertSee('Aplicar saldo disponible')->assertDontSee('Cartera por concesionario');
    }

    public function test_sale_payment_waits_for_approval_and_is_applied_exactly_once(): void
    {
        $data = $this->portfolio();
        $values = $this->payment($data);
        $this->actingAs($data['owner'])->post(route('orders.pay', $data['order']), $values)->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->assertSame('pending', $receipt->approval_status);
        $this->assertSame($data['order']->id, $receipt->source_order_id);
        $this->assertSame(0, $data['client']->fresh()->credit_cents);
        $this->assertSame(40000, $data['order']->fresh()->balance_cents);
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('orders.pay', $data['order']), $values)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('receipts', 1);
        $this->post(route('orders.pay', $data['order']), array_replace($values, ['amount' => '151.00']))->assertSessionHasErrors('amount');
        $this->get(route('orders.show', $data['order']))->assertOk()->assertSee('Pagos pendientes de aprobación');
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertForbidden();
        $this->post(route('receipts.apply', $receipt), ['payment_date' => today()->toDateString(), 'token' => (string) Str::uuid(), 'order_id' => $data['order']->id, 'amount' => '10.00'])->assertSessionHasErrors('amount');
        $this->actingAs($data['head'])->get(route('receipts.show', $receipt))->assertOk()->assertSee('Aprobar pago')->assertSee('Venta relacionada:')->assertDontSee('Registrar pago');
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(25000, $data['order']->fresh()->balance_cents);
        $this->assertSame(0, $data['order']->fresh()->installments->firstWhere('number', 0)->remaining_cents);
        $this->assertSame($data['head']->id, $receipt->fresh()->approved_by);
        $this->assertNotNull($receipt->fresh()->approved_at);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $receipt->id, 'action' => 'approve_receipt']);
    }

    public function test_unrelated_money_becomes_credit_only_after_approval_and_can_be_used_without_another_approval(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->assertSame(0, $receipt->available_cents);
        $this->actingAs($data['customer'])->get(route('portal.receipt', $receipt))->assertOk()->assertSee('pendiente de aprobación')->assertDontSee('Aprobar pago');
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertForbidden();
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertSame(15000, $data['client']->fresh()->credit_cents);
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($data['owner'])->post(route('orders.pay', $data['order']), ['payment_date' => today()->toDateString(), 'token' => (string) Str::uuid(), 'payment_source' => 'credit', 'amount' => '125.00'])->assertSessionHasNoErrors();
        $this->assertSame(2500, $data['client']->fresh()->credit_cents);
        $this->assertSame(27500, $data['order']->fresh()->balance_cents);
        $this->assertDatabaseCount('receipts', 1);
        $this->put(route('receipts.update', $receipt), ['reference' => 'CAMBIO'])->assertForbidden();
        $this->post(route('receipts.void', $receipt), ['reason' => 'No debe anular aprobado'])->assertForbidden();
        $this->actingAs($data['head'])->post(route('receipts.void', $receipt), ['reason' => 'Ingreso duplicado confirmado'])->assertSessionHasNoErrors();
        $this->assertSame(40000, $data['order']->fresh()->balance_cents);
        $this->assertSame(0, $data['client']->fresh()->credit_cents);
    }

    public function test_rejection_keeps_reason_and_never_changes_balances(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->post(route('receipts.reject', $receipt), ['reason' => 'Comprobante incorrecto'])->assertForbidden();
        $this->actingAs($data['head'])->post(route('receipts.reject', $receipt), ['reason' => 'Corto'])->assertSessionHasErrors('reason');
        $this->post(route('receipts.reject', $receipt), ['reason' => 'Comprobante incorrecto'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $receipt->fresh()->approval_status);
        $this->assertSame($data['head']->id, $receipt->fresh()->rejected_by);
        $this->get(route('receipts.show', $receipt))->assertOk()->assertSee('Historial del pago')->assertSee($data['head']->name)->assertSee('Comprobante incorrecto');
        $this->assertSame('Comprobante incorrecto', $receipt->fresh()->rejection_reason);
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasErrors('amount');
        $this->assertSame(0, $data['client']->fresh()->credit_cents);
        $this->assertSame(40000, $data['order']->fresh()->balance_cents);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_other_organizations_cannot_review_and_head_office_cannot_register_or_change_sales(): void
    {
        $data = $this->portfolio();
        $other = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->actingAs($other['head'])->get(route('receipts.index', ['dealership_id' => $data['company']->id]))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->total() === 0);
        $this->get(route('receipts.show', $receipt))->assertForbidden();
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertForbidden();
        $this->post(route('receipts.reject', $receipt), ['reason' => 'Intento desde otra organización'])->assertForbidden();
        $this->actingAs($data['head'])->post(route('receipts.store'), $this->payment($data))->assertForbidden();
        $this->get(route('receipts.create'))->assertForbidden();
        $this->get(route('clients.show', $data['client']))->assertForbidden();
        $this->get(route('orders.show', $data['order']))->assertForbidden();
        $this->post(route('orders.pay', $data['order']), $this->payment($data))->assertForbidden();
    }

    public function test_targeted_installment_and_surplus_are_preserved_during_approval(): void
    {
        $data = $this->portfolio();
        $target = $data['order']->installments->firstWhere('number', 2);
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data, '150.00') + ['order_id' => $data['order']->id, 'installment_id' => $target->id])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertSame(0, $target->fresh()->remaining_cents);
        $this->assertSame(10000, $data['order']->fresh()->installments->firstWhere('number', 0)->remaining_cents);
        $this->assertSame(5000, $data['client']->fresh()->credit_cents);
    }

    public function test_changed_debt_becomes_credit_and_cancelled_sale_cannot_be_approved(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('orders.pay', $data['order']), $this->payment($data, '400.00'))->assertSessionHasNoErrors();
        $first = Receipt::firstOrFail();
        $this->post(route('orders.pay', $data['order']), $this->payment($data, '400.00'))->assertSessionHasNoErrors();
        $second = Receipt::latest('id')->firstOrFail();
        $this->actingAs($data['head'])->post(route('receipts.approve', $first), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->post(route('receipts.approve', $second), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertSame(0, $data['order']->fresh()->balance_cents);
        $this->assertSame(40000, $data['client']->fresh()->credit_cents);
        $this->assertDatabaseCount('payments', 1);

        $other = $this->portfolio();
        $this->actingAs($other['owner'])->post(route('orders.pay', $other['order']), $this->payment($other))->assertSessionHasNoErrors();
        $cancelled = Receipt::latest('id')->firstOrFail();
        $other['order']->update(['cancelled' => true]);
        $this->actingAs($other['head'])->post(route('receipts.approve', $cancelled), ['approval_proof' => $this->approvalProof()])->assertSessionHasErrors('amount');
        $this->assertSame('pending', $cancelled->fresh()->approval_status);
        $this->assertNull($cancelled->fresh()->approved_at);
    }

    public function test_pending_and_rejected_receipts_are_excluded_from_confirmed_totals(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->get(route('receipts.index', ['state' => 'pending']))->assertOk()->assertViewHas('received', 0)->assertViewHas('pending', 15000)->assertViewHas('receipts', fn ($rows) => $rows->total() === 1);
        $this->get(route('receipts.index', ['state' => 'applied']))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->total() === 0);
        $this->get(route('reports', ['type' => 'receipts']))->assertOk()->assertViewHas('total', 0);
        $this->actingAs($data['head'])->get(route('reports'))->assertOk()->assertViewHas('totals', fn ($totals) => $totals['received_cents'] === 0);
        $this->post(route('receipts.reject', $receipt), ['reason' => 'Ingreso pendiente incorrecto'])->assertSessionHasNoErrors();
        $this->get(route('receipts.index', ['state' => 'rejected']))->assertOk()->assertViewHas('received', 0)->assertViewHas('pending', 0)->assertViewHas('receipts', fn ($rows) => $rows->total() === 1);

    }

    public function test_head_office_default_queue_includes_old_payments_and_keeps_receipt_and_approval_dates(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['owner'])->post(route('receipts.store'), $this->payment($data))->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $receivedAt = today()->subMonths(2)->setTime(10, 30);
        $receipt->update(['created_at' => $receivedAt]);
        $this->actingAs($data['head'])->get(route('receipts.index'))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $receipt->id);
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertTrue($receipt->fresh()->created_at->equalTo($receivedAt));
        $this->assertTrue($receipt->fresh()->approved_at->isToday());
        $this->get(route('receipts.index'))->assertOk()->assertViewHas('receipts', fn ($rows) => $rows->total() === 0);
    }
}
