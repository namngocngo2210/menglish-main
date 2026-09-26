{{-- Một ô của ma trận phân quyền cá nhân (mockup: checkbox). Ô tích = có quyền (sau khi áp phân quyền cá nhân).
     Gửi lên overrides[module][action] = inherit (trùng vai trò gốc) | allow (cấp thêm) | deny (thu hồi). --}}
@php
    $permissionName = "{$module}.{$action}";
    $hasRolePermission = in_array($permissionName, $rolePermissions, true);
    $override = $overrides->get($permissionName);
    $oldDecision = old("overrides.{$module}.{$action}");
    $decision = $oldDecision ?? ($override?->allow === true ? 'allow' : ($override?->allow === false ? 'deny' : 'inherit'));
    $effective = $decision === 'allow' || ($decision === 'inherit' && $hasRolePermission);
@endphp
<label x-data="{ on: @js($effective), role: @js($hasRolePermission) }"
       x-on:perm-set-all.window="on = $event.detail"
       x-on:perm-reset.window="on = role"
       x-on:perm-col.window="if ($event.detail.action === @js($action)) on = $event.detail.value"
       class="{{ $inline ? 'flex items-center justify-between gap-sm' : 'inline-flex flex-col items-center gap-[2px]' }} cursor-pointer"
       title="{{ $permissionName }} — vai trò gốc: {{ $hasRolePermission ? 'có quyền' : 'không có quyền' }}">
    @if ($label)
        <span class="font-body-small text-body-small text-on-surface">{{ $label }}</span>
    @endif
    <input type="hidden" name="overrides[{{ $module }}][{{ $action }}]" value="{{ $decision }}"
           :value="on === role ? 'inherit' : (on ? 'allow' : 'deny')">
    <input type="checkbox" x-model="on" data-perm="{{ $permissionName }}" @checked($effective)
           class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container">
    <span class="text-[10px] font-semibold" :class="on === role ? 'invisible' : (on ? 'text-tertiary' : 'text-error')"
          x-text="on === role ? '·' : (on ? 'Cấp thêm' : 'Thu hồi')">{{ $decision === 'allow' ? 'Cấp thêm' : ($decision === 'deny' ? 'Thu hồi' : '') }}</span>
</label>
