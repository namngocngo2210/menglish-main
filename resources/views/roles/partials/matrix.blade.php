{{-- Ma trận quyền của vai trò: nhóm → module; cột Xem / Thêm / Sửa / Xóa / Duyệt, "Thao tác khác", "Phạm vi dữ liệu".
     Vars: $groups (PermissionCatalog::grouped), $selected (tên quyền đang bật), $readonly, $superAdmin. --}}
@php
    use App\Support\PermissionCatalog;
    $columns = PermissionCatalog::matrixColumns();
    $levelLabels = PermissionCatalog::scopeLevelLabels();
    $selected = collect($selected)->all();
@endphp
@unless ($readonly)
    <input type="hidden" name="matrix_submitted" value="1">
@endunless
<x-ui.data-table min-width="1080px" data-testid="role-matrix">
    <x-slot:header>
        <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
            <span class="material-symbols-outlined text-primary-container" aria-hidden="true">grid_view</span>Ma trận quyền theo module
        </h2>
        <p class="font-body-small text-body-small text-on-surface-variant">Di chuột lên từng quyền để xem mô tả. "Đối tượng" = người dùng là ai (cổng, được xếp dạy lớp…), không phải thao tác.</p>
    </x-slot:header>
    <table>
        <thead>
            <tr>
                <th>Module</th>
                @foreach ($columns as $label)
                    <th class="text-center">{{ $label }}</th>
                @endforeach
                <th>Thao tác khác</th>
                <th class="min-w-[180px]">Phạm vi dữ liệu</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $groupLabel => $modules)
                <tr class="bg-surface-container-low" data-permission-group="{{ $groupLabel }}">
                    <td colspan="{{ 3 + count($columns) }}" class="font-label text-label uppercase text-on-surface-variant">{{ $groupLabel }}</td>
                </tr>
                @foreach ($modules as $module => $buckets)
                    @php
                        $byKey = collect($buckets['actions'])->keyBy(fn ($p) => PermissionCatalog::keyOf($p));
                        $others = collect($buckets['actions'])->reject(fn ($p) => array_key_exists(PermissionCatalog::keyOf($p), $columns))
                            ->merge($buckets['dynamic'])->merge($buckets['audience'])->values();
                        $levels = PermissionCatalog::scopeLevels($module);
                        $held = collect($levels)->filter(fn ($l) => in_array(PermissionCatalog::scopePermission($module, $l), $selected, true));
                        $currentLevel = old("scope.{$module}", $superAdmin ? 'all' : ($held->last() ?? ($levels[0] ?? null)));
                    @endphp
                    <tr class="align-top" data-module="{{ $module }}">
                        <td>
                            <div class="font-semibold text-on-surface">{{ PermissionCatalog::moduleLabel($module) }}</div>
                            <div class="font-code text-caption text-on-surface-variant">{{ $module }}</div>
                        </td>
                        @foreach (array_keys($columns) as $key)
                            <td class="text-center">
                                @if ($permission = $byKey->get($key))
                                    <label class="inline-flex cursor-pointer" title="{{ PermissionCatalog::label($permission) }} — {{ PermissionCatalog::description($permission) }} ({{ $permission }})">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission }}" data-perm="{{ $permission }}"
                                               @checked(in_array($permission, $selected, true)) @disabled($readonly)
                                               class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container">
                                        <span class="sr-only">{{ PermissionCatalog::label($permission) }}</span>
                                    </label>
                                @else
                                    <span class="text-outline" title="Module không có quyền này">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="min-w-[280px]">
                            @if ($others->isNotEmpty())
                                <div class="space-y-xs">
                                    @foreach ($others as $permission)
                                        <label class="flex cursor-pointer items-start gap-sm" title="{{ PermissionCatalog::description($permission) }} ({{ $permission }})">
                                            <input type="checkbox" name="permissions[]" value="{{ $permission }}" data-perm="{{ $permission }}"
                                                   @checked(in_array($permission, $selected, true)) @disabled($readonly)
                                                   class="mt-0.5 h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container">
                                            <span class="font-body-small text-body-small text-on-surface">
                                                {{ PermissionCatalog::label($permission) }}
                                                @if (PermissionCatalog::isAudience($permission))
                                                    <span class="rounded bg-secondary-fixed/60 px-xs font-caption text-caption text-secondary">Đối tượng</span>
                                                @endif
                                                <span class="block font-caption text-caption text-on-surface-variant">{{ PermissionCatalog::description($permission) }} <span class="font-code">{{ $permission }}</span></span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-outline">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($levels !== [])
                                <x-ui.select :name="'scope['.$module.']'" :aria-label="'Phạm vi dữ liệu '.PermissionCatalog::moduleLabel($module)" :disabled="$readonly" class="font-body-small text-body-small">
                                    @foreach ($levels as $level)
                                        <option value="{{ $level }}" @selected($currentLevel === $level) title="{{ PermissionCatalog::scopeLevelDescription($module, $level) }}">{{ $levelLabels[$level] ?? $level }}</option>
                                    @endforeach
                                </x-ui.select>
                                <p class="mt-xs font-caption text-caption text-on-surface-variant">{{ PermissionCatalog::scopeLevelDescription($module, $currentLevel) }}</p>
                            @else
                                <span class="font-body-small text-body-small text-on-surface-variant">Toàn hệ thống</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</x-ui.data-table>
