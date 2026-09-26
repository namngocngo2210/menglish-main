{{--
    Cấu hình ngày nghỉ (mockup epic-5/cau-hinh-ngay-nghi): danh sách; Thêm/Sửa mở modal (htmx), Xóa qua modal xác nhận.
    Mở thẳng URL create/edit (holidays/form, không phải htmx) → danh sách bên trái + form bên phải như mockup.
    Lưu/xóa xong server phát sự kiện "holidays-changed" → #holiday-list tự tải lại (giữ tìm kiếm, trang hiện tại).
--}}
@php
    $canManage = auth()->user()->can('holiday.manage');
    $holiday ??= null; // chỉ có khi mở trang create/edit đầy đủ
@endphp
<x-app-layout title="Cấu hình ngày nghỉ">
    <x-ui.page-header title="Cấu hình ngày nghỉ" description="Ngày nghỉ lễ / nghỉ riêng của chi nhánh dùng để sinh lịch học, hủy buổi trùng và xếp buổi học bù.">
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button icon="add" :href="route('holidays.create')" modal="md">Thêm ngày nghỉ</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert type="info" title="Lưu ý nghiệp vụ" class="mb-lg">
        Hệ thống hiện tại chỉ hỗ trợ cấu hình ngày nghỉ theo Chi nhánh hoặc Toàn hệ thống. Tính năng cấu hình riêng cho từng lớp cụ thể đang được phát triển.
    </x-ui.alert>

    @if (session('status'))
        <x-ui.alert type="success" class="mb-lg" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12" x-data="{ del: { url: '', name: '' } }">
        {{-- Danh sách ngày nghỉ --}}
        <section class="{{ $holiday && $canManage ? 'lg:col-span-8' : 'lg:col-span-12' }}">
            <div id="holiday-list" hx-get="{{ route('holidays.index', request()->query()) }}" hx-trigger="holidays-changed from:body" hx-select="#holiday-list" hx-swap="outerHTML" hx-disinherit="*">
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
                                <tr @class(['bg-primary-container/5' => $holiday?->exists && $holiday->id === $row->id])>
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
                                            <x-ui.button variant="ghost" icon="edit" :href="route('holidays.edit', $row)" modal="md" title="Sửa" aria-label="Sửa {{ $row->name }}" />
                                            <x-ui.button variant="danger-text" icon="delete" title="Xóa" aria-label="Xóa {{ $row->name }}"
                                                         data-url="{{ route('holidays.destroy', $row) }}" data-name="{{ $row->name }}"
                                                         x-on:click="del = { url: $el.dataset.url, name: $el.dataset.name }; $dispatch('open-modal', 'delete-holiday')" />
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <x-ui.empty-state icon="event_available" :title="request('search') ? 'Không tìm thấy ngày nghỉ' : 'Chưa có ngày nghỉ nào'"
                                            :description="request('search') ? 'Thử đổi từ khóa tìm kiếm.' : 'Bấm “Thêm ngày nghỉ” để tạo mới.'" />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    <x-slot:footer><x-ui.pagination :paginator="$holidays" unit="ngày nghỉ" /></x-slot:footer>
                </x-ui.data-table>
            </div>
        </section>

        {{-- Form Thêm / Sửa (chỉ khi mở thẳng URL create/edit) --}}
        @if ($canManage && $holiday)
            <aside class="lg:col-span-4 lg:sticky lg:top-md">
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                    <div class="flex items-center gap-sm border-b border-surface-container p-md">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">edit_note</span>
                        <h2 class="font-h3 text-h3 text-on-surface">{{ $holiday->exists ? 'Sửa ngày nghỉ' : 'Thông tin ngày nghỉ' }}</h2>
                    </div>
                    @include('holidays._form', ['asModal' => false])
                </div>
            </aside>
        @endif

        {{-- Xác nhận xóa (dùng chung cho mọi dòng; url/tên lấy từ nút Xóa) --}}
        @if ($canManage)
            <x-ui.modal name="delete-holiday" title="Xóa ngày nghỉ?" max-width="md">
                <p>Xóa ngày nghỉ <strong class="font-semibold" x-text="del.name"></strong>?</p>
                <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Buổi học bị hủy do ngày nghỉ này sẽ được khôi phục, buổi bù tương ứng được gỡ.</p>
                <form id="delete-holiday-form" method="POST" :action="del.url" hx-boost="true" hx-swap="none" hx-push-url="false">
                    @csrf @method('DELETE')
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'delete-holiday')">Hủy</x-ui.button>
                    <x-ui.button variant="danger" type="submit" form="delete-holiday-form" icon="delete">Xóa</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
