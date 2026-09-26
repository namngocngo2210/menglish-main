<x-app-layout>
    <x-ui.page-header title="Quản Lý Cơ Sở & Chi Nhánh Trung Tâm" icon="apartment">
        <x-slot:breadcrumbs>
            <a href="{{ route('dashboard') }}" class="hover:text-on-surface transition flex items-center gap-1">
                <span class="material-symbols-outlined text-[16px]">home</span>
                <span>Trang chủ</span>
            </a>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="text-primary-container font-semibold">Cơ sở &amp; Chi nhánh</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button icon="add_business" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'create-branch' }))">Thêm Chi Nhánh Mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6" x-data="{
        editData: { id: null, code: '', name: '', address: '', phone: '', is_active: true },
        openEdit(b) {
            this.editData = { ...b };
            this.$dispatch('open-modal', 'edit-branch');
        }
    }">

        {{-- Flash messages --}}

        @if (session('error') || $errors->any())
            <x-ui.alert type="error">{{ session('error') ?? $errors->first() }}</x-ui.alert>
        @endif

        {{-- Quick Stats Overview --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-ui.stat-card label="Tổng chi nhánh" :value="$totalBranches" tone="secondary" icon="apartment" />
            <x-ui.stat-card label="Đang hoạt động" :value="$activeBranches" tone="success" icon="check_circle" />
            <x-ui.stat-card label="Tổng học viên" :value="$totalStudents" tone="primary" icon="school" />
            <x-ui.stat-card label="Lớp đang mở" :value="$totalClasses" icon="meeting_room" />
        </div>

        {{-- Filter & Search Bar --}}
        <x-ui.filter-bar :action="route('branches.index')" :reset-url="route('branches.index')" placeholder="Tìm tên, mã, địa chỉ, số hotline..." class="!mb-0">
            <x-ui.select name="status" :options="['1' => 'Đang hoạt động', '0' => 'Tạm dừng']" placeholder="Tất cả trạng thái" aria-label="Trạng thái" onchange="this.form.submit()" />
            <div class="text-xs text-on-surface-variant font-mono shrink-0">
                Hiển thị: <strong>{{ $branches->count() }}</strong> cơ sở chi nhánh
            </div>
        </x-ui.filter-bar>

        {{-- Branches Table List --}}
        <x-ui.data-table class="shadow-sm">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="w-16 text-center">STT</th>
                        <th class="w-28">Mã cơ sở</th>
                        <th>Tên Chi Nhánh &amp; Địa Chỉ</th>
                        <th class="w-36">Hotline liên hệ</th>
                        <th class="text-center w-36">Quy mô hoạt động</th>
                        <th class="text-center w-32">Trạng thái</th>
                        <th class="text-right w-24">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $idx => $branch)
                        <tr>
                            <td class="text-center font-mono font-bold text-on-surface-variant/70">
                                {{ $idx + 1 }}
                            </td>
                            <td class="font-mono font-bold text-secondary">
                                <span class="px-2.5 py-1 rounded-lg bg-secondary/10 border border-secondary/30">
                                    {{ $branch->code }}
                                </span>
                            </td>
                            <td>
                                <div class="font-bold text-on-surface text-sm mb-0.5">{{ $branch->name }}</div>
                                <div class="text-[11px] text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px] text-on-surface-variant/70">location_on</span>
                                    <span>{{ $branch->address }}</span>
                                </div>
                            </td>
                            <td class="font-mono font-medium text-on-surface-variant">
                                {{ $branch->phone ?: 'Chưa cập nhật' }}
                            </td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-2 text-[11px] font-mono">
                                    <x-ui.badge color="secondary" :dot="false" title="Số lớp học">{{ $branch->classes_count }} Lớp</x-ui.badge>
                                    <x-ui.badge color="success" :dot="false" title="Số học viên">{{ $branch->students_count }} HV</x-ui.badge>
                                    <x-ui.badge color="primary" :dot="false" title="Nhân sự phụ trách">{{ $branch->users_count }} NS</x-ui.badge>
                                </div>
                            </td>
                            <td class="text-center">
                                <form action="{{ route('branches.toggle', $branch->id) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="cursor-pointer" title="Click để Bật / Tắt hoạt động">
                                        @if ($branch->is_active)
                                            <x-ui.badge color="success" :pill="true" class="uppercase">Hoạt động</x-ui.badge>
                                        @else
                                            <x-ui.badge color="neutral" :pill="true" class="uppercase">Tạm dừng</x-ui.badge>
                                        @endif
                                    </button>
                                </form>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    <x-ui.button variant="ghost" size="sm" icon="edit" x-on:click="openEdit({{ Js::from($branch) }})" title="Chỉnh sửa" aria-label="Chỉnh sửa" />
                                    <form action="{{ route('branches.destroy', $branch->id) }}" method="POST" class="inline" data-confirm="Bạn có chắc chắn muốn xóa chi nhánh {{ $branch->name }}?">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" aria-label="Xóa" />
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7"><x-ui.empty-state icon="apartment" title="Không tìm thấy cơ sở chi nhánh nào phù hợp." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        {{-- ────────────────────────────────────────────── --}}
        {{-- MODAL: THÊM CHI NHÁNH MỚI --}}
        {{-- ────────────────────────────────────────────── --}}
        <x-ui.modal name="create-branch" title="Thêm Cơ Sở Chi Nhánh Mới" max-width="md">
            <form id="createBranchForm" action="{{ route('branches.store') }}" method="POST" class="space-y-3">
                @csrf
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-1">
                        <x-ui.input name="code" id="create_code" label="Mã chi nhánh" required placeholder="VD: CG" class="font-mono font-bold uppercase" />
                    </div>
                    <div class="col-span-2">
                        <x-ui.input name="name" id="create_name" label="Tên chi nhánh" required placeholder="VD: Chi nhánh Cầu Giấy" />
                    </div>
                </div>

                <x-ui.input name="address" id="create_address" label="Địa chỉ chi nhánh" required placeholder="Số nhà, Đường, Quận, Thành phố..." />

                <x-ui.input name="phone" id="create_phone" label="Số điện thoại Hotline" placeholder="0243 555 0101" class="font-mono" />

                <div class="pt-1 flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" id="is_active_create" checked class="w-4 h-4 text-primary-container focus:ring-primary-container rounded border-outline-variant cursor-pointer" />
                    <label for="is_active_create" class="text-xs font-semibold text-on-surface-variant cursor-pointer">Kích hoạt chi nhánh ngay sau khi tạo</label>
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'create-branch')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="createBranchForm">Tạo Chi Nhánh</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- ────────────────────────────────────────────── --}}
        {{-- MODAL: SỬA CHI NHÁNH --}}
        {{-- ────────────────────────────────────────────── --}}
        <x-ui.modal name="edit-branch" title="Chỉnh Sửa Cơ Sở Chi Nhánh" max-width="md">
            <form id="editBranchForm" :action="'/branches/' + editData.id" method="POST" class="space-y-3">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-1">
                        <x-ui.input name="code" id="edit_code" label="Mã chi nhánh" x-model="editData.code" required class="font-mono font-bold uppercase" />
                    </div>
                    <div class="col-span-2">
                        <x-ui.input name="name" id="edit_name" label="Tên chi nhánh" x-model="editData.name" required />
                    </div>
                </div>

                <x-ui.input name="address" id="edit_address" label="Địa chỉ chi nhánh" x-model="editData.address" required />

                <x-ui.input name="phone" id="edit_phone" label="Số điện thoại Hotline" x-model="editData.phone" class="font-mono" />

                <div class="pt-1 flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" id="is_active_edit" :checked="editData.is_active" class="w-4 h-4 text-primary-container focus:ring-primary-container rounded border-outline-variant cursor-pointer" />
                    <label for="is_active_edit" class="text-xs font-semibold text-on-surface-variant cursor-pointer">Trạng thái: Đang hoạt động</label>
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'edit-branch')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="editBranchForm">Lưu Thay Đổi</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

    </div>
</x-app-layout>
