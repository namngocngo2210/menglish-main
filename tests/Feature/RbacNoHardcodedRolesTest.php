<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Chốt chặn RBAC (docs/rbac.md): quyết định "được làm gì / thấy gì" dùng permission (`can()`, `@can`, middleware `can:`),
 * phạm vi dữ liệu (App\Support\DataScope) hoặc quan hệ (người lập phiếu, GV của lớp, người được giao…).
 *
 * Bộ 9 vai trò là CỐ ĐỊNH (App\Support\Roles). Logic nghiệp vụ theo chức danh (loại lương, KPI, kỳ báo cáo, người nhận
 * thông báo…) được phép kiểm tra vai trò, nhưng CHỈ qua hằng số `Roles::*` — không gõ tên vai trò bằng chuỗi.
 * Super Admin: User::isSuperAdmin() (Gate::before). Các chỗ dùng isSuperAdmin() bị giới hạn trong danh sách dưới đây.
 */
class RbacNoHardcodedRolesTest extends TestCase
{
    /**
     * Mẫu kiểm tra vai trò bị cấm trong app/ và resources/views: gọi hasRole()… với chuỗi / biến tự do (chỉ nhận hằng
     * Roles::* hoặc Rbac::SUPER_ADMIN), directive Blade @role…, managedBranchIds().
     */
    private const FORBIDDEN = [
        '/->(hasRole|hasAnyRole|hasAllRoles|hasExactRoles)\s*\(\s*(?!\[?\s*(\.\.\.\s*)?\\\\?(App\\\\Support\\\\)?(Roles|Rbac)::)/',
        '/@(role|hasrole|hasanyrole|hasallroles|unlessrole)\b/i',
        '/->managedBranchIds\s*\(/',
    ];

    /** File được phép chứa mẫu cấm (đường dẫn tương đối) => lý do. */
    private const ALLOWLIST = [
        'app/Models/User.php' => 'User::isSuperAdmin() — định nghĩa Super Admin (Gate::before)',
    ];

    /**
     * Nơi được dùng isSuperAdmin() (ngoại lệ Super Admin), số lần tối đa. Gate::before + quản trị phân quyền +
     * luật quan hệ có miễn trừ cho Super Admin (người lập phiếu, người vi phạm) + tab mặc định giao diện.
     */
    private const SUPER_ADMIN_ALLOWLIST = [
        'app/Providers/AppServiceProvider.php' => 1,      // Gate::before: Super Admin toàn quyền thao tác
        'app/Models/User.php' => 1,                        // hasModuleAction (Super Admin toàn quyền cả theo phạm vi)
        'app/Support/Rbac.php' => 4,                       // gán vai trò Super Admin, chống tự khóa, Super Admin thấy mọi người (cây cấp dưới)
        'app/Http/Controllers/UserController.php' => 2,    // quản lý tài khoản Super Admin
        'app/Http/Controllers/UserPermissionOverrideController.php' => 5, // hiển thị quyền / phạm vi Super Admin; chỉ Super Admin chỉnh quyền Super Admin; chống tự phân quyền
        'app/Http/Controllers/TuitionController.php' => 6, // người lập phiếu sửa / không tự duyệt phiếu (Super Admin miễn); cờ "được sửa" cho form / lịch sử / duyệt phiếu (Vue)
        'app/Models/Penalty.php' => 1,                     // người vi phạm không tự chốt biên bản (Super Admin miễn)
        'app/Http/Controllers/WorkTaskController.php' => 1, // tab mặc định "Tất cả" (giao diện)
        'app/Services/NotificationService.php' => 1,       // không tìm được người phân công ticket → báo Super Admin
        'resources/views/users/permissions.blade.php' => 1, // cảnh báo "Super Admin luôn toàn quyền"
    ];

    public function test_business_code_has_no_hardcoded_role_checks(): void
    {
        $violations = [];
        foreach ($this->files() as $path => $contents) {
            if (isset(self::ALLOWLIST[$path])) {
                continue;
            }
            foreach (self::FORBIDDEN as $pattern) {
                if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [$match, $offset]) {
                        $violations[] = sprintf('%s:%d  %s', $path, substr_count(substr($contents, 0, $offset), "\n") + 1, $match);
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Kiểm tra cứng vai trò — dùng permission / DataScope / quan hệ, hoặc hằng Roles::* (docs/rbac.md):\n".implode("\n", $violations));
    }

    public function test_role_names_are_not_typed_as_string_literals(): void
    {
        $names = implode('|', array_map('preg_quote', \App\Support\Roles::ALL));
        // Hàm lấy / gán / kiểm tra vai trò với tên vai trò gõ tay (trừ định nghĩa hằng ở Roles.php và Rbac::SUPER_ADMIN).
        $pattern = "/(hasRole|hasAnyRole|User::role|->role|withRoles|assignRole|removeRole|syncRoles|findOrCreate|roles->contains|getRoleNames\(\)->contains)\s*\(\s*\[?\s*'(?:{$names})'/";
        $violations = [];
        foreach ($this->files() as $path => $contents) {
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$match, $offset]) {
                    $violations[] = sprintf('%s:%d  %s', $path, substr_count(substr($contents, 0, $offset), "\n") + 1, $match);
                }
            }
        }

        $this->assertSame([], $violations, "Tên vai trò gõ bằng chuỗi — dùng hằng App\\Support\\Roles::*:\n".implode("\n", $violations));
    }

    public function test_fixed_roles_match_the_default_role_config(): void
    {
        $this->assertEqualsCanonicalizing(\App\Support\Roles::ALL, array_keys(config('access.roles')), 'config/access.php phải có đúng bộ vai trò cố định (App\\Support\\Roles::ALL).');
        $this->assertEqualsCanonicalizing(\App\Support\Roles::ALL, array_keys(\App\Support\Roles::LABELS));
        $this->assertEqualsCanonicalizing(\App\Support\Roles::ALL, array_keys(\App\Support\Roles::SHORT_LABELS));
    }

    public function test_super_admin_exception_is_confined_to_the_allowlist(): void
    {
        $found = [];
        foreach ($this->files() as $path => $contents) {
            $count = preg_match_all('/->isSuperAdmin\s*\(/', $contents);
            if ($count > 0) {
                $found[$path] = $count;
            }
        }

        $unexpected = [];
        foreach ($found as $path => $count) {
            if ($count > (self::SUPER_ADMIN_ALLOWLIST[$path] ?? 0)) {
                $unexpected[] = "{$path}: {$count} lần (cho phép ".(self::SUPER_ADMIN_ALLOWLIST[$path] ?? 0).')';
            }
        }

        $this->assertSame([], $unexpected, "isSuperAdmin() ngoài danh sách ngoại lệ Super Admin:\n".implode("\n", $unexpected));
    }

    public function test_the_only_role_name_check_is_super_admin_definition(): void
    {
        $user = file_get_contents(base_path('app/Models/User.php'));
        $this->assertSame(1, preg_match_all('/->hasRole\s*\(/', $user), 'User.php chỉ được gọi hasRole() trong isSuperAdmin().');
        $this->assertMatchesRegularExpression('/function isSuperAdmin\(\): bool\s*\{\s*return \$this->hasRole\(\\\\App\\\\Support\\\\Rbac::SUPER_ADMIN\);/', $user);
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $files = [];
        $finder = (new Finder)->files()->in([base_path('app'), base_path('resources/views')])->name(['*.php']);
        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', substr($file->getRealPath(), strlen(base_path()) + 1));
            $files[$relative] = $file->getContents();
        }

        return $files;
    }
}
