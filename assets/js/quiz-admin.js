(function () {
  function syncPanels(form, type) {
    if (!form) return;
    form.querySelectorAll('.quiz-type-panel').forEach(function (panel) {
      panel.style.display = panel.dataset.type === type ? 'block' : 'none';
    });
  }
  document.querySelectorAll('.quiz-q-type-select').forEach(function (sel) {
    var form = sel.closest('form');
    syncPanels(form, sel.value);
    sel.addEventListener('change', function () { syncPanels(form, sel.value); });
  });
})();