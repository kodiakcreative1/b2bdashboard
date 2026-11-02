/* Progressive enhancement only: all navigation, filtering, notes and chart data work without JS. */
(() => {
  document.querySelectorAll('.cb2b-filters input[type="date"]').forEach(input => {
    input.addEventListener('change', () => {
      const form = input.form;
      const from = form.elements.from;
      const to = form.elements.to;
      to.min = from.value;
      from.max = to.value;
    });
  });
})();
