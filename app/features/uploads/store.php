<?php
declare(strict_types=1);

/**
 * THE LANDING STORE (uploads-loads.md; lake-engine.md §2 "The data directory"): DL_STORAGE_DIR/landing/ is where every file the lake is
 * given lands, byte for byte, under its own hash — `landing/<aa>/<bb>/<sha256>` (`blobs.storage_path` is relative) — de-duplicated: the same
 * bytes land ONCE however many times they are uploaded (`blobs.sha256` unique; `ref_count` follows the loads, db/009's trigger).
 *
 *   dl_landing_store($pdo, $path, $suggestedName)   THE one landing function (upload_finish calls it after assembling the parts; slice 6's
 *                                                   pulls call it for every file and page they land): hash, judge the bytes (an executable
 *                                                   is refused), dedupe into `blobs`, move into landing/<aa>/<bb>/<sha256>
 *   store_part_path / store_parts_dir               an upload's parts while they arrive: landing/.parts/<upload>/<n> — PHP's own temporary
 *                                                   files, removed by PHP once assembled or discarded
 * PHP NEVER deletes a landed blob (lake-engine §6 decision 7): the writer unlinks landing files when their window passes (slice 8). PHP reads
 * nothing of a landed file but its bytes while hashing — the sniff and the load are the writer's.
 */

final class UploadRefused extends DomainException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

/** The data directory (DL_STORAGE_DIR; dl_settings.storage_dir overrides when set). */
function dl_storage_dir(): string
{
    $dir = rtrim((string) env('DL_STORAGE_DIR', ''), '/');
    if ($dir === '') {
        throw new UploadRefused(503, 'storage', 'The lake has no data directory configured.');
    }
    return $dir;
}

function store_parts_dir(int $upload): string
{
    return dl_storage_dir() . '/landing/.parts/' . $upload;
}

function store_part_path(int $upload, int $partNo): string
{
    return store_parts_dir($upload) . '/' . $partNo;
}

/** A blob's relative path: landing/<aa>/<bb>/<sha256>. */
function landing_relative(string $sha): string
{
    return 'landing/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
}

/** Remove an upload's own part files (never a blob). */
function store_drop_parts(int $upload): void
{
    $dir = store_parts_dir($upload);
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) glob($dir . '/*') as $f) {
        if (is_file((string) $f)) {
            @unlink((string) $f);
        }
    }
    @rmdir($dir);
}

/** The sha256 of a file, streamed. */
function store_sha256(string $path): string
{
    $h = hash_init('sha256');
    $in = fopen($path, 'rb');
    if ($in === false) {
        throw new UploadRefused(503, 'storage', 'The file could not be read back.');
    }
    while (!feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === false || $chunk === '') {
            break;
        }
        hash_update($h, $chunk);
    }
    fclose($in);
    return hash_final($h);
}

/** MIME types that are programs, never data (the bytes judged by finfo, then the magic numbers finfo may miss). */
const STORE_EXECUTABLE_MIMES = ['application/x-executable', 'application/x-sharedlib', 'application/x-pie-executable', 'application/x-mach-binary',
    'application/x-dosexec', 'application/vnd.microsoft.portable-executable', 'application/x-msdownload', 'application/x-elf', 'text/x-shellscript',
    'application/x-shellscript', 'text/x-perl', 'text/x-python', 'application/java-archive', 'application/x-java-applet'];

/** Is this file a program? finfo's judgment of the bytes, and the magic numbers: ELF, MZ (Windows), Mach-O, a #! script. */
function store_is_executable(string $path): bool
{
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (in_array($mime, STORE_EXECUTABLE_MIMES, true)) {
        return true;
    }
    $f = fopen($path, 'rb');
    $head = $f === false ? '' : (string) fread($f, 4);
    if ($f !== false) {
        fclose($f);
    }
    return str_starts_with($head, "\x7fELF") || str_starts_with($head, '#!')                       // MZ is finfo's (a CSV may start "MZ_code,")
        || in_array(bin2hex($head), ['feedface', 'feedfacf', 'cefaedfe', 'cffaedfe'], true);
}

/**
 * THE one landing function. $path: a complete file PHP may move (an assembled upload, a pulled page); $suggestedName: the name it came with
 * (logged by its extension only). Hashes it, refuses a program (422 executable), and lands it once: an existing blob of these bytes (its file
 * present) is reused and $path removed — deduped; a blob whose file was purged gets its file back; else a new blob row and the file moved to
 * landing/<aa>/<bb>/<sha256> (0640 — the writer reads it through the directory's group). Returns [blob_id, sha256, path, deduped, byte_size].
 */
function dl_landing_store(PDO $pdo, string $path, string $suggestedName): array
{
    if (!is_file($path)) {
        throw new UploadRefused(503, 'storage', 'The file to land is missing.');
    }
    if (store_is_executable($path)) {
        @unlink($path);
        throw new UploadRefused(422, 'executable', 'That file is a program, not data — the lake takes spreadsheets, CSV, JSON and Parquet.');
    }
    $size = (int) filesize($path);
    $sha = store_sha256($path);
    $rel = landing_relative($sha);
    $abs = dl_storage_dir() . '/' . $rel;
    $pdo->prepare('INSERT INTO blobs (sha256, byte_size, storage_path) VALUES (:s, :n, :p) ON CONFLICT (sha256) DO NOTHING')
        ->execute(['s' => $sha, 'n' => $size, 'p' => $rel]);
    $st = $pdo->prepare('SELECT id, storage_path, deleted_at FROM blobs WHERE sha256 = :s');
    $st->execute(['s' => $sha]);
    $blob = $st->fetch();
    $existing = dl_storage_dir() . '/' . $blob['storage_path'];
    if ($blob['deleted_at'] === null && is_file($existing) && $existing !== $path) {
        @unlink($path);                                                // the same bytes are already here
        return ['blob_id' => (int) $blob['id'], 'sha256' => $sha, 'path' => $blob['storage_path'], 'deduped' => true, 'byte_size' => $size];
    }
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new UploadRefused(503, 'storage', 'The landing area is not writable.');
    }
    if (!@rename($path, $abs)) {
        if (!@copy($path, $abs)) {
            throw new UploadRefused(503, 'storage', 'The file could not be landed.');
        }
        @unlink($path);
    }
    @chmod($abs, 0640);
    if ($blob['deleted_at'] !== null || $blob['storage_path'] !== $rel) {
        $pdo->prepare('UPDATE blobs SET deleted_at = NULL, storage_path = :p, byte_size = :n WHERE id = :id')->execute(['p' => $rel, 'n' => $size, 'id' => (int) $blob['id']]);
    }
    return ['blob_id' => (int) $blob['id'], 'sha256' => $sha, 'path' => $rel, 'deduped' => false, 'byte_size' => $size];
}

/** A request body (an upload part's raw bytes, or a multipart field) saved to a temporary file under the upload's parts; null when none. */
function request_bytes_to_file(int $upload, string $field = 'part'): ?string
{
    if (isset($_FILES[$field]) && is_uploaded_file((string) $_FILES[$field]['tmp_name'])) {
        return (string) $_FILES[$field]['tmp_name'];
    }
    $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_starts_with($ct, 'multipart/form-data') || str_starts_with($ct, 'application/x-www-form-urlencoded')) {
        return null;
    }
    $dir = store_parts_dir($upload);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return null;
    }
    $tmp = $dir . '/incoming-' . bin2hex(random_bytes(6));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    if ($in === false || $out === false) {
        return null;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $tmp;
}
