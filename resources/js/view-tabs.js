export function initializeViewTabs() {
 document.querySelectorAll('[data-view-tabs]').forEach((group, groupIndex) => {
  const panels = [...group.children].filter(child => child.hasAttribute('data-tab-panel'));
  if (panels.length < 2) return;
  const nav = document.createElement('div');
  nav.className = 'view-tabs-nav';
  nav.setAttribute('role', 'tablist');
  nav.setAttribute('aria-label', group.dataset.tabsLabel || 'Secciones de la ficha');
  const prefix = group.id || `view-tabs-${groupIndex}`;
  const printMedia = window.matchMedia('print');
  let selectedIndex = 0;
  const buttons = panels.map((panel, index) => {
   panel.id ||= `${prefix}-${panel.dataset.tabKey || index}`;
   panel.setAttribute('role', 'tabpanel');
   const button = document.createElement('button');
   button.type = 'button';
   button.id = `${panel.id}-tab`;
   button.textContent = panel.dataset.tabPanel;
   button.setAttribute('role', 'tab');
   button.setAttribute('aria-controls', panel.id);
   panel.setAttribute('aria-labelledby', button.id);
   panel.tabIndex = 0;
   nav.append(button);
   return button;
  });
  function select(index, updateUrl = false) {
   selectedIndex = index;
   panels.forEach((panel, i) => {
    panel.hidden = !printMedia.matches && i !== index;
    buttons[i].setAttribute('aria-selected', String(i === index));
    buttons[i].tabIndex = i === index ? 0 : -1;
   });
   if (updateUrl) {
    const url = new URL(location.href);
    url.hash = panels[index].id;
    history.replaceState(history.state, '', url);
   }
  }
  function revealHash() {
   let target;
   try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch { return false; }
   const index = panels.findIndex(panel => panel === target || panel.contains(target));
   if (index < 0) return false;
   select(index);
   return true;
  }
  buttons.forEach((button, index) => {
   button.addEventListener('click', () => select(index, true));
   button.addEventListener('keydown', event => {
    let next;
    if (event.key === 'ArrowRight') next = (index + 1) % buttons.length;
    if (event.key === 'ArrowLeft') next = (index + buttons.length - 1) % buttons.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = buttons.length - 1;
    if (next === undefined) return;
    event.preventDefault();
    select(next, true);
    buttons[next].focus();
    buttons[next].scrollIntoView({block: 'nearest', inline: 'nearest'});
   });
  });
  group.addEventListener('invalid', event => {
   const index = panels.findIndex(panel => panel.contains(event.target));
   if (index >= 0) select(index);
  }, true);
  window.addEventListener('hashchange', revealHash);
  printMedia.addEventListener('change', () => select(selectedIndex));
  group.prepend(nav);
  const initial = panels.findIndex(panel => panel.dataset.tabKey === group.dataset.defaultTab);
  select(initial < 0 ? 0 : initial);
  revealHash();
 });
}
