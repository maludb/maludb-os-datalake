<?php
declare(strict_types=1);

/**
 * A transform's schedule (transforms-products-lineage.md divergence 3): a five-field cron expression — minute hour day-of-month month day-of-week — matched in PHP
 * in the BUSINESS's time zone. `*`, lists (1,15), ranges (9-17), steps (every 15 minutes) and a range with a step (8-18/2) are understood; names (mon, jan) are not;
 * 7 is a Sunday as 0 is. When both the day of the month and the day of the week are restricted, either one matching admits the day (cron's own rule).
 *
 *   cron_valid($expr)                     is it five fields we can read? (the form's check; the database's CHECK admits only digits, `*`, `/`, `,`, `-` and spaces)
 *   cron_due($expr, $last, $now)          is there a matching minute after $last (a run's time) up to $now? — once per due minute: a run made in the minute a pass finds due
 *                                         (or later) covers it. $last null (never run): only the minute $now is in counts. At most eight days are looked back over.
 *   cron_words($expr)                     the schedule in words for a card ("Every day at 06:00", "Every 15 minutes", else "Cron 5 4 * * 1")
 */

/** The five fields as sets of integers, or null when unreadable. */
function cron_parse(string $expr): ?array
{
    $parts = preg_split('/\s+/', trim($expr)) ?: [];
    if (count($parts) !== 5) {
        return null;
    }
    $limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    $sets = [];
    foreach ($parts as $i => $field) {
        [$lo, $hi] = $limits[$i];
        $set = [];
        foreach (explode(',', $field) as $item) {
            if (!preg_match('~^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:/(\d{1,2}))?$~', $item, $m)) {
                return null;
            }
            $step = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
            if ($step < 1) {
                return null;
            }
            if ($m[1] === '*') {
                [$a, $b] = [$lo, $hi];
            } else {
                $a = (int) $m[2];
                $b = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (isset($m[4]) && $m[4] !== '' ? $hi : $a);
            }
            if ($a < $lo || $b > $hi || $a > $b) {
                return null;
            }
            for ($v = $a; $v <= $b; $v += $step) {
                $set[$v] = true;
            }
        }
        if ($set === []) {
            return null;
        }
        $sets[] = $set;
    }
    if (isset($sets[4][7])) {                                   // 7 is a Sunday as 0 is
        $sets[4][0] = true;
        unset($sets[4][7]);
    }
    $star = static fn (string $f): bool => $f === '*' || str_starts_with($f, '*/');
    return ['m' => $sets[0], 'h' => $sets[1], 'dom' => $sets[2], 'mon' => $sets[3], 'dow' => $sets[4], 'domStar' => $star($parts[2]), 'dowStar' => $star($parts[4])];
}

function cron_valid(string $expr): bool
{
    return cron_parse($expr) !== null;
}

/** Does this minute match? */
function cron_matches(array $c, DateTimeImmutable $t): bool
{
    if (!isset($c['m'][(int) $t->format('i')]) || !isset($c['h'][(int) $t->format('G')]) || !isset($c['mon'][(int) $t->format('n')])) {
        return false;
    }
    $dom = isset($c['dom'][(int) $t->format('j')]);
    $dow = isset($c['dow'][(int) $t->format('w')]);
    if (!$c['domStar'] && !$c['dowStar']) {
        return $dom || $dow;
    }
    return $dom && $dow;
}

function cron_due(string $expr, ?DateTimeImmutable $last, DateTimeImmutable $now, string $tz = 'UTC'): bool
{
    $c = cron_parse($expr);
    if ($c === null) {
        return false;
    }
    $zone = new DateTimeZone($tz);
    $t = $now->setTimezone($zone);
    $t = $t->setTime((int) $t->format('G'), (int) $t->format('i'), 0);
    $floor = $last === null ? $t->getTimestamp() - 60 : $last->getTimestamp();
    for ($n = 0; $n < 11520 && $t->getTimestamp() > $floor; $n++, $t = $t->modify('-1 minute')) {
        if (cron_matches($c, $t)) {
            return true;
        }
    }
    return false;
}

/** A schedule in words: 'after_inputs', a few common cron shapes, else the expression. */
function cron_words(?string $schedule): string
{
    if ($schedule === null || $schedule === '') {
        return 'On request';
    }
    if ($schedule === 'after_inputs') {
        return 'After its inputs change';
    }
    $f = preg_split('/\s+/', trim($schedule)) ?: [];
    if (count($f) === 5) {
        if ($f[0] === '*' && $f[1] === '*' && $f[2] === '*' && $f[3] === '*' && $f[4] === '*') {
            return 'Every minute';
        }
        if (preg_match('~^\*/(\d+)$~', $f[0], $m) && array_slice($f, 1) === ['*', '*', '*', '*']) {
            return 'Every ' . (int) $m[1] . ' minutes';
        }
        if (ctype_digit($f[0]) && $f[1] === '*' && array_slice($f, 2) === ['*', '*', '*']) {
            return 'Every hour at :' . str_pad($f[0], 2, '0', STR_PAD_LEFT);
        }
        if (ctype_digit($f[0]) && ctype_digit($f[1]) && array_slice($f, 2) === ['*', '*', '*']) {
            return 'Every day at ' . str_pad($f[1], 2, '0', STR_PAD_LEFT) . ':' . str_pad($f[0], 2, '0', STR_PAD_LEFT);
        }
    }
    return 'Cron ' . $schedule;
}
