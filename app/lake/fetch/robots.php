<?php
declare(strict_types=1);

/**
 * robots.txt, parsed and honoured (design §0.2). Pure functions over the file's text; DlHttp fetches it once per host
 * per client (robots_for) and asks allows()/crawl_delay() before every request.
 *
 * Groups are chosen by the longest User-agent token found inside our user-agent (case-insensitive), else `*`;
 * inside a group the longest matching Allow/Disallow path wins (Allow on a tie), `$` anchors, `*` wildcards;
 * Crawl-delay is read per group. An absent file (404, 410) allows everything; a 403/429 or a bot wall on
 * robots.txt counts as "nothing allowed" (DECISION: the host has refused crawlers at the door).
 */

/** Parse the text → ['groups' => [agent => ['allow' => [...], 'disallow' => [...], 'crawl_delay' => ?float]], 'sitemaps' => [...]]. */
function dl_robots_parse(string $text): array
{
    $groups = [];
    $sitemaps = [];
    $current = [];
    $lastWasAgent = false;
    foreach (preg_split('~\r\n|\r|\n~', $text) ?: [] as $line) {
        $line = trim(preg_replace('~#.*$~', '', $line) ?? '');
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$field, $value] = array_map('trim', explode(':', $line, 2));
        $field = strtolower($field);
        if ($field === 'user-agent') {
            if (!$lastWasAgent) {
                $current = [];
            }
            $agent = strtolower($value);
            $current[] = $agent;
            $groups[$agent] ??= ['allow' => [], 'disallow' => [], 'crawl_delay' => null];
            $lastWasAgent = true;
            continue;
        }
        $lastWasAgent = false;
        if ($field === 'sitemap') {
            $sitemaps[] = $value;
            continue;
        }
        foreach ($current as $agent) {
            if ($field === 'disallow') {
                if ($value !== '') {
                    $groups[$agent]['disallow'][] = $value;
                }
            } elseif ($field === 'allow') {
                if ($value !== '') {
                    $groups[$agent]['allow'][] = $value;
                }
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $groups[$agent]['crawl_delay'] = (float) $value;
            }
        }
    }
    return ['groups' => $groups, 'sitemaps' => $sitemaps];
}

/** The group that applies to our user-agent (the product token before any "/" counts too), else "*", else null. */
function dl_robots_group(array $robots, string $userAgent): ?array
{
    $ua = strtolower($userAgent);
    $best = null;
    $bestLen = -1;
    foreach ($robots['groups'] as $agent => $group) {
        if ($agent === '*') {
            continue;
        }
        if ($agent !== '' && str_contains($ua, $agent) && strlen($agent) > $bestLen) {
            $best = $group;
            $bestLen = strlen($agent);
        }
    }
    return $best ?? ($robots['groups']['*'] ?? null);
}

/** Does a robots path pattern match the request path? */
function dl_robots_pattern_matches(string $pattern, string $path): bool
{
    $anchored = str_ends_with($pattern, '$');
    if ($anchored) {
        $pattern = substr($pattern, 0, -1);
    }
    $re = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . ($anchored ? '$' : '') . '~';
    return (bool) preg_match($re, $path);
}

/** Is the path (with its query) allowed for this user-agent? */
function dl_robots_allows(array $robots, string $path, string $userAgent): bool
{
    $group = dl_robots_group($robots, $userAgent);
    if ($group === null) {
        return true;
    }
    $path = $path === '' ? '/' : $path;
    $winner = null;   // [len, allow?]
    foreach (['allow' => true, 'disallow' => false] as $kind => $isAllow) {
        foreach ($group[$kind] as $pattern) {
            if (dl_robots_pattern_matches($pattern, $path)) {
                $len = strlen($pattern);
                if ($winner === null || $len > $winner[0] || ($len === $winner[0] && $isAllow)) {
                    $winner = [$len, $isAllow];
                }
            }
        }
    }
    return $winner === null ? true : $winner[1];
}

/** The Crawl-delay in seconds for this user-agent, or null. */
function dl_robots_crawl_delay(array $robots, string $userAgent): ?float
{
    $group = dl_robots_group($robots, $userAgent);
    return $group['crawl_delay'] ?? null;
}
