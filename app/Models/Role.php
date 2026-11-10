<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const LABELS = ['admin' => 'Administrador del Sistema', 'head_office' => 'Casa matriz', 'dealership_admin' => 'Concesionario', 'customer' => 'Cliente'];

    public $timestamps = false;

    protected $guarded = [];
}
