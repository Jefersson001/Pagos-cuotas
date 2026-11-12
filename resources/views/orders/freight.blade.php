@php($freightItem=$order->items->firstWhere('is_freight',true))
@if($freightItem)
<section class="card form-card" data-sale-freight>
    <h2>Flete</h2>
    <div class="detail-grid"><div><small>Servicio</small><strong>{{ $freightItem->description }}</strong></div><div><small>Importe acordado</small><strong>${{ number_format($freightItem->unit_price,2) }}</strong></div></div>
</section>
@elseif(!$readOnlyPortfolio && auth()->user()->can('update',$order) && $order->canAddFreight())
@php($freightProducts=$freightProducts??collect())
<section class="card form-card" data-sale-freight>
    <h2>Agregar flete</h2>
    @if($freightProducts->isEmpty())
        <p class="muted">No hay servicios de flete activos en el catálogo.</p>
    @else
        <form method="post" action="{{ route('orders.freight.store',$order) }}" x-data="{productId:@js((string)old('freight_product_id','')),amount:@js(old('freight_amount','')),products:@js($freightProducts->map(fn($product)=>['id'=>(string)$product->id,'price'=>$product->price])->values())}">
            @csrf
            <div class="form-grid">
                <label>Servicio de flete<select name="freight_product_id" x-model="productId" @change="amount=products.find(product=>product.id===productId)?.price||''" required><option value="">Selecciona el flete</option>@foreach($freightProducts as $product)<option value="{{ $product->id }}" @selected(old('freight_product_id')==$product->id)>{{ $product->name }}</option>@endforeach</select></label>
                <label>Importe del flete ($)<input name="freight_amount" type="number" x-model="amount" min="0.01" max="9999999999.99" step="0.01" required></label>
            </div>
            <p class="muted">Se añadirá como un compromiso separado, con vencimiento junto a la última cuota. Puedes ajustar el importe antes de agregarlo.</p>
            <div class="form-actions"><button class="btn primary">Agregar flete</button></div>
        </form>
    @endif
</section>
@endif
