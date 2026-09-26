<?php

namespace App\Support;

/**
 * Đọc danh mục quyền config/permission_catalog.php (nguồn duy nhất cho permission, nhãn, mô tả, phạm vi dữ liệu).
 *
 * Ba loại permission:
 *  - action   "module.action"          — năng lực thao tác (Xem / Thêm / Sửa / Xóa / Duyệt / thao tác khác).
 *  - scope    "module.scope_<level>"   — phạm vi dữ liệu (own / branch / all), xem DataScope.
 *  - audience "module.<name>"          — đối tượng người dùng (cổng học viên / giáo viên, được xếp dạy lớp…).
 *    Super Admin không tự có quyền audience (Gate::before bỏ qua) để không lọt vào danh sách giáo viên / cổng học viên.
 *  - dynamic  "user.assign_role.<role>" — sinh theo vai trò đang có (Rbac::ASSIGN_ROLE_PREFIX).
 */
final class PermissionCatalog
{
    public const KIND_ACTION = 'action';

    public const KIND_SCOPE = 'scope';

    public const KIND_AUDIENCE = 'audience';

    public const KIND_DYNAMIC = 'dynamic';

    /** @var array<string, array{kind: string, module: string, key: string, label: string, description: string}>|null */
    private static ?array $index = null;

    /** @return array<string, array<string, mixed>> */
    public static function modules(): array
    {
        return config('permission_catalog.modules', []);
    }

    /** @return array<string, mixed>|null */
    public static function module(string $module): ?array
    {
        return self::modules()[$module] ?? null;
    }

    /** @return array<string, string> */
    public static function groups(): array
    {
        return config('permission_catalog.groups', []);
    }

    /** @return array<string, string> */
    public static function scopeLevelLabels(): array
    {
        return config('permission_catalog.scope_levels', []);
    }

    /** @return array<string, string> */
    public static function matrixColumns(): array
    {
        return config('permission_catalog.matrix_columns', []);
    }

    /**
     * Mọi permission tĩnh của danh mục (action + scope + audience), không gồm quyền động user.assign_role.*.
     *
     * @return list<string>
     */
    public static function staticPermissions(): array
    {
        return array_keys(self::index());
    }

    /**
     * Permission tĩnh + quyền gán vai trò cho các vai trò truyền vào (trừ Super Admin).
     *
     * @param  iterable<string>  $roleNames
     * @return list<string>
     */
    public static function allPermissions(iterable $roleNames = []): array
    {
        $names = self::staticPermissions();
        foreach ($roleNames as $role) {
            if ($role !== Rbac::SUPER_ADMIN) {
                $names[] = Rbac::assignRolePermission($role);
            }
        }

        return array_values(array_unique($names));
    }

    public static function kind(string $permission): ?string
    {
        if (isset(self::index()[$permission])) {
            return self::index()[$permission]['kind'];
        }

        return str_starts_with($permission, Rbac::ASSIGN_ROLE_PREFIX) ? self::KIND_DYNAMIC : null;
    }

    public static function isAudience(string $permission): bool
    {
        return (self::index()[$permission]['kind'] ?? null) === self::KIND_AUDIENCE;
    }

    public static function isScope(string $permission): bool
    {
        return (self::index()[$permission]['kind'] ?? null) === self::KIND_SCOPE;
    }

    public static function moduleOf(string $permission): string
    {
        return self::index()[$permission]['module'] ?? explode('.', $permission, 2)[0];
    }

    /** Phần sau "module." (action / scope_level / audience / assign_role.<role>). */
    public static function keyOf(string $permission): string
    {
        return self::index()[$permission]['key'] ?? (explode('.', $permission, 2)[1] ?? $permission);
    }

    public static function label(string $permission): string
    {
        if (isset(self::index()[$permission])) {
            return self::index()[$permission]['label'];
        }
        if (str_starts_with($permission, Rbac::ASSIGN_ROLE_PREFIX)) {
            $role = substr($permission, strlen(Rbac::ASSIGN_ROLE_PREFIX));
            $template = self::module('user')['dynamic']['assign_role.'][0] ?? 'Gán vai trò: :role';

            return str_replace(':role', \App\Helpers\AclHelper::shortRoleLabel($role), $template);
        }

        return $permission;
    }

    public static function description(string $permission): string
    {
        if (isset(self::index()[$permission])) {
            return self::index()[$permission]['description'];
        }
        if (str_starts_with($permission, Rbac::ASSIGN_ROLE_PREFIX)) {
            $role = substr($permission, strlen(Rbac::ASSIGN_ROLE_PREFIX));
            $template = self::module('user')['dynamic']['assign_role.'][1] ?? '';

            return str_replace(':role', \App\Helpers\AclHelper::shortRoleLabel($role), $template);
        }

        return '';
    }

    public static function moduleLabel(string $module): string
    {
        return self::module($module)['label'] ?? $module;
    }

    public static function moduleGroup(string $module): string
    {
        $group = self::module($module)['group'] ?? null;

        return self::groups()[$group] ?? 'Khác';
    }

    /**
     * Các mức phạm vi dữ liệu module hỗ trợ (thấp → cao), [] nếu module không có phạm vi.
     *
     * @return list<string>
     */
    public static function scopeLevels(string $module): array
    {
        return self::module($module)['scope']['levels'] ?? [];
    }

    /** @return array<string, list<string>> module => levels */
    public static function scopedModules(): array
    {
        $result = [];
        foreach (self::modules() as $module => $definition) {
            if (! empty($definition['scope']['levels'])) {
                $result[$module] = $definition['scope']['levels'];
            }
        }

        return $result;
    }

    public static function scopePermission(string $module, string $level): string
    {
        return "{$module}.scope_{$level}";
    }

    public static function scopeLevelDescription(string $module, string $level): string
    {
        return self::module($module)['scope'][$level] ?? '';
    }

    /**
     * Module theo nhóm hiển thị (giữ thứ tự nhóm của danh mục), chỉ gồm module có ít nhất một permission trong $names.
     *
     * @param  iterable<string>  $names
     * @return array<string, array<string, array{actions: list<string>, audience: list<string>, dynamic: list<string>, scope: list<string>}>>
     */
    public static function grouped(iterable $names): array
    {
        $byModule = [];
        foreach ($names as $name) {
            $module = self::moduleOf($name);
            $kind = self::kind($name) ?? self::KIND_ACTION;
            $bucket = match ($kind) {
                self::KIND_SCOPE => 'scope',
                self::KIND_AUDIENCE => 'audience',
                self::KIND_DYNAMIC => 'dynamic',
                default => 'actions',
            };
            $byModule[$module] ??= ['actions' => [], 'audience' => [], 'dynamic' => [], 'scope' => []];
            $byModule[$module][$bucket][] = $name;
        }

        $groups = array_fill_keys(array_values(self::groups()), []);
        $order = array_flip(array_keys(self::modules()));
        uksort($byModule, fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));
        foreach ($byModule as $module => $buckets) {
            // Giữ thứ tự action theo danh mục.
            $actionOrder = array_flip(array_map(fn ($a) => "{$module}.{$a}", array_keys(self::module($module)['actions'] ?? [])));
            usort($buckets['actions'], fn ($a, $b) => ($actionOrder[$a] ?? PHP_INT_MAX) <=> ($actionOrder[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));
            sort($buckets['dynamic']);
            $groups[self::moduleGroup($module)][$module] = $buckets;
        }

        return array_filter($groups);
    }

    /** @return array<string, array{kind: string, module: string, key: string, label: string, description: string}> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        $levels = self::scopeLevelLabels();
        foreach (self::modules() as $module => $definition) {
            foreach ($definition['actions'] ?? [] as $action => [$label, $description]) {
                $index["{$module}.{$action}"] = ['kind' => self::KIND_ACTION, 'module' => $module, 'key' => $action, 'label' => $label, 'description' => $description];
            }
            foreach ($definition['audience'] ?? [] as $key => [$label, $description]) {
                $index["{$module}.{$key}"] = ['kind' => self::KIND_AUDIENCE, 'module' => $module, 'key' => $key, 'label' => $label, 'description' => $description];
            }
            foreach ($definition['scope']['levels'] ?? [] as $level) {
                $index[self::scopePermission($module, $level)] = [
                    'kind' => self::KIND_SCOPE, 'module' => $module, 'key' => "scope_{$level}",
                    'label' => 'Phạm vi: '.($levels[$level] ?? $level),
                    'description' => $definition['scope'][$level] ?? '',
                ];
            }
        }

        return self::$index = $index;
    }
}
