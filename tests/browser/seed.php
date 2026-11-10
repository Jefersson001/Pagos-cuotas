<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$sqliteDatabase = realpath(__DIR__.'/../../storage/app').DIRECTORY_SEPARATOR.'browser-tests.sqlite';
$usesSqlite = config('database.default') === 'sqlite'
    && str_replace('\\', '/', config('database.connections.sqlite.database')) === str_replace('\\', '/', $sqliteDatabase);
if (! $usesSqlite && (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'ventas_cuotas_browser')) {
    exit(3);
}
if ($usesSqlite && ! file_exists($sqliteDatabase)) {
    touch($sqliteDatabase);
}
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$password = Str::password(20);
User::create(['name' => 'Administrador de pruebas', 'username' => 'browser_admin', 'email' => 'browser@example.test', 'password' => $password, 'role_id' => Role::where('name', 'admin')->value('id')]);
echo json_encode(['email' => 'browser@example.test', 'password' => $password]);
