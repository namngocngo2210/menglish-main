{{--
    Form Thêm khách mới — dùng chung trang đầy đủ (crm/create) và modal ($asModal: id tiền tố "modal-", nút Lưu ở footer).
    Biến: $branches, $salesUsers, $leadSources, $asModal (bool, tuỳ chọn).
--}}
@php
    $asModal = $asModal ?? false;
    $id = fn (string $field) => $asModal ? 'modal-customer-'.$field : 'f_'.$field;
@endphp
<form action="{{ route('crm.customers.store') }}" method="POST" id="{{ $asModal ? 'modal-customer-form' : 'add-lead-form' }}" @class(['space-y-md', 'px-xl pb-xl' => ! $asModal])>
    @csrf

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <x-ui.input name="name" :id="$id('name')" label="Họ và tên" required placeholder="Nhập họ và tên khách" />
        <x-ui.input name="phone" :id="$id('phone')" type="tel" label="Số điện thoại" required placeholder="Nhập số điện thoại" hint="10 số, bắt đầu bằng 0 (hoặc +84)" />
    </div>

    <x-ui.input name="parent_name" :id="$id('parent_name')" label="Tên phụ huynh (tùy chọn)" placeholder="Nhập tên phụ huynh nếu có" />

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <x-ui.select name="source" :id="$id('source')" label="Nguồn khách" required placeholder="Chọn nguồn khách"
                     :options="$leadSources->mapWithKeys(fn ($s) => [$s => $s])" />

        <x-ui.select name="branch_id" :id="$id('branch_id')" label="Chi nhánh" required placeholder="Chọn cơ sở học tập"
                     :value="old('branch_id', $branches->count() === 1 ? $branches->first()->id : auth()->user()->branch_id)"
                     :options="$branches->pluck('name', 'id')" />
    </div>

    <details class="group rounded-lg border border-surface-container-highest" @if ($errors->hasAny(['email', 'parent_phone', 'next_follow_up_at', 'assigned_user_id', 'dob', 'deal_value'])) open @endif>
        <summary class="flex cursor-pointer select-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface-variant">
            Thông tin bổ sung (tùy chọn)
            <span class="material-symbols-outlined transition-transform group-open:rotate-180">expand_more</span>
        </summary>
        <div class="grid grid-cols-1 gap-md border-t border-surface-container-highest p-md sm:grid-cols-2">
            <x-ui.input name="parent_phone" :id="$id('parent_phone')" type="tel" label="SĐT phụ huynh" placeholder="0912 345 678" />
            <x-ui.input name="next_follow_up_at" :id="$id('next_follow_up_at')" type="datetime-local" label="Hạn liên hệ tiếp theo" />
            <x-ui.input name="email" :id="$id('email')" type="email" label="Email" placeholder="hocvien@example.com" />
            <x-ui.date name="dob" :id="$id('dob')" label="Ngày sinh" :value="old('dob')" />
            <x-ui.select name="gender" :id="$id('gender')" label="Giới tính" placeholder="-- Chọn --" :options="['Nam' => 'Nam', 'Nữ' => 'Nữ', 'Khác' => 'Khác']" />
            <x-ui.input name="course_interest" :id="$id('course_interest')" label="Khóa học quan tâm" placeholder="Ví dụ: Starters" />
            <div class="sm:col-span-2"><x-ui.input name="address" :id="$id('address')" label="Địa chỉ" /></div>
            @can('lead.assign')
                <x-ui.select name="assigned_user_id" :id="$id('assigned_user_id')" label="Người phụ trách" placeholder="-- Chọn Sales phụ trách --"
                             :value="old('assigned_user_id', auth()->id())" :options="$salesUsers->pluck('name', 'id')" />
            @endcan
            <x-ui.input name="deal_value" :id="$id('deal_value')" type="number" min="0" label="Giá trị dự kiến (VNĐ)" :value="old('deal_value', 0)" />
            <div class="sm:col-span-2">
                <x-ui.textarea name="notes" :id="$id('notes')" label="Ghi chú ban đầu" rows="3" />
            </div>
        </div>
    </details>

    @unless ($asModal)
        <div class="flex items-center justify-end gap-md pt-lg">
            <x-ui.button variant="secondary" :href="route('crm.customers.index')">Hủy</x-ui.button>
            <x-ui.button type="submit">Lưu thông tin</x-ui.button>
        </div>
    @endunless
</form>
