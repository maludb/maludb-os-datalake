<?php
declare(strict_types=1);

/**
 * The lineage graph as a SERVER-RENDERED SVG (transforms-products-lineage.md divergence 4; ★32: no chart library): layered columns — depth −N … 0 … +N, the dataset in the middle —
 * flowing left to right (sources and raw on the left, products on the right). A node per discoverable dataset (its key, its title, its zone's colour, a certification dot), a source as a
 * rounded grey node, an edge a curve labelled load / promote / transform / export; the nodes the viewer may not discover collapse into ONE dashed grey "n hidden" node per column.
 * The SVG scales by its viewBox (it never scrolls the page sideways); the hop lists under it carry the same facts as text. Colours are CSS classes (app-overrides.css), so the theme's
 * dark skin follows.
 *
 *   lineage_svg($up, $down, $centre, $depth = 2)   $up / $down: lineage_hops() answers; $centre: {dataset_id, key, title, zone, certification}
 */

const LG_NODE_W = 188;
const LG_NODE_H = 52;
const LG_GAP_X = 52;
const LG_GAP_Y = 14;
const LG_PAD = 16;

function lineage_svg(array $up, array $down, array $centre, int $depth = 2): string
{
    $depth = max(1, min(LINEAGE_MAX_DEPTH, $depth));
    $cols = 2 * $depth + 1;
    // column index: upstream depth d → $depth - d; the centre → $depth; downstream depth d → $depth + d
    $byCol = array_fill(0, $cols, []);
    $byCol[$depth][] = ['id' => 'c' . (int) $centre['dataset_id'], 'kind' => 'dataset', 'data' => $centre + ['depth' => 0, 'via' => null], 'centre' => true];
    foreach ($up['nodes'] as $n) {
        if ($n['depth'] <= $depth) {
            $byCol[$depth - (int) $n['depth']][] = ['id' => ($n['kind'] === 'source' ? 's' . $n['source_id'] : 'd' . $n['dataset_id']), 'kind' => $n['kind'], 'data' => $n, 'dir' => 'up'];
        }
    }
    foreach ($down['nodes'] as $n) {
        if ($n['depth'] <= $depth) {
            $byCol[$depth + (int) $n['depth']][] = ['id' => 'd' . $n['dataset_id'], 'kind' => 'dataset', 'data' => $n, 'dir' => 'down'];
        }
    }
    foreach ([[$up, -1], [$down, 1]] as [$hops, $sign]) {
        foreach ($hops['hidden_by_depth'] as $d => $count) {
            if ($d <= $depth && $count > 0) {
                $byCol[$depth + $sign * (int) $d][] = ['id' => 'h' . ($sign < 0 ? 'u' : 'd') . $d, 'kind' => 'hidden', 'data' => ['count' => (int) $count, 'depth' => (int) $d, 'dir' => $sign < 0 ? 'up' : 'down',
                    'parents' => $hops['hidden_parents'][$d] ?? []]];
            }
        }
    }
    $maxRows = max(1, ...array_map('count', $byCol));
    $colW = LG_NODE_W + LG_GAP_X;
    $w = LG_PAD * 2 + $cols * LG_NODE_W + ($cols - 1) * LG_GAP_X;
    $h = LG_PAD * 2 + 24 + $maxRows * LG_NODE_H + ($maxRows - 1) * LG_GAP_Y;
    $pos = [];
    $out = '';
    foreach ($byCol as $c => $items) {
        $x = LG_PAD + $c * $colW;
        $total = count($items) * LG_NODE_H + max(0, count($items) - 1) * LG_GAP_Y;
        $y0 = LG_PAD + 24 + (($h - LG_PAD * 2 - 24) - $total) / 2;
        foreach ($items as $i => $it) {
            $pos[$it['id']] = [$x, $y0 + $i * (LG_NODE_H + LG_GAP_Y), $c, $it];
        }
    }
    // edges first (under the nodes)
    $edgeSvg = '';
    $link = static function (string $from, string $to, ?string $via, bool $dashed = false) use ($pos, &$edgeSvg): void {
        if (!isset($pos[$from], $pos[$to])) {
            return;
        }
        [$x1, $y1] = $pos[$from];
        [$x2, $y2] = $pos[$to];
        $x1 += LG_NODE_W;
        $y1 += LG_NODE_H / 2;
        $y2 += LG_NODE_H / 2;
        $mx = ($x1 + $x2) / 2;
        $cls = 'lg-edge' . ($dashed ? ' lg-edge-hidden' : '') . ($via !== null ? ' lg-via-' . preg_replace('/[^a-z]/', '', $via) : '');
        $edgeSvg .= sprintf('<path class="%s" d="M%.1f %.1f C%.1f %.1f %.1f %.1f %.1f %.1f" fill="none"/>', $cls, $x1, $y1, $mx, $y1, $mx, $y2, $x2, $y2);
        if ($via !== null) {
            $edgeSvg .= sprintf('<text class="lg-edge-label" x="%.1f" y="%.1f" text-anchor="middle">%s</text>', $mx, ($y1 + $y2) / 2 - 4, e($via));
        }
    };
    foreach ($up['nodes'] as $n) {
        if ($n['depth'] > $depth) {
            continue;
        }
        $id = $n['kind'] === 'source' ? 's' . $n['source_id'] : 'd' . $n['dataset_id'];
        $parent = (int) $n['parent_dataset_id'] === (int) $centre['dataset_id'] ? 'c' . (int) $centre['dataset_id'] : 'd' . (int) $n['parent_dataset_id'];
        $link($id, $parent, $n['via']);
    }
    foreach ($down['nodes'] as $n) {
        if ($n['depth'] > $depth) {
            continue;
        }
        $parent = (int) $n['parent_dataset_id'] === (int) $centre['dataset_id'] ? 'c' . (int) $centre['dataset_id'] : 'd' . (int) $n['parent_dataset_id'];
        $link($parent, 'd' . $n['dataset_id'], $n['via']);
    }
    foreach ($pos as $id => [$x, $y, $c, $it]) {
        if ($it['kind'] !== 'hidden') {
            continue;
        }
        foreach ($it['data']['parents'] as $p) {
            $pid = (int) $p === (int) $centre['dataset_id'] ? 'c' . (int) $p : 'd' . (int) $p;
            $it['data']['dir'] === 'up' ? $link($id, $pid, null, true) : $link($pid, $id, null, true);
        }
    }
    $nodeSvg = '';
    foreach ($pos as $id => [$x, $y, $c, $it]) {
        $d = $it['data'];
        if ($it['kind'] === 'hidden') {
            $nodeSvg .= sprintf('<g class="lg-node lg-hidden" id="lg-node-%s"><rect x="%.1f" y="%.1f" width="%d" height="%d" rx="6"/><text class="lg-text" x="%.1f" y="%.1f" text-anchor="middle">%d hidden</text>'
                . '<text class="lg-sub" x="%.1f" y="%.1f" text-anchor="middle">you may not discover %s</text></g>', e($id), $x, $y, LG_NODE_W, LG_NODE_H, $x + LG_NODE_W / 2, $y + 22, $d['count'],
                $x + LG_NODE_W / 2, $y + 38, $d['count'] === 1 ? 'it' : 'them');
            continue;
        }
        if ($it['kind'] === 'source') {
            $label = $d['name'] !== null ? mb_strimwidth((string) $d['name'], 0, 24, '…') : 'a source';
            $nodeSvg .= sprintf('<g class="lg-node lg-source" id="lg-node-%s"><rect x="%.1f" y="%.1f" width="%d" height="%d" rx="%d"/><text class="lg-text" x="%.1f" y="%.1f" text-anchor="middle">%s</text>'
                . '<text class="lg-sub" x="%.1f" y="%.1f" text-anchor="middle">source%s</text></g>', e($id), $x, $y, LG_NODE_W, LG_NODE_H, LG_NODE_H / 2, $x + LG_NODE_W / 2, $y + 22, e($label),
                $x + LG_NODE_W / 2, $y + 38, $d['connector'] ? ' · ' . e((string) $d['connector']) : '');
            continue;
        }
        $zone = preg_replace('/[^a-z]/', '', (string) $d['zone']);
        $cert = (string) ($d['certification'] ?? '');
        $dot = $cert === 'certified' ? '<circle class="lg-dot lg-dot-certified" cx="%.1f" cy="%.1f" r="5"><title>Certified</title></circle>'
            : ($cert === 'promoted' ? '<circle class="lg-dot lg-dot-promoted" cx="%.1f" cy="%.1f" r="5"><title>Promoted</title></circle>' : '');
        $nodeSvg .= sprintf('<a href="%s" class="lg-link" aria-label="%s"><g class="lg-node lg-zone-%s%s" id="lg-node-%s"><rect x="%.1f" y="%.1f" width="%d" height="%d" rx="6"/>'
            . '<text class="lg-text" x="%.1f" y="%.1f">%s</text><text class="lg-sub" x="%.1f" y="%.1f">%s</text>%s</g></a>',
            e('/datasets/' . $d['key']), e((string) $d['title']), e($zone), !empty($it['centre']) ? ' lg-centre' : '', e($id), $x, $y, LG_NODE_W, LG_NODE_H,
            $x + 10, $y + 20, e(mb_strimwidth((string) $d['key'], 0, 27, '…')), $x + 10, $y + 37, e(mb_strimwidth((string) $d['title'], 0, 30, '…')),
            $dot === '' ? '' : sprintf($dot, $x + LG_NODE_W - 12, $y + 12));
    }
    $heads = '';
    $columns = '';
    foreach ($byCol as $c => $items) {
        $rel = $c - $depth;
        $label = $rel === 0 ? 'this dataset' : ($rel < 0 ? abs($rel) . ' upstream' : $rel . ' downstream');
        $x = LG_PAD + $c * $colW;
        $heads .= sprintf('<text class="lg-col-head" x="%.1f" y="%d">%s</text>', $x + LG_NODE_W / 2, LG_PAD + 8, e($label));
        $columns .= sprintf('<g class="lg-column" id="lineage-col-%s" data-col="%d" data-nodes="%d"></g>', $rel < 0 ? 'm' . abs($rel) : $rel, $rel, count($items));
    }
    $aria = sprintf('Lineage of %s: %d upstream, %d downstream%s', (string) $centre['key'], count($up['nodes']), count($down['nodes']),
        $up['hidden'] + $down['hidden'] > 0 ? ', ' . ($up['hidden'] + $down['hidden']) . ' hidden' : '');
    return sprintf('<svg id="lineage-graph-svg" class="lineage-graph-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="%s" data-columns="%d" data-depth="%d">'
        . '<title>%s</title>%s%s%s%s</svg>', $w, $h, $w, $h, e($aria), $cols, $depth, e($aria), $columns, $heads, $edgeSvg, $nodeSvg);
}
