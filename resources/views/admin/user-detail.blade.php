@extends('layouts.app')
@section('title',$u->exists?'Editar usuario':'Crear nuevo usuario')
@section('subtitle','Configura los datos, el acceso y la responsabilidad del usuario.')
@section('actions')<a class="btn secondary" href="{{ route('users') }}">Volver a usuarios</a>@endsection
@section('content')
<section class="card form-card"><h2>Datos del usuario</h2>@include('admin.user-form')</section>
@endsection
