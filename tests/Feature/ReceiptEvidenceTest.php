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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    /** @return array{head: User, dealer: User, customer: User, client: Client, values: array<string, mixed>} */
    private function portfolio(): array
    {
        $company = Dealership::factory()->create(['organization_type' => 'head_office']);
        $head = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'head_office')->value('id')]);
        $contact = Client::factory()->concessionaire()->create(['dealership_id' => $company->id]);
        $dealer = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'dealership_admin')->value('id'), 'concessionaire_id' => $contact->id]);
        $client = Client::factory()->for($dealer, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $contact->id]);
        $customer = User::factory()->create(['dealership_id' => $company->id, 'role_id' => Role::where('name', 'customer')->value('id'), 'client_id' => $client->id, 'concessionaire_id' => $contact->id]);
        $method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia', 'active' => true]);
        $values = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'amount' => '50.00', 'payment_method_id' => $method->id, 'payment_date' => today()->subDays(5)->toDateString(), 'reference' => 'REF-123'];

        return compact('head', 'dealer', 'customer', 'client', 'values');
    }

    public function test_actual_payment_date_is_required_and_separate_from_registration_and_approval(): void
    {
        $data = $this->portfolio();
        foreach ([null, 'incorrecta', today()->addDay()->toDateString()] as $date) {
            $this->actingAs($data['dealer'])->post(route('receipts.store'), array_replace($data['values'], ['payment_date' => $date]))->assertSessionHasErrors('payment_date');
        }
        $this->assertDatabaseCount('receipts', 0);
        $this->post(route('receipts.store'), $data['values'])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $registeredAt = $receipt->created_at->toDateTimeString();
        $this->assertSame($data['values']['payment_date'], $receipt->payment_date->toDateString());
        $this->assertNull($receipt->approved_at);
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertSame($registeredAt, $receipt->fresh()->created_at->toDateTimeString());
        $this->assertSame($data['values']['payment_date'], $receipt->fresh()->payment_date->toDateString());
        $this->assertTrue($receipt->fresh()->approved_at->isToday());
        $this->assertSame($data['head']->id, $receipt->fresh()->approved_by);
        $this->actingAs($data['customer'])->get(route('portal.receipt', $receipt))->assertOk()->assertSee('Fecha de pago')->assertSee($receipt->payment_date->format('d/m/Y'));
    }

    public function test_client_proof_is_saved_with_registration_and_a_retry_preserves_the_original(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealer'])->get(route('receipts.create'))->assertOk()->assertSee('name="proof"', false)->assertSee('multipart/form-data');
        $this->post(route('receipts.store'), $data['values'] + ['proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $path = $receipt->proof_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('pending', $receipt->approval_status);
        $this->assertNull($receipt->approval_proof_path);
        $this->post(route('receipts.store'), $data['values'] + ['proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('receipts', 1);
        $this->assertSame($path, $receipt->fresh()->proof_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('receipt-proofs'));
        $this->actingAs($data['head'])->get(route('receipts.proof', $receipt))->assertOk();
    }

    public function test_invalid_upload_or_invalid_payment_leaves_neither_receipt_nor_file(): void
    {
        $data = $this->portfolio();
        foreach ([UploadedFile::fake()->create('archivo.php', 1, 'application/x-httpd-php'), UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')] as $file) {
            $this->actingAs($data['dealer'])->post(route('receipts.store'), $data['values'] + ['proof' => $file])->assertSessionHasErrors('proof');
        }
        $this->post(route('receipts.store'), array_replace($data['values'], ['amount' => '-1', 'proof' => $this->approvalProof()]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('receipt-proofs'));
    }

    public function test_both_proofs_can_be_viewed_and_downloaded_after_approval_with_scoped_access(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealer'])->post(route('receipts.store'), $data['values'] + ['proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->actingAs($data['head'])->get(route('receipts.proof', ['receipt' => $receipt, 'preview' => 1]))->assertOk();
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->get(route('receipts.show', $receipt))->assertOk()
            ->assertSee('Ver comprobante del cliente')->assertSee('Descargar comprobante del cliente')
            ->assertSee('Ver comprobante de casa matriz')->assertSee('Descargar comprobante de casa matriz');
        foreach (['receipts.proof', 'receipts.approval-proof'] as $name) {
            $download = $this->get(route($name, $receipt))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
            $preview = $this->get(route($name, ['receipt' => $receipt, 'preview' => 1]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('inline;', $preview->headers->get('Content-Disposition'));
        }
        $foreign = $this->portfolio();
        $this->actingAs($foreign['head']);
        foreach (['receipts.proof', 'receipts.approval-proof'] as $name) {
            $this->get(route($name, $receipt))->assertForbidden();
            $this->get(route($name, ['receipt' => $receipt, 'preview' => 1]))->assertForbidden();
        }
    }

    public function test_sale_registration_saves_client_photo_for_dealer_and_administrator(): void
    {
        $data = $this->portfolio();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        foreach ([$data['dealer'], $admin] as $actor) {
            $order = app(SalesService::class)->create($data['dealer'], ['token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'description' => 'Venta con comprobante', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '120.00']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => today()->addMonth()->toDateString()]);
            $values = array_replace($data['values'], ['token' => (string) Str::uuid()]);
            $this->actingAs($actor)->get(route('orders.show', $order))->assertOk()->assertSee('name="proof"', false)->assertSee('multipart/form-data');
            $photo = UploadedFile::fake()->createWithContent('pago.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aHZkAAAAASUVORK5CYII='));
            $this->post(route('orders.pay', $order), $values + ['proof' => $photo])->assertSessionHasNoErrors();
            $receipt = Receipt::where('token', $values['token'])->firstOrFail();
            Storage::disk('local')->assertExists($receipt->proof_path);
            $this->assertSame($actor->isAdmin() ? 'approved' : 'pending', $receipt->approval_status);
            $this->assertNull($receipt->approval_proof_path);
            $path = $receipt->proof_path;
            $this->post(route('orders.pay', $order), $values + ['proof' => $this->approvalProof()])->assertSessionHasNoErrors();
            $this->assertSame($path, $receipt->fresh()->proof_path);
            $this->assertCount($actor->isAdmin() ? 2 : 1, Storage::disk('local')->allFiles('receipt-proofs'));
        }
    }

    public function test_approval_requires_valid_separate_proof_and_preserves_both_files_on_retry(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealer'])->post(route('receipts.store'), $data['values'])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->post(route('receipts.proof.store', $receipt), ['proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $clientProof = $receipt->fresh()->proof_path;
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt))->assertSessionHasErrors('approval_proof');
        foreach ([UploadedFile::fake()->create('archivo.php', 1, 'application/x-httpd-php'), UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')] as $invalid) {
            $this->post(route('receipts.approve', $receipt), ['approval_proof' => $invalid])->assertSessionHasErrors('approval_proof');
        }
        $this->assertSame('pending', $receipt->fresh()->approval_status);
        $this->assertSame(0, $data['client']->fresh()->credit_cents);
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $approvalProof = $receipt->fresh()->approval_proof_path;
        $this->assertNotSame($clientProof, $approvalProof);
        Storage::disk('local')->assertExists([$clientProof, $approvalProof]);
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertSame($approvalProof, $receipt->fresh()->approval_proof_path);
        $this->assertCount(2, Storage::disk('local')->allFiles('receipt-proofs'));
        $this->assertSame(5000, $data['client']->fresh()->credit_cents);
        $this->get(route('receipts.approval-proof', $receipt))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($data['dealer'])->get(route('receipts.approval-proof', $receipt))->assertOk();
        $this->actingAs($data['customer'])->get(route('receipts.approval-proof', $receipt))->assertForbidden();
        $foreign = $this->portfolio();
        $this->actingAs($foreign['head'])->get(route('receipts.approval-proof', $receipt))->assertForbidden();
        $this->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertForbidden();
    }

    public function test_rejection_needs_no_head_office_proof_and_keeps_balances_unchanged(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealer'])->post(route('receipts.store'), $data['values'])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->actingAs($data['head'])->post(route('receipts.reject', $receipt), ['reason' => 'El ingreso no aparece en nuestra cuenta'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $receipt->fresh()->approval_status);
        $this->assertNull($receipt->fresh()->approval_proof_path);
        $this->assertNull($receipt->fresh()->approved_at);
        $this->assertSame(0, $data['client']->fresh()->credit_cents);
    }

    public function test_pending_date_can_be_corrected_but_approved_date_cannot(): void
    {
        $data = $this->portfolio();
        $this->actingAs($data['dealer'])->post(route('receipts.store'), $data['values'])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $corrected = today()->subDays(3)->toDateString();
        $this->put(route('receipts.update', $receipt), ['payment_date' => $corrected])->assertSessionHasNoErrors();
        $this->assertSame($corrected, $receipt->fresh()->payment_date->toDateString());
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->put(route('receipts.update', $receipt), ['payment_date' => today()->toDateString()])->assertSessionHasErrors('payment_date');
        $this->assertSame($corrected, $receipt->fresh()->payment_date->toDateString());
    }

    public function test_sale_payment_date_and_failed_approval_keep_financial_and_file_state_consistent(): void
    {
        $data = $this->portfolio();
        $order = app(SalesService::class)->create($data['dealer'], ['token' => (string) Str::uuid(), 'client_id' => $data['client']->id, 'description' => 'Venta de prueba', 'items' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '120.00']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => today()->addMonth()->toDateString()]);
        $this->actingAs($data['dealer'])->post(route('orders.pay', $order), array_replace($data['values'], ['payment_date' => null]))->assertSessionHasErrors('payment_date');
        $this->post(route('orders.pay', $order), $data['values'])->assertSessionHasNoErrors();
        $receipt = Receipt::firstOrFail();
        $this->assertSame($data['values']['payment_date'], $receipt->payment_date->toDateString());
        $this->post(route('orders.pay', $order), array_replace($data['values'], ['payment_date' => today()->toDateString()]))->assertSessionHasErrors('amount');
        $this->actingAs($data['head'])->post(route('receipts.approve', $receipt), ['expected_applied_cents' => 100, 'approval_proof' => $this->approvalProof()])->assertSessionHasErrors('amount');
        $this->assertSame([], Storage::disk('local')->allFiles('receipt-proofs'));
        $this->assertSame('pending', $receipt->fresh()->approval_status);
        $this->assertNull($receipt->fresh()->approved_at);
        $this->assertNull($receipt->fresh()->approval_proof_path);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(12000, $order->fresh()->balance_cents);

        $this->post(route('receipts.approve', $receipt), ['expected_applied_cents' => 5000, 'approval_proof' => $this->approvalProof()])->assertSessionHasNoErrors();
        $this->assertSame(7000, $order->fresh()->balance_cents);
        $this->assertDatabaseCount('payments', 1);
    }
}
