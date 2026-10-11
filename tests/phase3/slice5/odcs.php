<?php
/**
 * Slice 5 proof 1 (the ODCS part) — the contract as an Open Data Contract Standard v3.2.0 document (★32: our own small writer): downloaded by a
 * reader, a YAML any parser reads (PyYAML here) with ODCS's fields — the schema's properties, the key's positions, the raw column each reads,
 * the SLA, the checks as quality rules; and read back by the contract form ("Import ODCS"): the draft it fills is the contract it came from.
 */
require __DIR__ . '/lib.php';

echo "1. Download\n";
[$jar] = actor(26);
$ch = curl_init(BASE . '/datasets/curated.orders/contract.odcs.yaml?version=1.0.0');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADER => true]);
$raw = (string) curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
$head = substr($raw, 0, $hsize);
$yaml = substr($raw, $hsize);
ok($code === 200 && stripos($head, 'content-type: application/yaml') !== false && stripos($head, 'attachment; filename="curated_orders-1.0.0.odcs.yaml"') !== false,
   'Priya downloads curated.orders 1.0.0 as curated_orders-1.0.0.odcs.yaml (application/yaml)');
$p = proc_open(['/usr/bin/python3', '-I', '-c', 'import sys, json, yaml; print(json.dumps(yaml.safe_load(sys.stdin), default=str))'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], $yaml);
fclose($pipes[0]);
$doc = json_decode((string) stream_get_contents($pipes[1]), true) ?: [];
$err = stream_get_contents($pipes[2]);
proc_close($p);
ok(($doc['apiVersion'] ?? '') === 'v3.2.0' && ($doc['kind'] ?? '') === 'DataContract' && ($doc['version'] ?? '') === '1.0.0' && ($doc['status'] ?? '') === 'active',
   'a YAML parser reads it: apiVersion v3.2.0, kind DataContract, version 1.0.0' . ($err ? ' — ' . $err : ''));
$obj = $doc['schema'][0] ?? [];
$props = array_column($obj['properties'] ?? [], null, 'name');
ok(($obj['physicalName'] ?? '') === 'curated.orders' && count($props) === 6 && ($props['order_id']['primaryKey'] ?? false) === true && ($props['order_id']['primaryKeyPosition'] ?? 0) === 1,
   'its schema: curated.orders, six properties, order_id the key at position 1');
ok(($props['amount']['physicalType'] ?? '') === 'DECIMAL(12,2)' && ($props['amount']['logicalType'] ?? '') === 'number' && ($props['order_date']['transformSourceObjects'] ?? []) === ['raw.orders']
   && ($props['order_date']['transformLogic'] ?? '') === 'order_date', 'each property\'s types and the raw column it reads (transformSourceObjects, transformLogic)');
$sla = array_column($doc['slaProperties'] ?? [], null, 'property');
ok(($sla['freshness']['value'] ?? 0) === 1 && ($sla['freshness']['unit'] ?? '') === 'd' && ($sla['retention']['unit'] ?? '') === 'y', 'the SLA as slaProperties (freshness 1 d, retention 2 y)');
$q = array_column($props['amount']['quality'] ?? [], 'metric');
ok(in_array('nullValues', $q, true), 'the amount\'s not_null check as a quality rule (library metric nullValues)');
ok(str_contains($yaml, 'customProperties') && str_contains($yaml, '"orderingColumn"'), 'the ordering column as a custom property');
ok(get_json(29, '/datasets/curated.orders/contract.odcs.yaml')['code'] === 404 && get_json(26, '/datasets/curated.no_contract/contract.odcs.yaml')['code'] === 404,
   'Ann gets nothing (404); a dataset with no contract has no document (404)');
$j = get_json(26, '/datasets/curated.orders/contract.odcs.yaml')['json']['data'] ?? [];
ok(str_starts_with((string) ($j['yaml'] ?? ''), '# ODCS v3.2.0') && ($j['contract']['status'] ?? '') === 'active', 'in JSON: the contract and the YAML as text');

echo "2. Read back (Import ODCS)\n";
$f = post_json(27, '/datasets/curated.orders_v/contract/edit', ['odcs' => $yaml]);
$d = $f['json']['data'] ?? [];
$imp = array_map(static fn (array $c): string => $c['name'] . ':' . $c['physical_type'] . ':' . $c['source'] . ':' . ($c['primary_key'] ? 'k' : '-'), $d['draft']['schema'] ?? []);
$orig = array_map(static fn (array $c): string => $c['name'] . ':' . $c['physical_type'] . ':' . $c['source'] . ':' . (!empty($c['primary_key']) ? 'k' : '-'), orders_schema());
ok($f['code'] === 200 && ($d['imported'] ?? false) === true && $imp === $orig, 'the form reads it back: the same six columns, types, sources and key');
ok(($d['draft']['key'] ?? []) === ['order_id'] && ($d['draft']['sla'] ?? []) === ['freshness' => 'P1D', 'retention' => 'P2Y', 'time_to_detect' => 'PT1H'] && ($d['draft']['ordering_column'] ?? '') === 'updated_at',
   '…the key, the SLA and the ordering column');
$s = post_json(27, '/quality/contracts/save.php', ['dataset' => 'curated.orders_v', 'version' => '2.1.0', 'schema' => json_encode($d['draft']['schema'] ?? []), 'key' => $d['draft']['key'] ?? [],
    'ordering_column' => $d['draft']['ordering_column'] ?? '', 'sla' => json_encode($d['draft']['sla'] ?? [])]);
ok($s['code'] === 200 && ($s['json']['breaking'] ?? null) === [], 'saved as curated.orders_v 2.1.0 (status comes back — not breaking)');
$bad = post_json(27, '/datasets/curated.orders_v/contract/edit', ['odcs' => "kind: \"Something\"\nname: \"x\"\n"]);
ok(($bad['json']['data']['imported'] ?? true) === false && str_contains((string) ($bad['json']['data']['notice'] ?? ''), 'not an ODCS contract'), 'a document that is not a contract: said so, nothing filled');
ok(get_json(26, '/datasets/curated.orders_v/contract/edit')['code'] === 403, 'a Member may not import (contracts.manage)');
finish();
