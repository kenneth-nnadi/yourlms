(function () {
  document.querySelectorAll('.comment-bank-pick').forEach(function (sel) {
    sel.addEventListener('change', function () {
      if (!sel.value) return;
      var form = sel.closest('form');
      var field = form && form.querySelector('.feedback-field');
      if (field) {
        field.value = field.value ? field.value + ' ' + sel.value : sel.value;
      }
      sel.value = '';
    });
  });

  function bindDrawer(opts) {
    var toggle = document.getElementById(opts.toggleId);
    var panel = document.getElementById(opts.panelId);
    var backdrop = document.getElementById(opts.backdropId);
    if (!toggle || !panel) return;

    var bodyClass = opts.bodyClass;
    var mq = window.matchMedia('(max-width: 768px)');

    function focusables() {
      return panel.querySelectorAll('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
    }

    function setOpen(open) {
      document.body.classList.toggle(bodyClass, open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? opts.closeLabel : opts.openLabel);
      if (backdrop) backdrop.hidden = !open;
    }

    toggle.addEventListener('click', function () {
      var open = !document.body.classList.contains(bodyClass);
      setOpen(open);
      if (open) {
        var first = focusables()[0];
        if (first) setTimeout(function () { first.focus(); }, 0);
      } else {
        setTimeout(function () { toggle.focus(); }, 0);
      }
    });

    if (backdrop) {
      backdrop.addEventListener('click', function () {
        setOpen(false);
        toggle.focus();
      });
    }

    panel.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (mq.matches) setOpen(false);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.body.classList.contains(bodyClass)) {
        setOpen(false);
        toggle.focus();
        return;
      }
      if (!document.body.classList.contains(bodyClass) || e.key !== 'Tab') return;
      var items = focusables();
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });

    mq.addEventListener('change', function (e) {
      if (!e.matches) setOpen(false);
    });
  }

  bindDrawer({
    toggleId: 'site-menu-toggle',
    panelId: 'site-menu',
    backdropId: 'site-menu-backdrop',
    bodyClass: 'site-menu-open',
    openLabel: 'Open menu',
    closeLabel: 'Close menu'
  });

  bindDrawer({
    toggleId: 'course-nav-toggle',
    panelId: 'course-sidebar',
    backdropId: 'course-nav-backdrop',
    bodyClass: 'course-nav-open',
    openLabel: 'Open course menu',
    closeLabel: 'Close course menu'
  });

  document.querySelectorAll('.notification-wrap').forEach(function (wrap) {
    var bellBtn = wrap.querySelector('.notification-bell-btn');
    var bellPanel = wrap.querySelector('.notification-dropdown');
    if (!bellBtn || !bellPanel) {
      return;
    }
    function setBellOpen(open) {
      bellPanel.hidden = !open;
      bellBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    bellBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setBellOpen(bellPanel.hidden);
    });
    document.addEventListener('click', function (e) {
      if (!bellPanel.hidden && !wrap.contains(e.target)) {
        setBellOpen(false);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !bellPanel.hidden) {
        setBellOpen(false);
        bellBtn.focus();
      }
    });
  });

  document.querySelectorAll('.rich-editor-wrap .ql-toolbar').forEach(function (bar) {
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting toolbar');
  });
})();