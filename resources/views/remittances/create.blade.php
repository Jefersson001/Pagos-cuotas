@extends('layouts.app')
@section('title','Preparar remesa')
@section('actions')<a class="btn secondary" href="{{ route('remittances.index') }}">Volver a remesas</a>@endsection
@section('content')
@if($concessionaires->count()>1)<form method="get" class="filters card mb-6"><label>Concesionario<select name="concessionaire_id" onchange="this.form.submit()">@foreach($concessionaires as $dealer)<option value="{{ $dealer->id }}" @selected($branch?->id===$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label><button class="btn secondary">Consultar cobros</button></form>@endif
@if(!$branch || $receipts->isEmpty())<section class="card form-card"><p>No hay cobros recibidos por este concesionario pendientes de envío.</p><a class="text-link" href="{{ route('receipts.create') }}">Registrar cobro</a></section>
@else
<form method="post" enctype="multipart/form-data" action="{{ route('remittances.store') }}" class="card form-card" x-data="{ids:@js(array_map('strval',old('receipt_ids',[]))),amount:@js(old('amount','')),values:@js($receipts->mapWithKeys(fn($receipt)=>[(string)$receipt->id=>\App\Support\Money::cents($receipt->amount)])->all()),get selectedTotal(){return this.ids.reduce((sum,id)=>sum+(this.values[id]||0),0)},syncAmount(){this.amount=(this.selectedTotal/100).toFixed(2)}}">
@csrf<input type="hidden" name="token" value="{{ old('token',(string)Str::uuid()) }}"><input type="hidden" name="concessionaire_id" value="{{ $branch->id }}">
<h2>Cobros de {{ $branch->name }}</h2><p class="muted mb-4">Selecciona los cobros que forman esta transferencia. Se conserva el destino de cada pago.</p>
<div class="table-scroll mb-6"><table><thead><tr><th>Incluir</th><th>Cobro / Fecha</th><th>Cliente</th><th>Venta / Destino</th><th>Importe</th><th>Comprobante del cliente</th></tr></thead><tbody>
@foreach($receipts as $receipt)<tr><td><input type="checkbox" name="receipt_ids[]" value="{{ $receipt->id }}" x-model="ids" @change="syncAmount()" aria-label="Incluir REC-{{ $receipt->id }}"></td><td>REC-{{ $receipt->id }}<small>{{ $receipt->payment_date?->format('d/m/Y') }}</small></td><td>{{ $receipt->client->name }}</td><td>@if($receipt->sourceOrder)Venta #{{ $receipt->source_order_id }}<small>{{ $receipt->intendedInstallment?->label ?? 'Distribución automática a compromisos de la venta' }}</small>@else Sin venta: saldo a favor al aprobar @endif</td><td>&#36;{{ number_format($receipt->amount,2) }}</td><td>@if($receipt->proof_path)<a class="text-link" href="{{ route('receipts.proof',['receipt'=>$receipt,'preview'=>1]) }}" target="_blank" rel="noopener">Ver</a>@else Sin adjunto @endif</td></tr>@endforeach
</tbody></table></div>
<div class="form-grid">
<label>Fecha de transferencia<input type="date" name="payment_date" value="{{ old('payment_date',today()->toDateString()) }}" max="{{ today()->toDateString() }}" required></label>
<label>Método de transferencia<select name="payment_method_id" required><option value="">Selecciona un método</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected(old('payment_method_id')==$method->id)>{{ $method->name }}</option>@endforeach</select></label>
<label>Referencia de transferencia<input name="reference" value="{{ old('reference') }}" maxlength="255" required></label>
<label>Importe transferido ($)<input name="amount" type="number" x-model="amount" min="0.01" max="9999999999.99" step="0.01" required><small>Debe coincidir con la suma de los cobros seleccionados.</small></label>
<label class="full">Comprobante de transferencia a casa matriz<input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required><small>JPG, PNG o PDF · Máximo 5 MB. Se conserva separado de los comprobantes de los clientes.</small></label>
</div><div class="form-actions"><span x-text="'Total seleccionado: $'+(selectedTotal/100).toFixed(2)"></span><button class="btn primary" :disabled="!ids.length">Enviar remesa</button></div>
</form>
@endif
@endsection