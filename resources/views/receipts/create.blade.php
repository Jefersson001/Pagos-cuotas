@extends('layouts.app')
@section('title','Registrar pago')
@section('subtitle',auth()->user()->isAdmin()?'Registra dinero recibido y su aplicación.':'Registra dinero recibido. Casa matriz debe aprobarlo antes de actualizar los saldos.')
@section('actions')<a class="btn secondary" href="{{ route('receipts.index') }}">Volver a la lista</a>@endsection
@section('content')
<p class="notice mb-6">{{ auth()->user()->isAdmin()?'El pago se aprueba al guardarlo.':'El pago quedará pendiente de aprobación por casa matriz.' }}</p>
<form method="post" enctype="multipart/form-data" action="{{ route('receipts.store') }}" class="card form-card" x-data="receiptForm(@js(['client'=>$selectedClient,'clientsUrl'=>$clientSearchUrl,'accountUrl'=>route('receipts.account'),'orderId'=>old('order_id'),'installmentId'=>old('installment_id'),'methodId'=>old('payment_method_id')]))">
@csrf<input type="hidden" name="token" value="{{ old('token',(string)Str::uuid()) }}">
@include('components.client-relation')
<p class="muted mt-4" x-show="accountLoading">Consultando cuenta del cliente…</p><p class="notice error mt-4" x-show="accountError" x-text="accountError" role="alert"></p>
<div class="detail-grid my-6" x-show="clientId && !accountLoading"><div><small>Deuda de ventas</small><strong x-text="money(debt)"></strong></div><div><small>Saldo a favor actual</small><strong x-text="money(credit)"></strong></div></div>
<div class="form-grid mt-6">
<label>Monto recibido ($)<input type="number" x-ref="amount" name="amount" min="0.01" max="9999999999.99" step="0.01" value="{{ old('amount') }}" required></label>
<label>Método de pago<select name="payment_method_id" x-model="methodId" :disabled="accountLoading || !clientId" required><option value="">Selecciona un método</option><template x-for="m in methods" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template></select></label>
<label class="full">Relacionar con una venta<select name="order_id" x-model="orderId" :disabled="accountLoading || !clientId"><option value="">Sin relacionar: saldo a favor después de la aprobación</option><template x-for="o in orders" :key="o.id"><option :value="String(o.id)" x-text="'#'+o.id+' · '+o.description+' · Pendiente '+money(o.balance)"></option></template></select></label>
<label class="full" x-show="orderId">Cuotas<select name="installment_id" x-model="installmentId" :disabled="!orderId" @change="if(selectedOrder)$refs.amount.value=(($el.value?Number($el.selectedOptions[0].dataset.remaining):selectedOrder.balance)/100).toFixed(2)"><option value="">Automáticamente: cubrir varias cuotas, más antigua primero</option><template x-for="i in (selectedOrder?.installments || [])" :key="i.id"><option :value="String(i.id)" :data-remaining="i.remaining" x-text="(i.label || 'Cuota '+i.number)+' · '+money(i.remaining)"></option></template></select></label>
<label>Referencia<input name="reference" maxlength="255" value="{{ old('reference') }}"></label>
<label>Fecha de pago<input type="date" name="payment_date" value="{{ old('payment_date',today()->toDateString()) }}" max="{{ today()->toDateString() }}" required><small>Día en que se realizó el pago, aunque lo registres después.</small></label>
<label class="full">Comprobante del cliente<input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"><small>Adjunta la foto o el documento del pago. JPG, PNG o PDF, máximo 5 MB. Se guardará al registrar el pago.</small></label>
</div>
<p class="notice mt-4" x-show="clientId && !accountLoading && !methods.length">No hay métodos de pago activos. Solicita a casa matriz que configure uno antes de registrar el pago.</p><details class="payment-help"><summary>¿Cómo se aplica este pago?</summary><p class="muted mt-4">Si eliges una venta, el pago queda relacionado con ella. Al aprobar casa matriz, se cubrirá la deuda o el compromiso seleccionado y el excedente quedará como saldo a favor. Sin venta, todo el importe aprobado quedará a favor del cliente.</p></details>
<details class="optional-fields" @if(old('notes')||$errors->has('notes')) open @endif><summary>Información adicional <span>Observaciones del pago</span></summary><label class="full">Observaciones<textarea name="notes" maxlength="3000">{{ old('notes') }}</textarea></label></details><div class="form-actions"><a class="btn secondary" href="{{ route('receipts.index') }}">Cancelar</a><button class="btn primary" :disabled="!clientId || accountLoading || !!accountError || !methods.length">Registrar pago</button></div>
</form>
@endsection
