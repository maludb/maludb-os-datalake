/* tag-editor.js — slice 1 (docs/build-specs/catalog.md): two small touches on the catalog's forms, both optional (every form works without
   JavaScript). Delegated from document, so a screen swapped in by HTMX needs no re-binding.
   1. [data-tag-input] — a field that names a tag by its words: shows the slug it will use (#sales-emea), or that it is an existing tag (the
      field's <datalist> holds the vocabulary), in the element its data-tag-preview names.
   2. [data-chips-input] — a comma-separated field (a term's synonyms): shows each value as a chip in the element its data-chips-preview names. */
(function () {
    'use strict';
    function slug(words) {
        return words.toLowerCase().trim().replace(/^#/, '').replace(/[^a-z0-9_-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '').slice(0, 50);
    }
    function preview(input) {
        var target = document.querySelector(input.getAttribute('data-tag-preview') || '');
        if (!target) { return; }
        var s = slug(input.value || '');
        if (s === '') { target.textContent = ''; return; }
        var list = input.list, known = null;
        if (list) {
            for (var i = 0; i < list.options.length; i++) {
                if (list.options[i].value === s) { known = list.options[i].textContent || s; break; }
            }
        }
        target.textContent = known ? 'The tag ' + known + ' (#' + s + ')' : 'A new tag: #' + s;
    }
    function chips(input) {
        var target = document.querySelector(input.getAttribute('data-chips-preview') || '');
        if (!target) { return; }
        target.textContent = '';
        (input.value || '').split(',').map(function (v) { return v.trim(); }).filter(function (v) { return v !== ''; }).slice(0, 30).forEach(function (v) {
            var chip = document.createElement('span');
            chip.className = 'badge text-bg-light border tag-chip';
            chip.textContent = v;
            target.appendChild(chip);
        });
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLInputElement)) { return; }
        if (t.hasAttribute('data-tag-input')) { preview(t); }
        if (t.hasAttribute('data-chips-input')) { chips(t); }
    });
    function init(root) {
        (root || document).querySelectorAll('input[data-chips-input]').forEach(chips);
    }
    document.addEventListener('DOMContentLoaded', function () { init(document); });
    document.body && document.body.addEventListener('htmx:afterSwap', function (e) { init(e.target); });
})();
