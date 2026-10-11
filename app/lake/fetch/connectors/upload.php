<?php
declare(strict_types=1);

/**
 * The `upload` connector (sources-pulls.md): NOT a way of fetching — a NAME for a recurring shape of file that people upload. It keeps the
 * target raw dataset and, when one is chosen, the saved mapping (import_templates) the upload screen offers for it. Nothing is pulled:
 * source_pull_now refuses it `manual_only`, the worker never finds it due (schedule_minutes is 0).
 */
final class DlConnectorUpload implements DlConnector
{
    public function capabilities(): array
    {
        return dl_capabilities(['label' => 'Uploaded by people', 'icon' => 'feather-upload', 'pulls' => false,
            'description' => 'A name for a file people upload again and again — the same shape each time, landing in one raw dataset. Nothing is fetched.']);
    }

    public function validate(array $in, array $current = []): array
    {
        $errors = [];
        $settings = [];
        $t = dl_template_setting($in['template_id'] ?? ($current['template_id'] ?? null), $errors);
        if ($t !== null) {
            $settings['template_id'] = $t;
        }
        return [$settings, $errors];
    }

    public function probe(array $source): array
    {
        return dl_probe_result('ok', 'An upload source fetches nothing — people upload into it.', ['pulls' => false]);
    }

    public function pull(array $source, callable $emit): array
    {
        throw new DlMisconfigured('manual_only: an upload source is a named shape for people\'s uploads — there is nothing to pull');
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        throw new DlNotSupported('unsupported');
    }

    public function lookup(array $source, array $identifiers): array
    {
        throw new DlNotSupported('unsupported');
    }
}
