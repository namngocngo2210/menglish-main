{{-- Cấu hình ngày nghỉ (mockup epic-5/cau-hinh-ngay-nghi): danh sách bên trái + form Thêm/Sửa bên phải trên cùng một trang. --}}
@php
    $canManage = auth()->user()->can('holiday.manage');
    $oldBranchIds = array_map('intval', old('branch_ids', $selectedBranchIds));
@endphp
<x-app-layout title="Cấu hình ngày nghỉ">
    <x-ui.page-header title="Cấu hình ngày nghỉ" description="Ngày nghỉ lễ / nghỉ riêng của chi nhánh dùng để sinh lịch học, hủy buổi trùng và xếp buổi học bù.">
        <x-slot:actions>
            @if ($canManage && $holiday->exists)
                <x-ui.button icon="add" :href="route('holidays.index')">Thêm ngày nghỉ mới</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert type="info" title="Lưu ý nghiệp vụ" class="mb-lg">
        Hệ thống hiện tại chỉ hỗ trợ cấu hình ngày nghỉ theo Chi nhánh hoặc Toàn hệ thống. Tính năng cấu hình riêng cho từng lớp cụ thể đang được phát triển.
    </x-ui.alert>

    @if (session('status'))
        <x-ui.alert type="success" class="mb-lg" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
        {{-- Danh sách ngày nghỉ --}}
        <section class="{{ $canManage ? 'lg:col-span-8' : 'lg:col-span-12' }}">
            <x-ui.data-table min-width="640px">
                <x-slot:header>
                    <h2 class="font-h3 text-h3 text-on-surface">Danh sách ngày nghỉ</h2>
                    <form method="GET" action="{{ route('holidays.index') }}" role="search" class="relative">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant" aria-hidden="true">search</span>
                        <input type="search" name="search" value="{{ request('search') }}" placeholder="Tìm kiếm ngày nghỉ..." aria-label="Tìm kiếm ngày nghỉ"
                               class="w-60 rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-9 pr-md font-body-small text-body-small focus:border-primary-container focus:ring-2 focus:ring-primary-container/20">
                    </form>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Tên ngày nghỉ</th>
                            <th>Thời gian</th>
                            <th>Phạm vi áp dụng</th>
                            @if ($canManage)<th class="text-right">Thao tác</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($holidays as $row)
                            <tr @class(['bg-primary-container/5' => $holiday->exists && $holiday->id === $row->id])>
                                <td>
                                    <div class="font-semibold text-on-surface">{{ $row->name }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">Mã: <span class="font-code">{{ $row->code }}</span></div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-xs whitespace-nowrap font-code text-code">
                                        <span>{{ $row->start_date->format('d/m/Y') }}</span>
                                        <span class="material-symbols-outlined text-[16px] text-on-surface-variant" aria-hidden="true">arrow_forward</span>
                                        <span>{{ $row->end_date->format('d/m/Y') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-xs">
                                        @if ($row->is_system_wide)
                                            <x-ui.badge color="primary" :dot="false">Toàn hệ thống</x-ui.badge>
                                        @else
                                            @foreach ($row->branches as $branch)
                                                <x-ui.badge color="secondary" :dot="false">{{ $branch->name }}</x-ui.badge>
                                            @endforeach
                                        @endif
                                    </div>
                                </td>
                                @if ($canManage)
                                    <td class="whitespace-nowrap text-right">
                                        <x-ui.button variant="ghost" icon="edit" :href="route('holidays.edit', $row)" title="Sửa" aria-label="Sửa {{ $row->name }}" />
                                        <form action="{{ route('holidays.destroy', $row) }}" method="POST" class="inline" onsubmit="return confirm('Xóa ngày nghỉ này? Buổi học bị hủy do ngày nghỉ sẽ được khôi phục.');">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger-text" icon="delete" title="Xóa" aria-label="Xóa {{ $row->name }}" />
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty-state icon="event_available" :title="request('search') ? 'Không tìm thấy ngày nghỉ' : 'Chưa có ngày nghỉ nào'"
                                        :description="request('search') ? 'Thử đổi từ khóa tìm kiếm.' : 'Thêm ngày nghỉ ở form bên phải.'" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$holidays" unit="ngày nghỉ" /></x-slot:footer>
            </x-ui.data-table>
        </section>

        {{-- Form Thêm / Sửa --}}
        @if ($canManage)
            <aside class="lg:col-span-4 lg:sticky lg:top-md">
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                    <div class="flex items-center gap-sm border-b border-surface-container p-md">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">edit_note</span>
                        <h2 class="font-h3 text-h3 text-on-surface">{{ $holiday->exists ? 'Sửa ngày nghỉ' : 'Thông tin ngày nghỉ' }}</h2>
                    </div>
                    <form method="POST" action="{{ $holiday->exists ? route('holidays.update', $holiday) : route('holidays.store') }}" class="space-y-md p-md">
                        @csrf
                        @if ($holiday->exists) @method('PUT') @endif

                        <x-ui.input name="name" id="holiday-name" label="Tên ngày nghỉ" required placeholder="Ví dụ: Tết Trung Thu" :value="$holiday->name" />
                        <div class="grid grid-cols-2 gap-sm">
                            <x-ui.date name="start_date" id="start-date" label="Từ ngày" required :value="$holiday->start_date" />
                            <x-ui.date name="end_date" id="end-date" label="Đến ngày" required :value="$holiday->end_date" />
                        </div>
                        <x-ui.input name="code" id="holiday-code" label="Mã ngày nghỉ" :value="$holiday->code" placeholder="Tự sinh HOL-YYYY-NNN" hint="Để trống để hệ thống tự sinh mã." />

                        <x-ui.field label="Phạm vi áp dụng" name="branch_ids">
                            <div class="max-h-48 space-y-xs overflow-y-auto rounded-lg border border-outline-variant p-sm" x-data="{ picked: @js($oldBranchIds) }">
                                <p class="font-body-small text-body-small font-semibold" :class="picked.length ? 'text-on-surface-variant' : 'text-primary'">
                                    <span x-text="picked.length ? 'Chi nhánh đã chọn: ' + picked.length : 'Toàn hệ thống (Mặc định)'"></span>
                                </p>
                                @foreach ($branches as $branch)
                                    <label class="flex items-center gap-sm font-body-small text-body-small text-on-surface">
                                        <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" x-model.number="picked"
                                               class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
                                        {{ $branch->name }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="font-caption text-caption text-on-surface-variant">* Để trống nếu muốn áp dụng cho tất cả chi nhánh.</p>
                        </x-ui.field>

                        <div class="flex items-start gap-sm rounded-lg bg-tertiary-fixed/30 p-sm">
                            <span class="material-symbols-outlined text-tertiary" aria-hidden="true">verified</span>
                            <p class="font-caption text-caption text-on-tertiary-fixed-variant">Lịch nghỉ sẽ tự động cập nhật vào lịch học của các lớp liên quan: buổi trùng ngày nghỉ chuyển "Đã hủy" và được xếp 1 buổi học bù cuối lịch (buổi đã điểm danh giữ nguyên).</p>
                        </div>

                        <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                            <x-ui.button variant="secondary" :href="route('holidays.index')">Hủy bỏ</x-ui.button>
                            <x-ui.button type="submit">Lưu thông tin</x-ui.button>
                        </div>
                    </form>
                </div>
            </aside>
        @endif
    </div>
</x-app-layout>
