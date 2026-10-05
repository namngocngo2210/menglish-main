<?php

namespace App\Services\Crm;

use App\Models\User;
use App\Support\DataScope;
use App\Support\Rbac;
use Illuminate\Support\Collection;

/**
 * Người phụ trách khách CRM (chủ dự án 29/09/2026): Học vụ và Admin — người đang hoạt động có quyền
 * "Được nhận phụ trách khách" (lead.be_assigned). Học vụ gắn với cơ sở của mình; người phạm vi CRM toàn hệ thống
 * (Admin) phụ trách được khách mọi cơ sở. Giao khách cho người ở cơ sở khác = chuyển cơ sở (CrmBranchTransferService).
 */
class LeadOwners
{
    /**
     * Người phụ trách được chọn, người toàn hệ thống (Admin) lên đầu. $branchIds: chỉ người thuộc các cơ sở này
     * (người toàn hệ thống luôn có); null = mọi cơ sở.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<int, User>
     */
    public static function candidates(?array $branchIds = null): Collection
    {
        return Rbac::scopeUsersWithPermission(User::query()->where('is_active', true), 'lead.be_assigned')
            ->with(['branch:id,name', 'branches:id,name', 'roles.permissions', 'permissions', 'permissionOverrides'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id', 'is_active'])
            ->filter(fn (User $user) => $branchIds === null || self::coversAll($user) || array_intersect($branchIds, $user->branchIds()) !== [])
            ->sortBy(fn (User $user) => self::coversAll($user) ? 0 : 1, SORT_REGULAR)
            ->values();
    }

    public static function isCandidate(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->can('lead.be_assigned');
    }

    /** Người phụ trách được khách mọi cơ sở (phạm vi CRM toàn hệ thống — Admin). */
    public static function coversAll(User $user): bool
    {
        return DataScope::isAll($user, 'lead');
    }

    public static function belongsToBranch(User $owner, ?int $branchId): bool
    {
        return self::coversAll($owner) || ($branchId !== null && in_array($branchId, $owner->branchIds(), true));
    }

    /** Cơ sở khách chuyển sang khi giao cho $owner: cơ sở chính của người đó (hoặc cơ sở được cấp thêm đầu tiên). */
    public static function homeBranchId(User $owner): ?int
    {
        return $owner->branch_id ? (int) $owner->branch_id : ($owner->branchIds()[0] ?? null);
    }

    /**
     * Nhãn trong danh sách chọn: "Tên — Cơ sở" (Admin: "Tên — Admin"). Người đang phụ trách khách nhưng không còn
     * nhận phụ trách (vd Sale trước 29/09/2026, tài khoản khóa): "Tên (người phụ trách cũ)".
     */
    public static function label(User $user): string
    {
        if (! self::isCandidate($user)) {
            return $user->name.' (người phụ trách cũ)';
        }
        if (self::coversAll($user)) {
            return $user->name.' — Admin';
        }
        $branch = $user->relationLoaded('branch') ? $user->branch : $user->branch()->first();

        return $user->name.($branch ? ' — '.$branch->name : '');
    }

    /**
     * @param  iterable<User>  $users
     * @return array<int, string>
     */
    public static function options(iterable $users): array
    {
        return collect($users)->mapWithKeys(fn (User $user) => [$user->id => self::label($user)])->all();
    }

    /** Tìm người phụ trách theo email hoặc họ tên (không phân biệt hoa thường) trong danh sách cho phép (nhập Excel). */
    public static function match(Collection $candidates, string $value): ?User
    {
        $needle = mb_strtolower(trim($value));
        if ($needle === '') {
            return null;
        }
        $byEmail = $candidates->first(fn (User $u) => mb_strtolower((string) $u->email) === $needle);
        if ($byEmail) {
            return $byEmail;
        }
        $byName = $candidates->filter(fn (User $u) => mb_strtolower(trim($u->name)) === $needle);

        return $byName->count() === 1 ? $byName->first() : null;
    }
}
