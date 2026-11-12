@extends('layouts.app')
@section('title','Remesa REM-'.str_pad($remittance->id,6,'0',STR_PAD_LEFT))
@section('actions')<a class="btn secondary" href="{{ route('remittances.index') }}">Volver a remesas</a><button class="btn secondary" onclick="window.print()">Imprimir</button>@endsection
@section('content')
<x-view-tabs id="remesa-ficha" class="card" data-default-tab="{{ $remittance->status==='pending'?'revision':'detalle' }}">
<div data-tab-panel="Cobros incluidos" data-tab-key="detalle">
<section class="card form-card"><div class="record-card-heading"><h2>{{ $remittance->concessionaire->name }}</h2><span class="pill neutral">{{ $remittance->status_label }}</span></div><div class="detail-grid"><div><small>Importe transferido</small><strong>&#36;{{ number_format($remittance->amount,2) }}</strong></div><div><small>Fecha de transferencia</small><strong>{{ $remittance->payment_date->format('d/m/Y') }}</strong></div><div><small>Referencia / Método</small><strong>{{ $remittance->reference }}</strong><p>{{ $remittance->method->name }}</p></div></div></section>
<section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Cobro / Cliente</th><th>Venta / Compromiso</th><th>Recibido</th><th>{{ $remittance->status==='pending'?'Se aplicará':'Aplicado actualmente' }}</th><th>{{ $remittance->status==='pending'?'Quedará a favor':'Crédito disponible' }}</th><th>Estado</th></tr></thead><tbody>
@foreach($remittance->receipts as $receipt)<tr><td><a class="text-link" href="{{ route('receipts.show',$receipt) }}">REC-{{ $receipt->id }}</a><strong>{{ $receipt->client->name }}</strong><small>{{ $receipt->payment_date?->format('d/m/Y') }}</small></td><td>@if($receipt->sourceOrder)<a class="text-link" href="{{ route(auth()->user()->isHeadOffice()?'head-office.orders.show':'orders.show',$receipt->source_order_id) }}">Venta #{{ $receipt->source_order_id }}</a><small>{{ $receipt->intendedInstallment?->label ?? 'Distribución automática: ver detalle en la venta' }}</small>@else Sin venta relacionada @endif</td><td>&#36;{{ number_format($receipt->amount,2) }}</td><td>&#36;{{ number_format(($remittance->status==='pending'?$previews[$receipt->id]['applied_cents']:$receipt->applied_cents)/100,2) }}</td><td>&#36;{{ number_format(($remittance->status==='pending'?$previews[$receipt->id]['credit_cents']:$receipt->available_cents)/100,2) }}</td><td>{{ $receipt->approval_label }}<small>{{ $receipt->collection_label }}</small></td></tr>@endforeach
</tbody></table></div></section>
</div>
<div data-tab-panel="Transferencia y aprobación" data-tab-key="revision">
@php
    $isPending = $remittance->status === 'pending';
    $canReview = auth()->user()->can('review', $remittance);
    $appliedCents = $isPending ? $previews->sum('applied_cents') : $remittance->receipts->sum('applied_cents');
    $creditCents = $isPending ? $previews->sum('credit_cents') : $remittance->receipts->sum('available_cents');
@endphp
<section class="card form-card mb-6 receipt-review" data-remittance-review>
    <header class="receipt-review-heading">
        <h2>{{ $remittance->status_label }}</h2>
        @if($remittance->approved_at)
            <p>{{ $remittance->approved_at->format('d/m/Y H:i') }} · {{ $remittance->approver?->name }}</p>
        @elseif($remittance->rejected_at)
            <p>{{ $remittance->rejected_at->format('d/m/Y H:i') }} · {{ $remittance->rejecter?->name }}</p>
        @endif
    </header>
    <div class="receipt-review-meta">
        <div><small>Referencia:</small> <strong>{{ $remittance->reference }}</strong></div>
        <div><small>Fecha de transferencia:</small> <strong>{{ $remittance->payment_date->format('d/m/Y') }}</strong></div>
        <div><small>Método:</small> <strong>{{ $remittance->method->name }}</strong></div>
        <div><small>Concesionario:</small> <strong>{{ $remittance->concessionaire->name }}</strong></div>
    </div>
    <div class="receipt-review-amounts">
        <div><small>Transferido</small><strong>&#36;{{ number_format($remittance->amount,2) }}</strong></div>
        <div><small>{{ $isPending ? 'A ventas' : 'Aplicado actualmente' }}</small><strong>&#36;{{ number_format($appliedCents/100,2) }}</strong></div>
        <div><small>{{ $isPending ? 'Saldo a favor de los clientes' : 'Saldo a favor disponible' }}</small><strong>&#36;{{ number_format($creditCents/100,2) }}</strong></div>
    </div>
    @if($isPending && !$canReview)
        <p class="muted receipt-review-warning">Esperando confirmación de casa matriz. Los cobros permanecen reportados.</p>
    @elseif($remittance->status==='rejected')
        <p class="notice error receipt-review-warning">{{ $remittance->rejection_reason }}</p>
        <p class="muted receipt-review-warning">Los cobros se conservan y pueden incluirse en una nueva remesa.</p>
    @elseif($remittance->status==='void')
        <p class="notice error receipt-review-warning">Anulada: {{ $remittance->void_reason }}</p>
    @endif
    @if($isPending && $previews->contains(fn($preview)=>$preview['blocked']))
        <p class="notice error receipt-review-warning">Una venta o compromiso no permite aplicar el cobro. Rechaza la remesa y solicita su corrección.</p>
    @endif
    <div @class(['receipt-review-grid','receipt-review-readonly'=>!$remittance->approval_proof_path && (!$isPending || !$canReview)])>
        <section class="receipt-review-source receipt-proof">
            <h3>Transferencia del concesionario</h3>
            @if(in_array(strtolower(pathinfo($remittance->proof_path, PATHINFO_EXTENSION)),['jpg','jpeg','png'],true))
                <a class="receipt-review-preview" href="{{ route('remittances.proof',['remittance'=>$remittance,'preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ampliar transferencia del concesionario"><img src="{{ route('remittances.proof',['remittance'=>$remittance,'preview'=>1]) }}" alt="Transferencia del concesionario" loading="lazy"></a>
            @endif
            <div class="receipt-review-links">
                <a class="btn secondary" href="{{ route('remittances.proof',['remittance'=>$remittance,'preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ver transferencia">Ver</a>
                <a class="btn secondary" href="{{ route('remittances.proof',$remittance) }}" aria-label="Descargar transferencia">Descargar</a>
            </div>
        </section>
        @if($remittance->approval_proof_path)
            <section class="receipt-review-approval receipt-proof">
                <h3>Comprobante de casa matriz</h3>
                @if(in_array(strtolower(pathinfo($remittance->approval_proof_path, PATHINFO_EXTENSION)),['jpg','jpeg','png'],true))
                    <a class="receipt-review-preview" href="{{ route('remittances.proof',['remittance'=>$remittance,'side'=>'approval','preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ampliar ingreso en casa matriz"><img src="{{ route('remittances.proof',['remittance'=>$remittance,'side'=>'approval','preview'=>1]) }}" alt="Comprobante de casa matriz" loading="lazy"></a>
                @endif
                <div class="receipt-review-links">
                    <a class="btn secondary" href="{{ route('remittances.proof',['remittance'=>$remittance,'side'=>'approval','preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ver ingreso en casa matriz">Ver</a>
                    <a class="btn secondary" href="{{ route('remittances.proof',['remittance'=>$remittance,'side'=>'approval']) }}" aria-label="Descargar ingreso en casa matriz">Descargar</a>
                </div>
            </section>
        @elseif($isPending && $canReview)
            <section class="receipt-review-approval" x-data="{rejecting:@js($errors->has('reason'))}">
                <h3>Confirmación de casa matriz</h3>
                <form method="post" enctype="multipart/form-data" action="{{ route('remittances.approve',$remittance) }}">
                    @csrf
                    @foreach($previews as $id=>$preview)<input type="hidden" name="expected_applied_cents[{{ $id }}]" value="{{ $preview['applied_cents'] }}">@endforeach
                    <label>Comprobante de ingreso en casa matriz<input type="file" name="approval_proof" accept=".jpg,.jpeg,.png,.pdf" required><small>Ingreso recibido en tu cuenta · JPG, PNG o PDF · Máximo 5 MB.</small></label>
                    <div class="receipt-review-actions">
                        <button class="btn primary" @disabled($previews->contains(fn($preview)=>$preview['blocked']))>Aprobar remesa</button>
                        <button type="button" class="btn secondary" @click="rejecting=!rejecting;if(rejecting)$nextTick(()=>$refs.rejectionReason.focus())" :aria-expanded="rejecting" aria-controls="remittance-rejection">Rechazar remesa</button>
                    </div>
                </form>
                <form id="remittance-rejection" method="post" action="{{ route('remittances.reject',$remittance) }}" class="receipt-review-rejection" x-show="rejecting" x-cloak>
                    @csrf
                    <label>Motivo del rechazo<input x-ref="rejectionReason" name="reason" value="{{ old('reason') }}" minlength="10" maxlength="1000" required></label>
                    <button class="btn danger">Confirmar rechazo</button>
                </form>
            </section>
        @endif
    </div>
</section>
@if($remittance->status==='approved')@can('review',$remittance)<details class="card form-card"><summary>Anular remesa y sus cobros</summary><p class="muted">Revierte todos sus pagos y aplicaciones. Se conserva el historial.</p><form method="post" action="{{ route('remittances.void',$remittance) }}" data-confirm="¿Anular la remesa completa y restaurar las deudas de sus cobros?">@csrf<label>Motivo de anulación<input name="reason" minlength="10" maxlength="1000" required></label><button class="btn danger">Anular remesa</button></form></details>@endcan @endif
</div>
</x-view-tabs>
@endsection