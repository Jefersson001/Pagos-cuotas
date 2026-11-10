<p class="muted mb-6">Primero los productos de pago completo, luego la inicial y después las cuotas.</p>
<div class="table-scroll"><table data-sale-schedule>
    <thead><tr><th>Concepto</th><th>Vencimiento</th><th>Programado</th></tr></thead>
    <tbody>
        <template x-for="item in directPaymentItems" :key="item.product_id">
            <tr><td x-text="'Pago completo: '+item.description"></td><td>Al crear la venta</td><td x-text="money(cents(item.unit_price)*Number(item.quantity||0))"></td></tr>
        </template>
        <tr x-show="cents(initial)>0"><td>Inicial</td><td>Al crear la venta</td><td x-text="money(cents(initial))"></td></tr>
        <template x-for="(row,index) in schedule" :key="index">
            <tr><td x-text="`Cuota ${index+1}`"></td>
                <td><template x-if="frequency==='custom'"><input type="date" :name="`custom[${index}][due_date]`" x-model="custom[index].due_date" :aria-label="'Vencimiento de cuota '+(index+1)" required></template><span x-show="frequency!=='custom'" x-text="row.date"></span></td>
                <td><template x-if="frequency==='custom'"><input type="number" step="0.01" min="0.01" :name="`custom[${index}][amount]`" x-model="custom[index].amount" :aria-label="'Importe de cuota '+(index+1)" required></template><strong x-show="frequency!=='custom'" x-text="money(row.amount)"></strong></td>
            </tr>
        </template>
    </tbody>
</table></div>
<div x-show="frequency==='custom' && balance>0" class="review-total"><span>Total de cuotas programadas</span><strong x-text="money(customTotal)" :class="{'text-red-600':customTotal!==balance}"></strong></div>
