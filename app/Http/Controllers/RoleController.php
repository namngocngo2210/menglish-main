<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Concerns\RendersModals;
use App\Http\Requests\RoleRequest;
use App\Support\PermissionCatalog;
use App\Support\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Màn Vai trò (RBAC linh hoạt — docs/rbac.md): danh sách, tạo, nhân bản, đổi tên, xóa vai trò; ma trận quyền theo
 * module (Xem / Thêm / Sửa / Xóa / Duyệt + thao tác khác + phạm vi dữ liệu).
 *
 * An toàn:
 *  - Super Admin (vai trò `admin`) bất biến: không sửa quyền, không đổi mã, không xóa (luôn toàn quyền qua Gate::before).
 *  - Không tự làm mất quyền quản trị phân quyền của chính mình khi sửa vai trò mình đang giữ.
 *  - Mọi thay đổi ghi nhật ký trước / sau (log "Người dùng & Phân quyền") và xóa cache quyền.
 *
 * Tạo / đổi tên từ danh sách mở modal (htmx) chỉ gồm tên, mã, mô tả; ma trận quyền vẫn ở trang đầy đủ roles.create/edit.
 */
class RoleController extends Controller
{
    use RendersModals;

    public const LOG = 'Người dùng & Phân quyền';

    public function index(Request $request): View
    {
        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [Rbac::SUPER_ADMIN])
            ->orderBy('name')
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('roles.index', compact('roles'));
    }

    public function create(Request $request): Response
    {
        return $this->formView($request, new Role);
    }

    public function store(RoleRequest $request): Response|RedirectResponse
    {
        $permissions = $this->requestedPermissions($request, null);
        $this->authorizeAssigningPermissions($request, $permissions !== []);

        $role = DB::transaction(function () use ($request, $permissions) {
            $role = Role::create([
                'name' => $request->validated('name'),
                'guard_name' => 'web',
                'label' => $request->validated('label'),
                'description' => $request->validated('description'),
            ]);
            $role->syncPermissions($permissions);
            Rbac::registerRole($role);

            return $role;
        });

        $this->log($role, 'created', 'Tạo vai trò mới', [], $this->snapshot($role->fresh()));

        return $this->modalSaved('Đã tạo vai trò thành công.', 'roles-changed', route('roles.index'));
    }

    public function edit(Request $request, Role $role): Response
    {
        return $this->formView($request, $role);
    }

    public function update(RoleRequest $request, Role $role): Response|RedirectResponse
    {
        $before = $this->snapshot($role);
        $isSuperAdmin = $role->name === Rbac::SUPER_ADMIN;
        $newName = $request->validated('name');

        if ($isSuperAdmin && $newName !== $role->name) {
            throw ValidationException::withMessages(['name' => 'Không đổi mã vai trò Super Admin.']);
        }
        if (! $isSuperAdmin && AclHelper::isSystemRole($role->name) && $newName !== $role->name) {
            throw ValidationException::withMessages(['name' => 'Không đổi mã của vai trò hệ thống (chỉ đổi tên hiển thị).']);
        }

        $permissions = null;
        $requested = $this->requestedPermissions($request, $role);
        $current = $role->permissions->pluck('name')->all();
        // Form chỉ đổi tên / mô tả (không gửi ma trận) → giữ nguyên quyền.
        $touchesMatrix = $request->hasAny(['permissions', 'scope', 'matrix_submitted']);
        if (! $touchesMatrix) {
            // Không đổi quyền.
        } elseif ($isSuperAdmin) {
            // Bất biến: form không gửi ma trận (chỉ đổi tên hiển thị / mô tả); gửi thì không được bớt quyền nào.
            if (($request->has('permissions') || $request->has('scope')) && array_diff($current, $requested) !== []) {
                throw ValidationException::withMessages(['permissions' => 'Vai trò Super Admin bất biến: luôn có toàn quyền, không thu hồi được quyền.']);
            }
        } elseif (collect($requested)->sort()->values()->all() !== collect($current)->sort()->values()->all()) {
            $this->authorizeAssigningPermissions($request, true);
            $this->ensureActorKeepsAccess($request, $role, $requested);
            $permissions = $requested;
        }

        DB::transaction(function () use ($role, $request, $newName, $permissions) {
            $oldName = $role->name;
            $role->update([
                'name' => $newName,
                'label' => $request->validated('label', $role->label),
                'description' => $request->validated('description', $role->description),
            ]);
            if ($oldName !== $newName) {
                Rbac::renameRole($oldName, $newName);
            }
            if ($permissions !== null) {
                $role->syncPermissions($permissions);
            }
        });
        Rbac::flushCache();

        $this->log($role, 'updated', 'Cập nhật vai trò', $before, $this->snapshot($role->fresh()));

        return $this->modalSaved('Đã cập nhật vai trò.', 'roles-changed', route('roles.index'));
    }

    /** Nhân bản vai trò: bản sao có cùng quyền + phạm vi dữ liệu, chưa gán cho ai. */
    public function duplicate(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeAssigningPermissions($request, true);

        $base = Str::of($role->name)->replaceMatches('/_copy\d*$/', '')->append('_copy')->toString();
        $name = $base;
        for ($i = 2; Role::query()->where('name', $name)->exists(); $i++) {
            $name = $base.$i;
        }

        $copy = DB::transaction(function () use ($role, $name) {
            $copy = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'label' => 'Bản sao của '.AclHelper::shortRoleLabel($role->name),
                'description' => $role->description,
            ]);
            $copy->syncPermissions($role->name === Rbac::SUPER_ADMIN
                ? Rbac::superAdminPermissions()
                : $role->permissions->pluck('name')->all());
            Rbac::registerRole($copy);

            return $copy;
        });

        $this->log($copy, 'created', 'Nhân bản vai trò từ "'.$role->name.'"', [], $this->snapshot($copy->fresh()));

        return redirect()->route('roles.edit', $copy)->with('status', 'Đã nhân bản vai trò — đặt tên và chỉnh quyền cho vai trò mới.');
    }

    public function destroy(Role $role): Response|RedirectResponse
    {
        if ($role->name === Rbac::SUPER_ADMIN) {
            return $this->modalFailed('Không thể xóa vai trò Super Admin.', 'role');
        }
        if ($role->users()->exists()) {
            return $this->modalFailed('Không thể xóa vai trò đang được gán cho nhân viên.', 'role');
        }

        $before = $this->snapshot($role);
        $name = $role->name;
        DB::transaction(function () use ($role, $name) {
            $role->delete();
            Rbac::forgetRole($name);
        });

        activity(self::LOG)->causedBy(auth()->user())->event('deleted')
            ->withProperties(['old' => $before, 'name' => $name])
            ->log('Xóa vai trò "'.$name.'"');

        return $this->modalSaved('Đã xóa vai trò.', 'roles-changed', route('roles.index'));
    }

    /**
     * Tập quyền gửi lên từ ma trận: permissions[] (thao tác, đối tượng, gán vai trò) + scope[module] (mức phạm vi dữ
     * liệu). Không gửi scope cho module thì giữ quyền phạm vi có trong permissions[] (tương thích form cũ / API).
     *
     * @return list<string>
     */
    private function requestedPermissions(RoleRequest $request, ?Role $role): array
    {
        $permissions = collect($request->validated('permissions', []));

        foreach ((array) $request->validated('scope', []) as $module => $level) {
            $levels = PermissionCatalog::scopeLevels((string) $module);
            if ($levels === [] || ! in_array($level, $levels, true)) {
                continue;
            }
            $permissions = $permissions->reject(fn (string $p) => PermissionCatalog::isScope($p) && PermissionCatalog::moduleOf($p) === $module);
            // Mức thấp nhất của module là mặc định (không cần cấp): chỉ lưu mức cao hơn.
            if ($level !== $levels[0]) {
                $permissions->push(PermissionCatalog::scopePermission((string) $module, (string) $level));
            }
        }

        $names = $permissions->unique()->values()->all();
        $existing = Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('name')->all();

        return array_values(array_intersect($names, $existing));
    }

    /**
     * Gán permission cho vai trò là năng lực riêng (role.assign_permission):
     * người chỉ có role.create/update nhưng không có assign_permission không được đụng vào tập quyền.
     */
    private function authorizeAssigningPermissions(Request $request, bool $changesPermissions): void
    {
        if ($changesPermissions) {
            abort_unless($request->user()->can('role.assign_permission'), 403, 'Bạn không có quyền gán permission cho vai trò.');
        }
    }

    /** @param  list<string>  $permissions */
    private function ensureActorKeepsAccess(Request $request, Role $role, array $permissions): void
    {
        $actor = $request->user();
        $actorRoles = $actor->getRoleNames()->all();
        if (! in_array($role->name, $actorRoles, true)) {
            return;
        }

        $lost = Rbac::lostAccessManagement($actor, $actorRoles, [$role->name => $permissions]);
        if ($lost !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Bạn đang giữ vai trò này — không thể bỏ quyền quản trị phân quyền của chính mình ('
                    .collect($lost)->map(fn (string $p) => PermissionCatalog::label($p))->implode(', ').').',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function log(Role $role, string $event, string $description, array $before, array $after): void
    {
        $added = array_values(array_diff($after['permissions'] ?? [], $before['permissions'] ?? []));
        $removed = array_values(array_diff($before['permissions'] ?? [], $after['permissions'] ?? []));

        activity(self::LOG)->causedBy(auth()->user())->performedOn($role)->event($event)
            ->withProperties([
                'old' => $before,
                'attributes' => $after,
                'added' => $added,
                'removed' => $removed,
                // Tương thích nhật ký cũ.
                'permissions' => $after['permissions'] ?? [],
            ])
            ->log($description.' "'.$role->name.'"'.($added || $removed ? ' (+'.count($added).' / −'.count($removed).' quyền)' : ''));
    }

    /** Modal: chỉ tên / mã / mô tả (không dựng ma trận quyền); trang đầy đủ: kèm ma trận. */
    private function formView(Request $request, Role $role): Response
    {
        return $this->modalView('roles.form', $this->isModalRequest()
            ? [
                'role' => $role,
                'isSuperAdmin' => $role->exists && $role->name === Rbac::SUPER_ADMIN,
                'isSystemRole' => $role->exists && AclHelper::isSystemRole($role->name),
            ]
            : $this->formData($role, $request->user()));
    }

    /** @return array<string, mixed> */
    private function formData(Role $role, $actor): array
    {
        $roleNames = Role::query()->where('guard_name', 'web')->pluck('name')->all();
        $allNames = Permission::query()->where('guard_name', 'web')->pluck('name')
            ->merge(PermissionCatalog::allPermissions($roleNames))
            ->unique()
            ->reject(fn (string $name) => $name === Rbac::assignRolePermission(Rbac::SUPER_ADMIN))
            ->values();
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $selected = $role->exists
            ? ($role->name === Rbac::SUPER_ADMIN ? $allNames->reject(fn ($n) => PermissionCatalog::isAudience($n))->merge($role->permissions->pluck('name'))->unique()->all() : $role->permissions->pluck('name')->all())
            : [];

        return [
            'role' => $role,
            'groups' => PermissionCatalog::grouped($allNames->filter(fn ($n) => in_array($n, $existing, true))),
            'selected' => $selected,
            'isSuperAdmin' => $role->exists && $role->name === Rbac::SUPER_ADMIN,
            'isSystemRole' => $role->exists && AclHelper::isSystemRole($role->name),
            'canAssignPermissions' => (bool) $actor?->can('role.assign_permission'),
        ];
    }
}
