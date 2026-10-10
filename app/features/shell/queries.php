<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the bell's count (mcp_notifications), the decisions waiting for
 * the person (access requests they decide, the Steward's proposals on what they own or steward), the business name. Each is cached for the
 * request — one query per render. Every read goes through a view or function that applies the wall; nothing here re-implements a rule.
 */

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/**
 * The decisions waiting for me: ['requests' => access requests pending that I decide (a dataset of mine, or grants.manage — never my own),
 * 'proposals' => the Steward's pending proposals on a dataset I own or steward, or any for catalog.edit]. Slice 4 makes requests, slice 10 proposals.
 */
function decisions(PDO $pdo, int $memberId): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $requests = (int) one_value($pdo, "SELECT count(*) FROM mcp_access_requests WHERE status = 'pending' AND requester_id <> :me", ['me' => $memberId]);
    $proposals = (int) one_value($pdo, "SELECT count(*) FROM mcp_steward_proposals p
                                         WHERE p.status = 'pending'
                                           AND ((SELECT dl_has_right('catalog.edit'))
                                                OR EXISTS (SELECT 1 FROM datasets d WHERE d.id = p.dataset_id AND :me IN (d.owner_member_id, d.steward_member_id)))",
                                    ['me' => $memberId]);
    return $cache = ['requests' => $requests, 'proposals' => $proposals];
}

/** The decisions badge: how many decisions wait for me. */
function decisions_count(PDO $pdo, int $memberId): int
{
    return array_sum(decisions($pdo, $memberId));
}

/** The business's name on the header (dl_settings.business_name via mcp_settings), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: app_name());
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}

/** A zone's chip colour (sso-shell.md "Status vocabulary"): raw secondary, curated primary, product success, sandbox light. */
function zone_color(string $zone): string
{
    return ['raw' => 'secondary', 'curated' => 'primary', 'product' => 'success', 'sandbox' => 'light'][$zone] ?? 'secondary';
}

/** A certification's badge: [class, words] or null for none. */
function certification_badge(?string $certification): ?array
{
    return match ($certification) {
        'certified' => ['text-bg-success', 'Certified'],
        'promoted'  => ['text-bg-info', 'Promoted'],
        default     => null,
    };
}

/** A count in words a person reads: 1,204 · 12.4k · 3.1M. */
function count_words(int|float|string|null $n): string
{
    $v = (float) ($n ?? 0);
    if ($v >= 1e6) {
        return rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M';
    }
    if ($v >= 1e4) {
        return rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'k';
    }
    return number_format($v);
}
