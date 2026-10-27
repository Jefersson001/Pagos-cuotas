<?php

use App\Models\Receipt;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SalesService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    exit(3);
}
// The parent holds the order lock until both workers have reached this point.
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    if (isset($argv[5])) {
        app(SalesService::class)->pay(User::findOrFail($argv[1]), (int) $argv[2], ['token' => (string) Str::uuid(), 'amount' => '80.00', 'payment_source' => 'credit']);
    } elseif (isset($argv[4])) {
        app(ReceiptService::class)->apply(User::findOrFail($argv[1]), Receipt::findOrFail($argv[4]), (int) $argv[2], ['token' => (string) Str::uuid(), 'amount' => '80.00']);
    } else {
        app(SalesService::class)->pay(User::findOrFail($argv[1]), (int) $argv[2], ['token' => (string) Str::uuid(), 'amount' => '80.00', 'payment_method_id' => (int) $argv[3]]);
    }
    fwrite(STDOUT, 'paid');
} catch (ValidationException $e) {
    fwrite(STDOUT, 'rejected');
}
