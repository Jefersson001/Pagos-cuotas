<div x-show="directPaymentItems.length" x-cloak class="mt-6">
    <h3>Productos de pago completo</h3>
    <p class="muted">Cada concepto se cobra por separado; no forma parte de la inicial ni de las cuotas.</p>
    <template x-for="item in directPaymentItems" :key="item.product_id">
        <div class="review-total"><span><span x-text="item.description"></span> × <span x-text="item.quantity"></span></span><strong x-text="money(cents(item.unit_price)*Number(item.quantity||0))"></strong></div>
    </template>
</div>
