@php($isApproved=$receipt->approval_status==='approved')
<section class="card form-card mb-6 receipt-review" data-receipt-review>
    <header class="receipt-review-heading">
        <h2>{{ $isApproved ? 'Pago aprobado' : 'Pendiente de aprobación' }}</h2>
        @if($isApproved && $receipt->approved_at)
            <p>{{ $receipt->approved_at->format('d/m/Y H:i') }}{{ $receipt->approver ? ' · '.$receipt->approver->name : '' }}</p>
        @endif
    </header>
    <div class="receipt-review-meta">
        <div><small>Referencia:</small> <strong>{{ $receipt->reference ?: 'Sin referencia' }}</strong></div>
        <div><small>Fecha de pago:</small> <strong>{{ $receipt->payment_date?->format('d/m/Y') ?? 'No informada' }}</strong></div>
        <div><small>Método:</small> <strong>{{ $receipt->method->name }}</strong></div>
    <p class="receipt-review-sale">
        @if($receipt->sourceOrder)
            Venta relacionada: <strong>#{{ $receipt->source_order_id }} · {{ $receipt->sourceOrder->description }}</strong>
            @if($receipt->intendedInstallment)<span> · {{ $receipt->intendedInstallment->label }}</span>@endif
        @elseif($isApproved)
            Pago registrado sin venta relacionada.
        @else
            Sin venta relacionada: el importe aprobado quedará como saldo a favor.
        @endif
    </p>
    </div>
    <div class="receipt-review-amounts">
        <div><small>Recibido</small><strong>${{ number_format($receipt->amount,2) }}</strong></div>
        <div><small>{{ $isApproved ? 'Aplicado a ventas' : 'A la venta' }}</small><strong>${{ number_format(($isApproved ? $receipt->applied_cents : $approvalPreview['applied_cents'])/100,2) }}</strong></div>
        <div><small>{{ $isApproved ? 'Saldo a favor disponible' : 'Saldo a favor' }}</small><strong>${{ number_format(($isApproved ? $receipt->available_cents : $approvalPreview['credit_cents'])/100,2) }}</strong></div>
    </div>
    @if(!$isApproved && $approvalPreview['changed'])<p class="notice receipt-review-warning">La deuda cambió. Se habían previsto ${{ number_format($receipt->intended_amount,2) }} para la venta; revisa los importes actuales.</p>@endif
    @if(!$isApproved && $approvalPreview['blocked'])<p class="notice error receipt-review-warning">La venta o el compromiso ya no permite aplicar este pago. Rechaza el registro y solicita su corrección.</p>@endif
    <div @class(['receipt-review-grid','receipt-review-readonly'=>!$isApproved && ($readOnlyPortfolio||!auth()->user()->can('review',$receipt))])>
        <section class="receipt-review-source receipt-proof">
            <h3>Comprobante del cliente</h3>
            @if($receipt->proof_path)
                @if(in_array(strtolower(pathinfo($receipt->proof_path, PATHINFO_EXTENSION)),['jpg','jpeg','png'],true))
                    <a class="receipt-review-preview" href="{{ route('receipts.proof',['receipt'=>$receipt,'preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ampliar comprobante del cliente"><img src="{{ route('receipts.proof',['receipt'=>$receipt,'preview'=>1]) }}" alt="Comprobante del cliente" loading="lazy"></a>
                @endif
                <div class="receipt-review-links">
                    <a class="btn secondary" href="{{ route('receipts.proof',['receipt'=>$receipt,'preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ver comprobante del cliente">Ver</a>
                    <a class="btn secondary" href="{{ route('receipts.proof',$receipt) }}" aria-label="Descargar comprobante del cliente">Descargar</a>
                </div>
            @else
                <p class="muted">Sin comprobante adjunto.</p>
            @endif
            @if(!$isApproved)
            @can('update',$receipt)
                <details class="receipt-review-replace"><summary class="text-link">{{ $receipt->proof_path?'Cambiar comprobante':'Adjuntar comprobante' }}</summary>
                    <form method="post" enctype="multipart/form-data" action="{{ route('receipts.proof.store',$receipt) }}">@csrf
                        <label>Comprobante del cliente<input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required><small>JPG, PNG o PDF · Máximo 5 MB.</small></label>
                        <button class="btn secondary">Guardar comprobante</button>
                    </form>
                </details>
            @endcan
            @endif
        </section>
        @if($isApproved)
            <section class="receipt-review-approval receipt-proof">
                <h3>Comprobante de casa matriz</h3>
                @if($receipt->approval_proof_path)
                    @if(in_array(strtolower(pathinfo($receipt->approval_proof_path, PATHINFO_EXTENSION)),['jpg','jpeg','png'],true))
                        <a class="receipt-review-preview" href="{{ route('receipts.approval-proof',['receipt'=>$receipt,'preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ampliar comprobante de casa matriz"><img src="{{ route('receipts.approval-proof',['receipt'=>$receipt,'preview'=>1]) }}" alt="Comprobante de casa matriz" loading="lazy"></a>
                    @endif
                    <div class="receipt-review-links">
                        <a class="btn secondary" href="{{ route('receipts.approval-proof',['receipt'=>$receipt,'preview'=>1]) }}" target="_blank" rel="noopener" aria-label="Ver comprobante de casa matriz">Ver</a>
                        <a class="btn secondary" href="{{ route('receipts.approval-proof',$receipt) }}" aria-label="Descargar comprobante de casa matriz">Descargar</a>
                    </div>
                @else
                    <p class="muted">Este pago fue aprobado sin comprobante de casa matriz antes de este control o registrado directamente por el administrador.</p>
                @endif
            </section>
        @elseif($receipt->collection_channel==='concessionaire')
            <section class="receipt-review-approval"><h3>Envío a casa matriz</h3><p class="muted">Este cobro fue recibido por el concesionario. Se confirma mediante la aprobación de su remesa.</p>
            @if($receipt->remittance_id)<a class="btn secondary" href="{{ route('remittances.show',$receipt->remittance_id) }}">Ver remesa REM-{{ $receipt->remittance_id }}</a>
            @else<p>Pendiente de incluir en una remesa.</p>@can('create',\App\Models\Remittance::class)<a class="btn secondary" href="{{ route('remittances.create',['concessionaire_id'=>$receipt->collecting_concessionaire_id]) }}">Preparar remesa</a>@endcan @endif
            </section>
        @elseif(!$readOnlyPortfolio)
            @can('review',$receipt)
                <section class="receipt-review-approval" x-data="{rejecting:@js($errors->has('reason'))}">
                    <h3>Confirmación de casa matriz</h3>
                    <form method="post" enctype="multipart/form-data" action="{{ route('receipts.approve',$receipt) }}" data-confirm="¿Confirmar que el dinero ingresó a la cuenta de casa matriz y aprobar este pago?">
                        @csrf<input type="hidden" name="expected_applied_cents" value="{{ $approvalPreview['applied_cents'] }}">
                        <label>Comprobante de ingreso en casa matriz<input type="file" name="approval_proof" accept=".jpg,.jpeg,.png,.pdf" required><small>Ingreso recibido en tu cuenta · JPG, PNG o PDF · Máximo 5 MB.</small></label>
                        <div class="receipt-review-actions">
                            <button class="btn primary" @disabled($approvalPreview['blocked'])>Aprobar pago</button>
                            <button type="button" class="btn secondary" @click="rejecting=!rejecting;if(rejecting)$nextTick(()=>$refs.rejectionReason.focus())" :aria-expanded="rejecting" aria-controls="receipt-rejection">Rechazar pago</button>
                        </div>
                    </form>
                    <form id="receipt-rejection" method="post" action="{{ route('receipts.reject',$receipt) }}" class="receipt-review-rejection" x-show="rejecting" x-cloak>@csrf
                        <label>Motivo del rechazo<input x-ref="rejectionReason" name="reason" value="{{ old('reason') }}" required minlength="10" maxlength="1000"></label>
                        <button class="btn danger">Confirmar rechazo</button>
                    </form>
                </section>
            @endcan
        @endif
    </div>
</section>
