/*
 * The SQL editor's enhancements (slice 3 — query-service-editor.md; no third-party editor, ★32): completion of MY readable datasets' keys and
 * columns from the server-rendered list (#sql-completion), a click in the catalog inserts at the caret, Ctrl/⌘ + Enter runs, every run gets a
 * fresh request id (the page's prefix sql-{me}-, so the server accepts it) which Cancel names, Cancel shown while a run is in flight. Without
 * this file the form posts and the page comes back with the result. Event delegation: the screen may arrive by HTMX navigation.
 */
(function () {
    'use strict';
    var active = -1;

    function editor() { return document.getElementById('sql-editor'); }
    function completion() {
        var el = document.getElementById('sql-completion');
        if (!el) { return { keys: [], columns: {} }; }
        if (!el._data) { try { el._data = JSON.parse(el.textContent || '{}'); } catch (e) { el._data = { keys: [], columns: {} }; } }
        return el._data;
    }
    function newRequestId(form) {
        var bytes = new Uint8Array(8), hex = '';
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        for (var i = 0; i < bytes.length; i++) { hex += ('0' + bytes[i].toString(16)).slice(-2); }
        return (form.getAttribute('data-request-prefix') || 'sql-') + hex;
    }
    function insertAtCaret(ta, text, replaceLen) {
        var start = ta.selectionStart - (replaceLen || 0), end = ta.selectionEnd;
        ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
        ta.selectionStart = ta.selectionEnd = start + text.length;
        ta.focus();
    }
    function tokenBeforeCaret(ta) {
        var m = /[A-Za-z0-9_.]+$/.exec(ta.value.slice(0, ta.selectionStart));
        return m ? m[0] : '';
    }
    function suggestions(token) {
        var c = completion(), out = [], seen = {}, t = token.toLowerCase();
        if (t.length < 2) { return out; }
        var dot = t.lastIndexOf('.');
        if (dot > 0 && c.columns[t.slice(0, dot)]) {          // zone.name. → that dataset's columns
            var key = t.slice(0, dot), rest = t.slice(dot + 1);
            c.columns[key].forEach(function (col) { if (col.toLowerCase().indexOf(rest) === 0) { out.push({ label: col, insert: token.slice(0, dot + 1) + col }); } });
            return out.slice(0, 8);
        }
        c.keys.forEach(function (k) { if (k.indexOf(t) === 0 || k.split('.')[1].indexOf(t) === 0) { out.push({ label: k, insert: k }); seen[k] = 1; } });
        Object.keys(c.columns).forEach(function (k) {
            c.columns[k].forEach(function (col) { if (!seen[col] && col.toLowerCase().indexOf(t) === 0) { seen[col] = 1; out.push({ label: col, insert: col, hint: k }); } });
        });
        return out.slice(0, 8);
    }
    function hide() { var box = document.getElementById('sql-suggest'); if (box) { box.classList.add('d-none'); box.innerHTML = ''; } active = -1; }
    function show(ta) {
        var box = document.getElementById('sql-suggest'), token = tokenBeforeCaret(ta), list = suggestions(token);
        if (!box || list.length === 0) { hide(); return; }
        box.innerHTML = '';
        list.forEach(function (s, i) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'list-group-item list-group-item-action py-1 px-2 fs-12 font-monospace sql-suggestion';
            b.setAttribute('role', 'option'); b.setAttribute('data-insert', s.insert); b.setAttribute('data-replace', String(token.length));
            b.textContent = s.label + (s.hint ? '  · ' + s.hint : '');
            box.appendChild(b);
        });
        active = 0; mark(box);
        box.classList.remove('d-none');
    }
    function mark(box) { Array.prototype.forEach.call(box.children, function (b, i) { b.classList.toggle('active', i === active); }); }

    document.addEventListener('input', function (ev) {
        if (ev.target && ev.target.id === 'sql-editor') { show(ev.target); }
        if (ev.target && ev.target.id === 'sql-catalog-filter') {
            var q = ev.target.value.toLowerCase();
            document.querySelectorAll('.sql-catalog-item').forEach(function (d) {
                d.classList.toggle('d-none', q !== '' && d.textContent.toLowerCase().indexOf(q) === -1);
            });
        }
    });
    document.addEventListener('keydown', function (ev) {
        var ta = ev.target;
        if (!ta || ta.id !== 'sql-editor') { return; }
        var box = document.getElementById('sql-suggest'), open = box && !box.classList.contains('d-none');
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
            ev.preventDefault(); hide();
            var form = document.getElementById('sql-form'), run = document.getElementById('sql-run');
            if (form.requestSubmit) { form.requestSubmit(run); } else { run.click(); }
            return;
        }
        if (!open) { return; }
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault();
            active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + box.children.length) % box.children.length; mark(box);
        } else if (ev.key === 'Tab' || ev.key === 'Enter') {
            var b = box.children[Math.max(0, active)];
            if (b) { ev.preventDefault(); insertAtCaret(ta, b.getAttribute('data-insert'), parseInt(b.getAttribute('data-replace'), 10)); hide(); }
        } else if (ev.key === 'Escape') { hide(); }
    });
    document.addEventListener('click', function (ev) {
        var s = ev.target.closest ? ev.target.closest('.sql-suggestion') : null;
        if (s) { insertAtCaret(editor(), s.getAttribute('data-insert'), parseInt(s.getAttribute('data-replace'), 10)); hide(); return; }
        var ins = ev.target.closest ? ev.target.closest('.sql-insert') : null;
        if (ins && editor()) { ev.preventDefault(); insertAtCaret(editor(), ins.getAttribute('data-insert'), 0); return; }
        if (!(ev.target.closest && ev.target.closest('#sql-suggest'))) { hide(); }
    });
    // a fresh request id per run (Cancel names it) — set in the CAPTURE phase of submit, before htmx reads the form
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || form.id !== 'sql-form') { return; }
        var rid = newRequestId(form);
        var own = document.getElementById('sql-request-id'), cancel = document.getElementById('sql-cancel-request-id');
        if (own) { own.value = rid; }
        if (cancel) { cancel.value = rid; }
    }, true);
    document.addEventListener('htmx:beforeRequest', function (ev) {
        if (!ev.target || ev.target.id !== 'sql-form') { return; }
        var cf = document.getElementById('sql-cancel-form'), slot = document.getElementById('sql-cancel-slot');
        if (cf) { cf.classList.remove('d-none'); }
        if (slot) { slot.innerHTML = ''; }
    });
    document.addEventListener('htmx:afterRequest', function (ev) {
        if (ev.target && ev.target.id === 'sql-form') {
            var cf = document.getElementById('sql-cancel-form');
            if (cf) { cf.classList.add('d-none'); }
        }
    });
})();
