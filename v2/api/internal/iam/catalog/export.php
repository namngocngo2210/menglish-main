<?php
/**
 * Xuất danh mục quyền và vai trò của bản Laravel ra catalog.json cho v2 (Go nhúng file này).
 * Chạy từ gốc repo: php v2/api/internal/iam/catalog/export.php > v2/api/internal/iam/catalog/catalog.json
 * CI chạy lại lệnh này và báo lỗi nếu catalog.json lệch với config Laravel.
 *
 * Nguồn: config/permission_catalog.php, config/access.php, app/Support/Roles.php,
 * app/Support/Approvals/AdminOnlyApprovals.php. Giữ nguyên thứ tự khai báo.
 */
function env($key, $default = null) { return $default; }

$root = dirname(__DIR__, 5);
$catalog = require $root.'/config/permission_catalog.php';
$access = require $root.'/config/access.php';
require $root.'/app/Support/Roles.php';
require $root.'/app/Support/Approvals/AdminOnlyApprovals.php';

use App\Support\Approvals\AdminOnlyApprovals;
use App\Support\Roles;

$pairs = fn (array $m) => array_map(fn ($k, $v) => ['key' => (string) $k, 'label' => $v], array_keys($m), $m);
$actions = fn (array $m) => array_map(
    fn ($k, $v) => ['key' => (string) $k, 'label' => $v[0], 'description' => $v[1] ?? ''],
    array_keys($m), $m
);

$modules = [];
foreach ($catalog['modules'] as $key => $m) {
    $out = [
        'key' => $key,
        'label' => $m['label'],
        'group' => $m['group'],
        'icon' => $m['icon'] ?? '',
        'actions' => $actions($m['actions'] ?? []),
        'audience' => $actions($m['audience'] ?? []),
    ];
    if (isset($m['scope'])) {
        $out['scope'] = [
            'levels' => $m['scope']['levels'],
            'descriptions' => array_intersect_key($m['scope'], array_flip($m['scope']['levels'])),
        ];
    }
    foreach ($m['dynamic'] ?? [] as $prefix => [$label, $description]) {
        $out['dynamic'][] = ['prefix' => $prefix, 'label' => $label, 'description' => $description];
    }
    $modules[] = $out;
}

$roles = [];
foreach (Roles::ALL as $i => $name) {
    $roles[] = [
        'name' => $name,
        'label' => Roles::LABELS[$name],
        'short_label' => Roles::SHORT_LABELS[$name],
        'defaults' => $access['roles'][$name] ?? [],
    ];
}

echo json_encode([
    'groups' => $pairs($catalog['groups']),
    'scope_levels' => $pairs($catalog['scope_levels']),
    'matrix_columns' => $pairs($catalog['matrix_columns']),
    'modules' => $modules,
    'roles' => $roles,
    'admin_only' => AdminOnlyApprovals::PERMISSIONS,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
