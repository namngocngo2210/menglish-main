{{-- Một ô trong ma trận phân quyền cá nhân: kế thừa (—) / cấp thêm (✓) / thu hồi (✕). --}}
@php
    $permissionName = "{$module}.{$action}";
    $hasRolePermission = in_array($permissionName, $rolePermissions, true);
    $override = $overrides->get($permissionName);
    $current = old("overrides.{$module}.{$action}", $override?->allow === true ? 'allow' : ($override?->allow === false ? 'deny' : 'inherit'));
    $tone = fn ($value) => match ($value) {
        'allow' => 'bg-emerald-50 text-emerald-800 border-emerald-300',
        'deny' => 'bg-rose-50 text-rose-800 border-rose-300',
        default => 'bg-white text-gray-700 border-gray-200',
    };
@endphp
<div class="{{ $inline ? 'flex items-center justify-between gap-2' : 'inline-flex flex-col items-center gap-0.5' }}">
    @if ($label)
        <span class="text-[11px] text-gray-700">{{ $label }}</span>
    @endif
    <select name="overrides[{{ $module }}][{{ $action }}]"
            title="{{ $permissionName }} — vai trò gốc: {{ $hasRolePermission ? 'có quyền' : 'không có quyền' }}"
            class="permission-select rounded-lg border text-xs py-1 pl-2 pr-7 font-semibold shadow-2xs {{ $tone($current) }}"
            onchange="this.classList.remove('bg-emerald-50','text-emerald-800','border-emerald-300','bg-rose-50','text-rose-800','border-rose-300','bg-white','text-gray-700','border-gray-200'); this.classList.add(...({allow:['bg-emerald-50','text-emerald-800','border-emerald-300'],deny:['bg-rose-50','text-rose-800','border-rose-300']}[this.value] || ['bg-white','text-gray-700','border-gray-200']))">
        <option value="inherit" @selected($current === 'inherit')>— {{ $hasRolePermission ? '(có)' : '(không)' }}</option>
        <option value="allow" @selected($current === 'allow')>✓ Cấp</option>
        <option value="deny" @selected($current === 'deny')>✕ Thu hồi</option>
    </select>
</div>
