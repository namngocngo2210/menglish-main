{{-- Một ô của ma trận phân quyền cá nhân (mockup: checkbox). Ô tích = có quyền (sau khi áp phân quyền cá nhân).
     Gửi lên overrides[module][action] = inherit (trùng vai trò gốc) | allow (cho phép thêm) | deny (chặn).
     Action có dấu "." (user.assign_role.<vai trò>) gửi bằng ":" (khóa mảng form không chứa "."). --}}
@php
    $permissionName = "{$module}.{$action}";
    $formKey = str_replace('.', \App\Http\Controllers\UserPermissionOverrideController::ACTION_DOT, $action);
    $hasRolePermission = in_array($permissionName, $rolePermissions, true);
    $override = $overrides->get($permissionName);
    $oldDecision = old("overrides.{$module}.{$formKey}");
    $decision = $oldDecision ?? ($override?->allow === true ? 'allow' : ($override?->allow === false ? 'deny' : 'inherit'));
    $effective = $decision === 'allow' || ($decision === 'inherit' && $hasRolePermission);
    $description = \App\Support\PermissionCatalog::description($permissionName);
@endphp
<label x-data="{ on: @js($effective), role: @js($hasRolePermission) }"
       x-on:perm-set-all.window="on = $event.detail"
       x-on:perm-reset.window="on = role"
       x-on:perm-col.window="if ($event.detail.action === @js($action)) on = $event.detail.value"
       class="{{ $inline ? 'flex items-start justify-between gap-sm' : 'inline-flex flex-col items-center gap-[2px]' }} cursor-pointer"
       title="{{ $description ? $description.' — ' : '' }}{{ $permissionName }} — vai trò gốc: {{ $hasRolePermission ? 'có quyền' : 'không có quyền' }}">
    @if ($label)
        <span class="font-body-small text-body-small text-on-surface">
            {{ $label }}
            @if (\App\Support\PermissionCatalog::isAudience($permissionName))
                <span class="rounded bg-secondary-fixed/60 px-xs font-caption text-caption text-secondary">Đối tượng</span>
            @endif
        </span>
    @endif
    <span class="inline-flex flex-col items-center gap-[2px]">
        <input type="hidden" name="overrides[{{ $module }}][{{ $formKey }}]" value="{{ $decision }}"
               :value="on === role ? 'inherit' : (on ? 'allow' : 'deny')">
        <input type="checkbox" x-model="on" data-perm="{{ $permissionName }}" @checked($effective)
               class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container">
        <span class="text-[10px] font-semibold" :class="on === role ? 'invisible' : (on ? 'text-tertiary' : 'text-error')"
              x-text="on === role ? '·' : (on ? 'Cấp thêm' : 'Thu hồi')">{{ $decision === 'allow' ? 'Cấp thêm' : ($decision === 'deny' ? 'Thu hồi' : '') }}</span>
    </span>
</label>
