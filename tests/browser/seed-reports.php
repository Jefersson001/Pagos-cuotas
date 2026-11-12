<?php

use App\Models\Client;
use App\Models\Dealership;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\SalesEvent;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

ob_start();
require __DIR__.'/seed.php';
$credentials = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$admin = User::where('email', $credentials['email'])->firstOrFail();
$company = Dealership::factory()->create(['organization_type' => 'head_office', 'name' => 'Empresa de reportes PDF']);
$passwordHash = Hash::make($credentials['password']);
$head = User::factory()->create(['name' => 'Casa matriz PDF', 'email' => 'head-pdf@example.test', 'password' => $passwordHash, 'role_id' => Role::where('name', 'head_office')->value('id'), 'dealership_id' => $company->id]);
$method = PaymentMethod::create(['dealership_id' => $company->id, 'name' => 'Transferencia bancaria de prueba PDF', 'active' => true]);
$sales = app(SalesService::class);
$receipts = app(ReceiptService::class);
$dealerEmail = null;
for ($index = 1; $index <= 45; $index++) {
    $suffix = str_pad($index, 3, '0', STR_PAD_LEFT);
    $branch = Client::factory()->concessionaire()->for($admin, 'owner')->create(['dealership_id' => $company->id, 'name' => 'Concesionario PDF '.$suffix.' · Sucursal con nombre extenso para impresión', 'document' => 'J-PDF-'.$suffix]);
    $owner = User::factory()->create(['name' => 'Responsable PDF '.$suffix, 'role_id' => Role::where('name', $index === 1 ? 'dealership_admin' : 'user')->value('id'), 'dealership_id' => $company->id, 'concessionaire_id' => $branch->id, 'password' => $passwordHash]);
    $client = Client::factory()->for($owner, 'owner')->create(['dealership_id' => $company->id, 'concessionaire_id' => $branch->id, 'name' => 'Cliente PDF '.$suffix.' Álvarez '.str_repeat('TextoExtenso', 10), 'document' => 'V-PDF-'.$suffix]);
    $event = SalesEvent::factory()->create(['dealership_id' => $company->id, 'name' => 'Jornada PDF '.$suffix.' · Evento de ventas y atención comercial']);
    $values = ['token' => (string) Str::uuid(), 'client_id' => $client->id, 'sales_event_id' => $event->id, 'description' => 'Venta para comprobación de PDF', 'items' => [['description' => 'Producto de prueba', 'quantity' => 1, 'unit_price' => '100']], 'initial_amount' => '0', 'installment_count' => 2, 'frequency' => 'monthly', 'first_due' => today()->subMonthsNoOverflow(2)->startOfMonth()->toDateString()];
    $sales->create($admin, $values);
    $paid = $sales->create($admin, array_replace($values, ['token' => (string) Str::uuid(), 'items' => [['description' => 'Producto pagado', 'quantity' => 1, 'unit_price' => '50']]]));
    $sales->pay($admin, $paid->id, ['token' => (string) Str::uuid(), 'amount' => '50', 'payment_method_id' => $method->id, 'reference' => 'APROBADO-PDF-'.$suffix]);
    $cancelled = $sales->create($admin, array_replace($values, ['token' => (string) Str::uuid()]));
    $cancelled->update(['cancelled' => true]);
    $receipts->receive($owner, $client->id, ['token' => (string) Str::uuid(), 'amount' => '13', 'payment_method_id' => $method->id, 'reference' => 'PENDIENTE-PDF-'.$suffix]);
    $voided = $receipts->receive($admin, $client->id, ['token' => (string) Str::uuid(), 'amount' => '7', 'payment_method_id' => $method->id, 'reference' => 'ANULADO-PDF-'.$suffix]);
    $receipts->void($admin, $voided, 'Ingreso anulado de la prueba PDF');
    if ($index === 1) {
        $dealerEmail = $owner->email;
    }
}
SalesEvent::defaultForCompany($company->id);
echo json_encode($credentials + ['company_id' => $company->id, 'head_email' => $head->email, 'dealer_email' => $dealerEmail], JSON_THROW_ON_ERROR);
