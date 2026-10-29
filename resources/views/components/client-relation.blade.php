 <div class="relational-field" @click.outside="clientOpen=false">
  <label for="sale-client">Concesionario</label>
  <div class="relational-control"><i data-lucide="search"></i><input id="sale-client" type="text" role="combobox" aria-autocomplete="list" aria-controls="sale-client-options" :aria-expanded="clientOpen" :aria-activedescendant="activeClientIndex>=0 && clientOptions[activeClientIndex] ? 'sale-client-option-'+clientOptions[activeClientIndex].id : null" aria-describedby="sale-client-help" x-ref="clientInput" x-model="clientText" @input="clientInputChanged()" @focus="openClientOptions()" @keydown.arrow-down.prevent="moveClient(1)" @keydown.arrow-up.prevent="moveClient(-1)" @keydown.enter.prevent="chooseActiveClient()" @keydown.escape.stop="clientOpen=false" @keydown.tab="clientOpen=false" placeholder="Escribe el nombre o documento del concesionario" autocomplete="off" maxlength="120" required :aria-busy="clientLoading"></div>
  <input type="hidden" name="client_id" :value="clientId">
  <div id="sale-client-options" class="relational-options" role="listbox" aria-label="Concesionarios encontrados" x-show="clientOpen" x-cloak>
   <p class="relational-message" x-show="clientLoading" role="status">Consultando concesionarios…</p>
   <p class="relational-message text-red-600" x-show="clientLookupError" x-text="clientLookupError" role="alert"></p>
   <p class="relational-message" x-show="!clientLoading && !clientLookupError && clientOptions.length===0">No hay concesionarios que coincidan.</p>
   <template x-for="(client,index) in clientOptions" :key="client.id"><button type="button" role="option" :id="'sale-client-option-'+client.id" :aria-selected="String(client.id)===clientId" :class="{'is-active':activeClientIndex===index}" @mouseenter="activeClientIndex=index" @mousedown.prevent @click="selectClient(client)"><strong x-text="client.name"></strong><small x-text="client.document"></small></button></template>
   <p class="relational-message" x-show="clientHasMore && !clientLoading">Escribe más datos para precisar la búsqueda.</p>
  </div>
  <p id="sale-client-help" class="muted mt-2" x-text="selectedClient ? 'RIF: '+selectedClient.document : 'Selecciona una coincidencia para vincular el concesionario al pedido.'"></p>
 </div>
