<?php
/**
 * The upload screen (uploads-loads.md): the drop zone (#upload-drop) mounted by assets/js/upload.js — each file a row (upload/partials/file-row.php
 * is the row's shape, cloned by the script) with its parts' progress, then Landed / Already here and "Map it"; the same form posts one file
 * whole when there is no JavaScript. My last uploads below. Data: mine, part_bytes, max_bytes, notice
 */
$tz = member_timezone();
?>
<?= view('shared/page-header.php', ['id' => 'upload', 'title' => 'Upload', 'crumbs' => [['Home', '/'], ['Upload', null]],
    'action' => hx_link('/templates', '<i class="feather-copy me-1"></i>Saved mappings', 'btn btn-light btn-touch', 'id="upload-templates-btn"')]) ?>
<div class="main-content" id="upload-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <form method="post" action="/upload/start" enctype="multipart/form-data" id="upload-form" class="upload-wrap"
              data-csrf="<?= e(csrf_token()) ?>" data-part-bytes="<?= (int) $part_bytes ?>" data-max-bytes="<?= (int) $max_bytes ?>">
            <?= csrf_field() ?>
            <div class="drop-zone border border-2 border-dashed rounded-3 p-4 text-center" id="upload-drop" tabindex="0" role="button" aria-describedby="upload-drop-help">
                <i class="feather-upload-cloud fs-1 text-primary d-block mb-2" aria-hidden="true"></i>
                <div class="fw-semibold">Drop spreadsheets here</div>
                <div class="fs-12 text-muted" id="upload-drop-help">CSV, TSV, Excel (xlsx, xls, ods), JSON or NDJSON, Parquet — up to <?= e(bytes_words($max_bytes)) ?> a file, sent in parts of <?= e(bytes_words($part_bytes)) ?>.</div>
                <label class="btn btn-primary btn-touch mt-3 mb-0" for="upload-files-input" id="upload-choose-btn"><i class="feather-file-plus me-1"></i>Choose files</label>
                <input type="file" class="visually-hidden" id="upload-files-input" name="file" multiple
                       accept=".csv,.tsv,.txt,.xlsx,.xlsm,.xls,.ods,.json,.ndjson,.jsonl,.parquet">
                <div class="mt-2 upload-nojs"><button type="submit" class="btn btn-light btn-touch" id="upload-submit-btn">Upload</button>
                    <div class="fs-11 text-muted mt-1">Without JavaScript one file at a time, up to 64 MB.</div></div>
            </div>
            <div class="fs-12 text-muted mt-2" id="upload-summary" aria-live="polite"></div>
            <div class="list-group mt-2" id="upload-list"></div>
        </form>
        <template id="upload-row-template"><?= view('upload/partials/file-row.php', []) ?></template>
    </div></div>
    <h6 class="fs-12 text-uppercase text-muted">Your last uploads</h6>
    <?php if ($mine === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="upload-mine-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-inbox"></i></span>
            <div><div class="fw-semibold">Nothing uploaded yet</div><div class="fs-12 text-muted">A file you drop lands here first; you map its columns, and it becomes a dataset in your sandbox.</div></div></div></div></div>
    <?php else: ?>
        <div class="list-group" id="upload-mine">
            <?php foreach ($mine as $u): [$cls, $word] = upload_badge((string) $u['status'], (string) $u['sniff_status']); ?>
                <div class="list-group-item d-flex flex-wrap align-items-center gap-2" id="upload-mine-<?= (int) $u['id'] ?>">
                    <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($u['filename']) ?></div>
                        <div class="fs-11 text-muted"><?= e(bytes_words((int) $u['byte_size'])) ?> · <?= e(format_ts($u['created_at'], $tz)) ?>
                            <?php if ($u['dataset_key'] !== null): ?> · into <?= hx_link('/datasets/' . $u['dataset_key'], e($u['dataset_key']), 'dataset-key') ?><?php endif; ?></div></div>
                    <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
                    <?php if ($u['load_id'] !== null): ?>
                        <?= hx_link('/loads/' . (int) $u['load_id'], 'The load', 'btn btn-sm btn-light btn-touch', 'id="upload-mine-' . (int) $u['id'] . '-load"') ?>
                    <?php elseif ($u['status'] === 'done'): ?>
                        <?= hx_link('/uploads/' . (int) $u['id'] . '/map', 'Map it', 'btn btn-sm btn-primary btn-touch', 'id="upload-mine-' . (int) $u['id'] . '-map"') ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
