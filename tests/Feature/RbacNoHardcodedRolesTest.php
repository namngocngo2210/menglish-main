<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Chốt chặn RBAC (docs/rbac.md): code nghiệp vụ không kiểm tra cứng tên vai trò. Mọi quyết định "được làm gì / thấy
 * gì" dùng permission (`can()`, `@can`, middleware `can:`), phạm vi dữ liệu (App\Support\DataScope) hoặc quan hệ
 * (người lập phiếu, GV của lớp, người được giao…).
 *
 * Ngoại lệ duy nhất cho hasRole(): User::isSuperAdmin() (Super Admin luôn toàn quyền — Gate::before). Các chỗ dùng
 * isSuperAdmin() cũng bị giới hạn trong danh sách dưới đây: thêm chỗ mới phải cân nhắc (và cập nhật docs/rbac.md).
 */
class RbacNoHardcodedRolesTest extends TestCase
{
    /** Mẫu kiểm tra vai trò bị cấm trong app/ và resources/views. */
    private const FORBIDDEN = [
        '/->hasRole\s*\(/',
        '/->hasAnyRole\s*\(/',
        '/->hasAllRoles\s*\(/',
        '/->hasExactRoles\s*\(/',
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
        'app/Support/Rbac.php' => 2,                       // gán vai trò Super Admin, chống tự khóa
        'app/Http/Controllers/UserController.php' => 2,    // quản lý tài khoản Super Admin
        'app/Http/Controllers/UserPermissionOverrideController.php' => 4, // chỉ Super Admin chỉnh quyền Super Admin
        'app/Http/Controllers/TuitionController.php' => 3, // người lập phiếu sửa / không tự duyệt phiếu (Super Admin miễn)
        'app/Models/Penalty.php' => 1,                     // người vi phạm không tự chốt biên bản (Super Admin miễn)
        'app/Http/Controllers/WorkTaskController.php' => 1, // tab mặc định "Tất cả" (giao diện)
        'app/Services/NotificationService.php' => 1,       // không tìm được người phân công ticket → báo Super Admin
        'resources/views/tuition/create-receipt.blade.php' => 1,
        'resources/views/tuition/history.blade.php' => 1,
        'resources/views/tuition/approve-receipt.blade.php' => 1,
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

        $this->assertSame([], $violations, "Kiểm tra cứng vai trò — dùng permission / DataScope / quan hệ thay thế (docs/rbac.md):\n".implode("\n", $violations));
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
