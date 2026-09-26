{{--
    Form Thêm/Sửa ngày nghỉ — dùng chung cho trang đầy đủ (holidays/index, cột phải) và modal (holidays/form, $asModal).
    Biến: $holiday, $branches, $selectedBranchIds, $asModal (bool, tuỳ chọn).
    Trong modal: id có tiền tố "modal-" (tránh trùng với form ở trang), nút Lưu nằm ở footer của x-ui.modal-frame.
--}}
@php
    $asModal = $asModal ?? false;
    $p = $asModal ? 'modal-' : '';
    $oldBranchIds = array_map('intval', old('branch_ids', $selectedBranchIds));
@endphp
<form id="{{ $p }}holiday-form" method="POST" action="{{ $holiday->exists ? route('holidays.update', $holiday) : route('holidays.store') }}" @class(['space-y-md', 'p-md' => ! $asModal])>
    @csrf
    @if ($holiday->exists) @method('PUT') @endif

    <x-ui.input name="name" id="{{ $p }}holiday-name" label="Tên ngày nghỉ" required placeholder="Ví dụ: Tết Trung Thu" :value="$holiday->name" />
    <div class="grid grid-cols-2 gap-sm">
        <x-ui.date name="start_date" id="{{ $p }}start-date" label="Từ ngày" required :value="$holiday->start_date" />
        <x-ui.date name="end_date" id="{{ $p }}end-date" label="Đến ngày" required :value="$holiday->end_date" />
    </div>
    <x-ui.input name="code" id="{{ $p }}holiday-code" label="Mã ngày nghỉ" :value="$holiday->code" placeholder="Tự sinh HOL-YYYY-NNN" hint="Để trống để hệ thống tự sinh mã." />

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

    @unless ($asModal)
        <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
            <x-ui.button variant="secondary" :href="route('holidays.index')">Hủy bỏ</x-ui.button>
            <x-ui.button type="submit">Lưu thông tin</x-ui.button>
        </div>
    @endunless
</form>
