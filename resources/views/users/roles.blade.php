{{-- Gán vai trò & chức vụ cho nhân sự: mở từ danh sách → modal (htmx); mở thẳng URL → trang đầy đủ. --}}
@if ($asModal)
    <x-ui.modal-frame :title="'Gán vai trò — '.$user->name" :description="($user->branch?->name ?? 'Chưa gán chi nhánh').' · '.$user->email">
        @include('users._roles-form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-user-roles-form" icon="save">Lưu thay đổi vai trò</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout :title="'Gán vai trò — '.$user->name">
        <x-ui.page-header :title="'Gán vai trò & Chức vụ — '.$user->name" description="Chọn một hoặc nhiều vai trò để gán quyền tương ứng cho nhân sự này.">
            <x-slot:breadcrumbs>
                <a href="{{ route('users.index') }}" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">group</span>Nhân sự</a>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>Gán vai trò chức vụ</span>
            </x-slot:breadcrumbs>
            <x-slot:actions>
                @can('permission.override')
                    <x-ui.button variant="secondary" icon="tune" :href="route('users.permissions.edit', $user)">Phân quyền chi tiết</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <div class="mx-auto grid max-w-5xl grid-cols-1 items-start gap-lg lg:grid-cols-12">
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg text-center lg:col-span-4">
                <div class="flex flex-col items-center gap-xs">
                    <x-ui.avatar :name="$user->name" />
                    <h2 class="font-h3 text-h3 text-on-surface">{{ $user->name }}</h2>
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</p>
                    <p class="font-code text-caption text-on-surface-variant">{{ $user->email }}</p>
                </div>
                <dl class="space-y-xs border-t border-surface-container pt-md text-left font-body-small text-body-small">
                    <div class="flex justify-between"><dt class="text-on-surface-variant">Mã NV</dt><dd class="font-code">{{ $user->employee_code ?? ('NV-'.str_pad($user->id, 4, '0', STR_PAD_LEFT)) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-on-surface-variant">Số điện thoại</dt><dd class="font-code">{{ $user->phone ?? 'Chưa cập nhật' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-on-surface-variant">Số vai trò hiện tại</dt><dd class="font-semibold text-primary">{{ $user->roles->count() }} vai trò</dd></div>
                </dl>
            </section>

            <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg lg:col-span-8">
                @include('users._roles-form')
            </section>
        </div>
    </x-app-layout>
@endif
