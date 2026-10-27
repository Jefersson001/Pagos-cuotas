<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('sales:admin {email} {--name=Administrador}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Correo inválido.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('El correo ya existe.');

        return 1;
    }
    $role = Role::where('name', 'admin')->firstOrFail();
    $password = Str::password(20);
    User::create(['name' => $this->option('name'), 'email' => $email, 'username' => 'admin_'.Str::lower(Str::random(6)), 'password' => $password, 'role_id' => $role->id]);
    $this->info('Administrador creado: '.$email);
    $this->line('Contraseña inicial: '.$password);
})->purpose('Crear un administrador con contraseña aleatoria');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
