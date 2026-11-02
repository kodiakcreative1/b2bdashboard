/* Chamber B2B Dashboard — GPL-2.0-or-later */
(() => {
  const tbody = document.getElementById('cb2b-groups');
  let next = Date.now();
  document.getElementById('cb2b-add')?.addEventListener('click', () => {
    tbody.insertAdjacentHTML('beforeend', document.getElementById('cb2b-group-template').innerHTML.replaceAll('__INDEX__', String(next++)));
  });
  tbody?.addEventListener('click', event => {
    if (event.target.classList.contains('cb2b-remove')) event.target.closest('tr').remove();
  });
  document.getElementById('cb2b-media')?.addEventListener('click', () => {
    const frame = wp.media({ title: 'Choose verified Chamber logo', library: { type: 'image' }, multiple: false });
    frame.on('select', () => { document.getElementById('cb2b-logo').value = frame.state().get('selection').first().toJSON().url; });
    frame.open();
  });
})();
