<?php

namespace App\Support;

use App\Helpers\AclHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Đổi vai trò trên bản thử nghiệm (config/role_switcher.php): nhập mã truy cập chung một lần (cookie 30 ngày), rồi chọn
 * vai trò / người để đăng nhập hẳn bằng tài khoản đó — quyền, phạm vi dữ liệu, menu đúng như người dùng thật.
 *
 * Chỉ dùng được khi bật ROLE_SWITCHER_ENABLED, có ROLE_SWITCHER_CODE và APP_ENV khác production. Cookie chứa HMAC của
 * mã (khoá APP_KEY): đổi mã thì cookie cũ hết hiệu lực. Chưa nhập mã thì không lộ danh sách tài khoản.
 */
final class RoleSwitcher
{
    public const COOKIE = 'role_switcher';

    private const COOKIE_MINUTES = 60 * 24 * 30;

    /** Số tài khoản tối đa hiện trong menu cho mỗi vai trò. */
    private const ACCOUNTS_PER_ROLE = 20;

    public static function enabled(): bool
    {
        return (bool) config('role_switcher.enabled')
            && (string) config('role_switcher.code') !== ''
            && ! app()->environment('production');
    }

    /** Giá trị cookie của trình duyệt đã nhập đúng mã. */
    public static function token(): string
    {
        return hash_hmac('sha256', 'role-switcher|'.config('role_switcher.code'), (string) config('app.key'));
    }

    public function checkCode(string $code): bool
    {
        return self::enabled() && hash_equals((string) config('role_switcher.code'), $code);
    }

    public function unlocked(Request $request): bool
    {
        return self::enabled() && hash_equals(self::token(), (string) $request->cookie(self::COOKIE));
    }

    public function cookie(): Cookie
    {
        return cookie(self::COOKIE, self::token(), self::COOKIE_MINUTES);
    }

    /** Chỉ chuyển sang tài khoản đang hoạt động và có vai trò của hệ thống. */
    public function canSwitchTo(User $user): bool
    {
        return ! $user->isLocked() && $user->roles()->whereIn('name', Roles::ALL)->exists();
    }

    /**
     * Shared prop `roleSwitcher`: null khi tắt; chưa nhập mã → chỉ { unlocked: false } (trang đăng nhập hiện ô nhập mã).
     *
     * @return array<string, mixed>|null
     */
    public function menu(Request $request): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        if (! $this->unlocked($request)) {
            return ['unlocked' => false];
        }

        $current = $request->user();
        $roleOf = fn (User $user) => ($role = $user->getRoleNames()->first()) ? AclHelper::shortRoleLabel($role) : 'Người dùng';

        return [
            'unlocked' => true,
            'current' => $current ? ['id' => $current->id, 'name' => $current->name, 'role' => $roleOf($current)] : null,
            'groups' => $this->groups($current),
        ];
    }

    /**
     * Tài khoản đang hoạt động theo vai trò (thứ tự Roles::ALL, tên A→Z); vai trò chưa có ai thì bỏ qua.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(?User $current): array
    {
        $groups = [];
        foreach (Roles::ALL as $role) {
            $users = User::query()
                ->with('branch:id,code')
                ->where('is_active', true)
                ->whereNull('locked_at')
                ->whereHas('roles', fn ($q) => $q->where('name', $role))
                ->orderBy('name')
                ->limit(self::ACCOUNTS_PER_ROLE)
                ->get(['id', 'name', 'email', 'employee_code', 'branch_id']);
            if ($users->isEmpty()) {
                continue;
            }

            $groups[] = [
                'role' => $role,
                'label' => AclHelper::shortRoleLabel($role),
                'accounts' => $users->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'login' => $user->employee_code ?: $user->loginIdentifier(),
                    'branch' => $user->branch?->code,
                    'current' => $current?->is($user) ?? false,
                ])->all(),
            ];
        }

        return $groups;
    }
}
