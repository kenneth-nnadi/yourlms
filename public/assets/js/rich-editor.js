(function () {
  if (typeof Quill === 'undefined') return;

  var toolbar = [
    ['bold', 'italic', 'underline'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['link'],
    [{ header: [2, 3, false] }],
    ['clean'],
  ];

  document.querySelectorAll('textarea[data-rich-editor]').forEach(function (textarea) {
    if (textarea.dataset.richReady) return;
    textarea.dataset.richReady = '1';
    textarea.removeAttribute('required');
    textarea.style.display = 'none';

    var wrap = document.createElement('div');
    wrap.className = 'rich-editor-wrap';
    textarea.parentNode.insertBefore(wrap, textarea);

    var editor = document.createElement('div');
    editor.className = 'rich-editor-surface';
    wrap.appendChild(editor);

    var quill = new Quill(editor, {
      theme: 'snow',
      modules: { toolbar: toolbar },
    });

    var initial = textarea.value || '';
    if (initial.trim()) {
      var formatField = textarea.parentNode.querySelector('[data-rich-format]');
      var isHtml = formatField && formatField.value === 'html';
      if (isHtml || /<\/?[a-z][\s\S]*>/i.test(initial)) {
        quill.clipboard.dangerouslyPasteHTML(initial);
      } else {
        quill.setText(initial);
      }
    }

    var form = textarea.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        var plain = quill.getText().trim();
        var formatField = textarea.parentNode.querySelector('[data-rich-format]');
        if (!plain) {
          e.preventDefault();
          textarea.value = '';
          if (formatField) formatField.value = 'text';
          quill.focus();
          if (textarea.dataset.richRequired !== undefined) {
            alert('Please enter a message body.');
          }
          return;
        }
        var html = quill.root.innerHTML;
        textarea.value = html;
        if (formatField) formatField.value = 'html';
      });
    }
  });
})();