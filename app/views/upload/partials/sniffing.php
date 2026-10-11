<?php /** "Reading the file…" — the sniff runs in the writer; this polls /uploads/{id}/sniff every 2 s and the door answers the mapping once read (HX-Location). Without JavaScript: a link to look again. Data: u */ ?>
<div class="card" id="map-sniffing" hx-get="/uploads/<?= (int) $u['id'] ?>/sniff" hx-trigger="every 2s" hx-target="this" hx-swap="outerHTML"><div class="card-body">
    <div class="empty-state"><span class="avatar-text avatar-lg rounded"><span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span></span>
        <div><div class="fw-semibold">Reading the file…</div>
            <div class="fs-12 text-muted">The lake finds its format, its columns, their types and what looks personal. A large file takes a moment.</div>
            <a class="fs-12 fw-semibold" href="/uploads/<?= (int) $u['id'] ?>/map" id="map-sniffing-reload">Look again</a></div></div>
</div></div>
