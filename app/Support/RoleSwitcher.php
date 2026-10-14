<?php

namespace App\Support;

use App\Helpers\AclHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Đổi vai trò nhanh trên môi trường thử nghiệm (config/role_switcher.php): Super Admin đăng nhập một lần rồi chuyển
 * sang tài khoản mẫu của từng vai trò (đăng nhập hẳn bằng tài khoản đó: quyền, phạm vi dữ liệu, menu đúng như người
 * dùng thật), chuyển tiếp hoặc quay về tài khoản gốc mà không gõ mật khẩu.
 *
 * Chỉ dùng được khi: bật ROLE_SWITCHER_ENABLED, APP_ENV khác production, và người bắt đầu phiên là Super Admin (id lưu
 * trong session — sau khi chuyển sang Học viên / GV vẫn thấy menu). Chỉ chuyển được sang tài khoản có trong menu.
 */
final class RoleSwitcher
{
    /** Id tài khoản Super Admin đã bắt đầu đổi vai trò trong phiên này. */
    public const SESSION_KEY = 'role_switcher.origin_id';

    public static function enabled(): bool
    {
        return (bool) config('role_switcher.enabled') && ! app()->environment('production');
    }

    /** Tài khoản gốc (Super Admin) được đổi vai trò trong phiên này; null = không được dùng. */
    public function origin(Request $request): ?User
    {
        $user = $request->user();
        if (! self::enabled() || ! $user || ! $request->hasSession()) {
            return null;
        }

        $originId = $request->session()->get(self::SESSION_KEY);
        $origin = $originId && (int) $originId !== $user->id ? User::find($originId) : $user;

        return $origin && ! $origin->isLocked() && $origin->isSuperAdmin() ? $origin : null;
    }

    /** Tài khoản có trong menu (hoặc tài khoản gốc) — chỉ những tài khoản này được chuyển sang. */
    public function canSwitchTo(User $origin, User $target): bool
    {
        if ($target->is($origin)) {
            return true;
        }

        return ! $target->isLocked() && $this->groups()->flatten(1)->contains(fn (User $user) => $user->is($target));
    }

    /**
     * Dữ liệu menu "Đổi vai trò" cho Vue (shared prop `roleSwitcher`); null khi người dùng không được dùng.
     *
     * @return array<string, mixed>|null
     */
    public function menu(Request $request): ?array
    {
        $origin = $this->origin($request);
        if (! $origin) {
            return null;
        }

        $current = $request->user();
        $notes = $this->notes();
        $account = fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'login' => $user->loginIdentifier(),
            'branch' => $user->branch?->code,
            'note' => $notes[mb_strtolower((string) $user->email)] ?? null,
            'current' => $user->is($current),
            'url' => route('role-switcher.switch', $user),
        ];

        return [
            'current_role' => ($role = $current->getRoleNames()->first()) ? AclHelper::shortRoleLabel($role) : 'Người dùng',
            'origin' => $current->is($origin) ? null : ['name' => $origin->name, 'url' => route('role-switcher.switch', $origin)],
            'groups' => $this->groups()->map(fn (Collection $users, string $role) => [
                'role' => $role,
                'label' => AclHelper::shortRoleLabel($role),
                'accounts' => $users->map($account)->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Tài khoản theo vai trò (thứ tự Roles::ALL): tài khoản mẫu trong config theo thứ tự khai báo; vai trò không có
     * tài khoản mẫu nào → vài tài khoản đang hoạt động đầu tiên của vai trò.
     *
     * @return Collection<string, Collection<int, User>>
     */
    private function groups(): Collection
    {
        $order = array_flip(array_keys($this->notes()));
        $demo = $this->activeUsers()
            ->whereIn('email', array_keys($order))
            ->get()
            ->sortBy(fn (User $user) => $order[mb_strtolower((string) $user->email)] ?? PHP_INT_MAX);

        $groups = collect();
        foreach (Roles::ALL as $role) {
            $users = $demo->filter(fn (User $user) => $user->roles->contains('name', $role))->values();
            if ($users->isEmpty()) {
                $users = $this->activeUsers()->role($role)->orderBy('id')->limit((int) config('role_switcher.fallback_per_role', 3))->get();
            }
            if ($users->isNotEmpty()) {
                $groups[$role] = $users;
            }
        }

        return $groups;
    }

    private function activeUsers()
    {
        return User::query()->with(['roles:id,name', 'branch:id,code'])->where('is_active', true)->whereNull('locked_at');
    }

    /** @return array<string, string|null> email (chữ thường) => ghi chú */
    private function notes(): array
    {
        $notes = [];
        foreach ((array) config('role_switcher.accounts', []) as $email => $note) {
            $notes[mb_strtolower((string) $email)] = $note;
        }

        return $notes;
    }
}
