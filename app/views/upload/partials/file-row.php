<?php /** One file on the upload screen (cloned by assets/js/upload.js — ids upload-file-{n}, upload-map-{n}): the name, the size, the state, the parts' progress, the message. */ ?>
<div class="list-group-item upload-row">
    <div class="d-flex align-items-center gap-2 min-w-0">
        <div class="fw-semibold text-truncate flex-grow-1 min-w-0 upload-name"></div>
        <span class="fs-11 text-muted text-nowrap upload-size"></span>
        <span class="badge bg-soft-secondary text-secondary upload-state">Queued</span>
    </div>
    <div class="progress mt-1" style="height: 6px;"><div class="progress-bar upload-bar" role="progressbar" style="width: 0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
    <div class="fs-11 text-muted mt-1 upload-msg"></div>
</div>
