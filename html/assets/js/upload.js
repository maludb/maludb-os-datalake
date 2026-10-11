/* upload.js — slice 2 (docs/build-specs/uploads-loads.md): the upload screen's uploader, DocCloud's protocol. Mounted on #upload-form when the
   screen is there (the page's first load or an HTMX swap). Each file: POST /upload/start {filename, byte_size, sha256 (a file ≤ 64 MB is hashed
   here first), upload (a session this browser started for the same file — the resume)} → its parts of part_bytes, three in flight, each retried
   three times, the ones the server already holds skipped → POST /upload/finish → "Landed" or "Already here", and "Map it". Nothing of a file is
   read into memory beyond one part (and the hash of a small one). The session id is kept in localStorage per file until it lands, so a broken
   connection — even a closed tab — carries on from the part it stopped at. Without this script the form posts one file whole. */
(function () {
  'use strict';
  var MAX_INFLIGHT = 3, HASH_MAX = 64 * 1048576;
  function fmt(n) { var u = ['B', 'KB', 'MB', 'GB']; var i = 0; var v = n; while (v >= 1024 && i < 3) { v /= 1024; i++; } return (i ? v.toFixed(1) : v) + ' ' + u[i]; }
  function store(k, v) { try { if (v === null) { localStorage.removeItem(k); } else { localStorage.setItem(k, v); } } catch (e) { /* private mode: no resume across tabs */ } }
  function recall(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

  function mount(form) {
    if (!form || form.dataset.mounted) { return; }
    form.dataset.mounted = '1';
    var csrf = form.dataset.csrf, maxBytes = parseInt(form.dataset.maxBytes, 10) || 0;
    var list = form.querySelector('#upload-list'), summary = form.querySelector('#upload-summary'), zone = form.querySelector('#upload-drop');
    var input = form.querySelector('#upload-files-input'), tpl = document.getElementById('upload-row-template');
    var queue = [], finished = 0, n = 0, running = 0;
    form.classList.add('js-upload');
    var nojs = form.querySelector('.upload-nojs'); if (nojs) { nojs.classList.add('d-none'); }

    function headers(extra) { var h = { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }; for (var k in (extra || {})) { h[k] = extra[k]; } return h; }
    function post(url, fields) {
      var body = new URLSearchParams(); for (var k in fields) { if (fields[k] !== null && fields[k] !== undefined && fields[k] !== '') { body.append(k, fields[k]); } }
      return fetch(url, { method: 'POST', headers: headers({ 'Content-Type': 'application/x-www-form-urlencoded' }), body: body.toString(), credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, json: j }; }); });
    }
    function state(row, cls, text) { row.badge.className = 'badge bg-soft-' + cls + ' text-' + cls + ' upload-state'; row.badge.textContent = text; row.el.dataset.state = cls; }
    function progress(row, pct) { row.bar.style.width = Math.max(0, Math.min(100, pct)) + '%'; row.bar.setAttribute('aria-valuenow', String(Math.round(pct))); }
    function sum() { summary.textContent = queue.length ? finished + ' of ' + queue.length + ' done' : ''; }
    function keyOf(f) { return 'dl-upload:' + f.name + ':' + f.size + ':' + (f.lastModified || 0); }

    function addRow(file) {
      n++;
      var wrap = document.createElement('div'); wrap.innerHTML = tpl ? tpl.innerHTML : '<div class="list-group-item upload-row"></div>';
      var el = wrap.firstElementChild; el.id = 'upload-file-' + n; el.dataset.name = file.name;
      var row = { file: file, n: n, el: el, badge: el.querySelector('.upload-state'), bar: el.querySelector('.upload-bar'), msg: el.querySelector('.upload-msg') };
      el.querySelector('.upload-name').textContent = file.name; el.querySelector('.upload-size').textContent = fmt(file.size);
      list.appendChild(el); queue.push(row); return row;
    }

    function sha256(file) {
      if (!window.crypto || !crypto.subtle || file.size > HASH_MAX) { return Promise.resolve(''); }
      return file.arrayBuffer().then(function (buf) { return crypto.subtle.digest('SHA-256', buf); })
        .then(function (h) { return Array.prototype.map.call(new Uint8Array(h), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); })
        .catch(function () { return ''; });
    }

    function sendPart(row, s, k, attempt) {
      var start = (k - 1) * s.part_bytes, blob = row.file.slice(start, Math.min(row.file.size, start + s.part_bytes));
      return fetch('/upload/part?upload=' + s.upload_id + '&n=' + k, { method: 'POST', headers: headers({ 'Content-Type': 'application/octet-stream' }), body: blob, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (r.status !== 200) { var e = new Error((j.error && j.error.message) || 'Part ' + k + ' failed.'); e.status = r.status; throw e; } return j; }); })
        .catch(function (e) {
          var a = attempt || 0;
          if (a < 3 && !(e.status && e.status >= 400 && e.status < 500)) { return new Promise(function (res) { setTimeout(res, 600 * (a + 1)); }).then(function () { return sendPart(row, s, k, a + 1); }); }
          throw e;
        });
    }

    function parts(row, s) {
      var have = {}; (s.parts_received || []).forEach(function (p) { have[p] = true; });
      var todo = []; for (var k = 1; k <= s.parts_total; k++) { if (!have[k]) { todo.push(k); } }
      var done = s.parts_total - todo.length, failed = null, inflight = 0;
      progress(row, done / s.parts_total * 100);
      state(row, 'info', (s.resumed ? 'Resuming ' : 'Uploading ') + done + '/' + s.parts_total);
      return new Promise(function (resolve, reject) {
        function pump() {
          if (failed) { if (inflight === 0) { reject(failed); } return; }
          if (!todo.length && inflight === 0) { resolve(); return; }
          while (inflight < MAX_INFLIGHT && todo.length) {
            (function (k) {
              inflight++;
              sendPart(row, s, k).then(function () { inflight--; done++; progress(row, done / s.parts_total * 100); state(row, 'info', 'Uploading ' + done + '/' + s.parts_total); pump(); },
                function (e) { inflight--; failed = e; pump(); });
            })(todo.shift());
          }
        }
        pump();
      });
    }

    function finish(row, s) {
      state(row, 'info', 'Checking');
      return post('/upload/finish', { upload: s.upload_id }).then(function (r) {
        if (r.status !== 200 || !r.json.ok) { var e = new Error((r.json.error && r.json.error.message) || 'The upload could not be finished.'); e.status = r.status; throw e; }
        store(keyOf(row.file), null);
        progress(row, 100);
        state(row, 'success', r.json.deduped ? 'Already here' : 'Landed');
        row.msg.textContent = r.json.deduped ? 'These bytes landed before — nothing was stored twice. ' : '';
        var a = document.createElement('a'); a.href = r.json.map_url; a.id = 'upload-map-' + row.n; a.className = 'btn btn-sm btn-primary btn-touch mt-1'; a.textContent = 'Map it';
        a.setAttribute('hx-get', r.json.map_url); a.setAttribute('hx-target', '#page-content'); a.setAttribute('hx-swap', 'innerHTML'); a.setAttribute('hx-push-url', r.json.map_url);
        row.msg.appendChild(a); if (window.htmx) { htmx.process(a); }
      });
    }

    function run(row) {
      if (maxBytes && row.file.size > maxBytes) { state(row, 'danger', 'Too large'); row.msg.textContent = 'A file is at most ' + fmt(maxBytes) + '.'; return Promise.resolve(); }
      state(row, 'info', 'Hashing');
      var key = keyOf(row.file);
      return sha256(row.file).then(function (sha) {
        return post('/upload/start', { filename: row.file.name, byte_size: row.file.size, sha256: sha, upload: recall(key) || '' });
      }).then(function (r) {
        if (r.status !== 200 || !r.json.ok) { var e = new Error((r.json.error && r.json.error.message) || 'The upload could not start.'); e.status = r.status; throw e; }
        store(key, String(r.json.upload_id));
        row.el.dataset.upload = String(r.json.upload_id);
        return parts(row, r.json).then(function () { return finish(row, r.json); });
      }).catch(function (e) {
        state(row, 'danger', e.status && e.status < 500 ? 'Refused' : 'Stopped');
        row.msg.textContent = (e.message || String(e)) + (e.status && e.status < 500 ? '' : ' Drop the same file again to carry on from where it stopped.');
      });
    }

    function next() {
      while (running < 2) {
        var row = queue.filter(function (r) { return !r.started; })[0];
        if (!row) { return; }
        row.started = true; running++;
        run(row).then(function () { running--; finished++; sum(); next(); });
      }
    }
    function add(files) { for (var i = 0; i < files.length; i++) { if (files[i] && files[i].size >= 0) { addRow(files[i]); } } sum(); next(); }

    input.addEventListener('change', function () { add(input.files); input.value = ''; });
    form.addEventListener('submit', function (e) { e.preventDefault(); if (input.files && input.files.length) { add(input.files); input.value = ''; } });
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('border-primary'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('border-primary'); }); });
    zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) { add(e.dataTransfer.files); } });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
  }
  function scan() { mount(document.getElementById('upload-form')); }
  document.addEventListener('DOMContentLoaded', scan);
  document.addEventListener('htmx:load', scan);
  window.DlUpload = { mount: mount };
})();
