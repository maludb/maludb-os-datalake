<?php
declare(strict_types=1);

/**
 * The NDJSON a K7 page or a postgres page lands as (sources-pulls.md divergences 2 and 3): one row object per line, byte-exact from the
 * decoded JSON (a K7 row) or the database's own text (a postgres row — every scalar written as a STRING, so a numeric(12,2) keeps its
 * trailing zero and a code keeps its leading ones; the writer's JSON reader types what it can before it casts everything back to text, so
 * only a date or a timestamp comes back in DuckDB's spelling).
 *
 *   dl_landing_ndjson($rows, $inject, $workDir[, $marker])   → the path of the file written under $workDir
 *   dl_pull_marker($pullId)                                  → a line of spaces and tabs that is this pull's alone
 *
 * The marker: a pull that REPLACES the dataset (the postgres weekly reload, a source with no watermark) must not be taken for a repeat — the
 * writer answers a file it has already loaded "already loaded" and then never reaches the replace, and the load's own key
 * ('<dataset>:<sha256>') would refuse the pages that follow the first (their bytes are last time's). EVERY page of such a pull therefore carries
 * the marker: whitespace before the first row is insignificant to every JSON reader, so the page's bytes (its sha256) differ from every earlier
 * landing while its rows do not.
 */

/** Write $rows (a list of row arrays) as NDJSON under $workDir, each row given the $inject fields (they replace a field of the same name); the path. */
function dl_landing_ndjson(array $rows, array $inject, string $workDir, string $marker = ''): string
{
    if (!is_dir($workDir) && !@mkdir($workDir, 0770, true) && !is_dir($workDir)) {
        throw new RuntimeException('The pull\'s scratch directory could not be made.');
    }
    $path = rtrim($workDir, '/') . '/page-' . bin2hex(random_bytes(6)) . '.ndjson';
    $fh = @fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('The pull\'s scratch file could not be opened.');
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;
    if ($marker !== '') {
        fwrite($fh, $marker . "\n");
    }
    foreach ($rows as $row) {
        $row = is_array($row) ? $row : ['value' => $row];
        fwrite($fh, json_encode($inject === [] ? $row : array_merge($row, $inject), $flags) . "\n");
    }
    fclose($fh);
    return $path;
}

/** A row's scalars as strings (null stays null; true/false become 'true'/'false'; a nested value becomes its JSON text) — a postgres page's rows. */
function dl_row_as_text(array $row): array
{
    $out = [];
    foreach ($row as $k => $v) {
        $out[(string) $k] = match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v) => (string) $v,
            default => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }
    return $out;
}

/** A line of spaces (0) and tabs (1) spelling the pull's number in binary: unique per pull, invisible to a JSON reader. */
function dl_pull_marker(int $pullId): string
{
    return strtr(decbin(max(1, $pullId)), ['0' => ' ', '1' => "\t"]);
}
