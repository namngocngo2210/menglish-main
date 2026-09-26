{{--
    Form gán vai trò cho nhân sự — dùng chung cho trang đầy đủ (users/roles) và modal ($asModal).
    Biến: $user, $roles (withCount permissions), $asModal (bool, tuỳ chọn — id tiền tố "modal-", nút Lưu ở footer x-ui.modal-frame).
--}}
@php
    $asModal = $asModal ?? false;
    $checked = old('roles', $user->roles->pluck('name')->all());
@endphp
<form id="{{ $asModal ? 'modal-' : '' }}user-roles-form" method="POST" action="{{ route('users.roles.update', $user) }}" class="space-y-md">
    @csrf
    @method('PUT')

    @error('role')
        <x-ui.alert type="error">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.field label="Vai trò (chọn một hoặc nhiều)" name="roles" required hint="Vai trò đầu tiên là vai trò chính; các vai trò còn lại là kiêm nhiệm.">
        <div class="space-y-xs">
            @foreach ($roles as $role)
                <label class="group flex cursor-pointer items-center justify-between gap-md rounded-lg border border-outline-variant p-sm transition-colors hover:border-primary-container hover:bg-primary-container/5">
                    <span class="flex items-center gap-sm">
                        <input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked(in_array($role->name, $checked, true))
                               class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
                        <span>
                            <span class="block font-semibold text-on-surface group-hover:text-primary">{{ \App\Helpers\AclHelper::roleLabel($role->name) }}</span>
                            <span class="block font-code text-caption text-on-surface-variant">{{ $role->name }}</span>
                        </span>
                    </span>
                    <x-ui.badge color="neutral" :dot="false">{{ $role->permissions_count }} quyền</x-ui.badge>
                </label>
            @endforeach
        </div>
    </x-ui.field>

    @unless ($asModal)
        <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
            <x-ui.button variant="secondary" :href="route('users.index')">Hủy</x-ui.button>
            <x-ui.button type="submit" icon="save">Lưu thay đổi vai trò</x-ui.button>
        </div>
    @endunless
</form>
