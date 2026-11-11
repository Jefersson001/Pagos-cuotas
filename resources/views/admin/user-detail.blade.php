@extends('layouts.app')
@section('title',$u->exists?(($readOnly??false)?$u->name:'Editar usuario'):'Crear nuevo usuario')
@section('subtitle','Configura los datos, el acceso y la responsabilidad del usuario.')
@section('actions')<a class="btn secondary" href="{{ route('users') }}">Volver a usuarios</a>@endsection
@section('content')
@if($u->exists)<x-record-actions type="user" :record="$u" :edit="route('users.edit',$u)" :view="route('users.show',$u)" :read-only="$readOnly??false" :can-delete="auth()->user()->isAdmin()" :can-duplicate="auth()->user()->isAdmin()" />@endif

<section class="card form-card"><div class="record-card-heading"><h2>Datos del usuario</h2><x-record-status :current="$u->exists?($u->active?'Activo':'Inactivo'):'Borrador'" /></div>@include('admin.user-form')</section>
@endsection
