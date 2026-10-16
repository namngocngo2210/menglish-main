<?php
/**
 * Xuất danh mục SLA mặc định của bản Laravel (config/sla.php) ra sla.json cho v2 (Go nhúng file này, catalogs.SyncSLA
 * đồng bộ xuống hr.sla_rules sau mỗi lần migrate).
 * Chạy từ gốc repo: php v2/api/internal/catalogs/sla/export.php > v2/api/internal/catalogs/sla/sla.json
 * Giữ nguyên thứ tự khai báo.
 */
$root = dirname(__DIR__, 5);
$sla = require $root.'/config/sla.php';

$groups = array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys($sla['groups']), $sla['groups']);
$rules = [];
foreach ($sla['rules'] as $key => $r) {
    $rules[] = [
        'key' => $key,
        'group' => $r['group'],
        'label' => $r['label'],
        'description' => $r['description'],
        'unit' => $r['unit'],
        'value' => (int) $r['value'],
        'penalty' => $r['penalty'],
        'amount' => (int) $r['amount'],
        'ladder' => isset($r['ladder']) ? array_map('intval', $r['ladder']) : null,
        'amount_label' => $r['amount_label'] ?? null,
        'amount_per' => $r['amount_per'] ?? null,
        'task' => (bool) $r['task'],
        'switchable' => $r['switchable'] ?? true,
        'category' => $r['category'] ?? 'operations',
        'violation' => $r['violation'],
    ];
}

echo json_encode([
    'groups' => $groups,
    'ladder_reset_months' => (int) $sla['ladder_reset_months'],
    'rules' => $rules,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
