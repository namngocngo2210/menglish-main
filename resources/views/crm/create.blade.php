<x-app-layout title="Thêm khách mới">
    {{-- Mockup crm-ui-mockup/them-khach-moi: hộp 560px — Họ và tên*, SĐT* (2 cột), Tên phụ huynh (tùy chọn), Nguồn khách*, Chi nhánh*, Hủy / Lưu thông tin.
         Các trường còn lại (email, ngày sinh, khóa quan tâm, hạn liên hệ, người phụ trách…) gom vào "Thông tin bổ sung". --}}
    <div class="mx-auto w-full max-w-[560px] py-md">
        <div class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-level-3">
            <div class="flex items-center justify-between px-xl pb-md pt-xl">
                <h1 class="font-h2 text-h2 text-primary">Thêm khách mới</h1>
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('crm.customers.index') }}" aria-label="Đóng"
                   class="rounded-full p-xs text-on-surface-variant transition-colors hover:bg-surface-variant active:scale-95">
                    <span class="material-symbols-outlined block">close</span>
                </a>
            </div>

            <form action="{{ route('crm.customers.store') }}" method="POST" class="space-y-md px-xl pb-xl" id="add-lead-form">
                @csrf

                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <x-ui.input name="name" label="Họ và tên" required placeholder="Nhập họ và tên khách" />
                    <x-ui.input name="phone" type="tel" label="Số điện thoại" required placeholder="Nhập số điện thoại" hint="10 số, bắt đầu bằng 0 (hoặc +84)" />
                </div>

                <x-ui.input name="parent_name" label="Tên phụ huynh (tùy chọn)" placeholder="Nhập tên phụ huynh nếu có" />

                <x-ui.select name="source" label="Nguồn khách" required placeholder="Chọn nguồn khách"
                             :options="$leadSources->mapWithKeys(fn ($s) => [$s => $s])" />

                <x-ui.select name="branch_id" label="Chi nhánh" required placeholder="Chọn cơ sở học tập"
                             :value="old('branch_id', $branches->count() === 1 ? $branches->first()->id : auth()->user()->branch_id)"
                             :options="$branches->pluck('name', 'id')" />

                <details class="group rounded-lg border border-surface-container-highest" @if ($errors->hasAny(['email', 'parent_phone', 'next_follow_up_at', 'assigned_user_id', 'dob', 'deal_value'])) open @endif>
                    <summary class="flex cursor-pointer select-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface-variant">
                        Thông tin bổ sung (tùy chọn)
                        <span class="material-symbols-outlined transition-transform group-open:rotate-180">expand_more</span>
                    </summary>
                    <div class="grid grid-cols-1 gap-md border-t border-surface-container-highest p-md sm:grid-cols-2">
                        <x-ui.input name="parent_phone" type="tel" label="SĐT phụ huynh" placeholder="0912 345 678" />
                        <x-ui.input name="next_follow_up_at" type="datetime-local" label="Hạn liên hệ tiếp theo" />
                        <x-ui.input name="email" type="email" label="Email" placeholder="hocvien@example.com" />
                        <x-ui.date name="dob" label="Ngày sinh" :value="old('dob')" />
                        <x-ui.select name="gender" label="Giới tính" placeholder="-- Chọn --" :options="['Nam' => 'Nam', 'Nữ' => 'Nữ', 'Khác' => 'Khác']" />
                        <x-ui.input name="course_interest" label="Khóa học quan tâm" placeholder="Ví dụ: Starters" />
                        <div class="sm:col-span-2"><x-ui.input name="address" label="Địa chỉ" /></div>
                        @can('lead.assign')
                            <x-ui.select name="assigned_user_id" label="Người phụ trách" placeholder="-- Chọn Sales phụ trách --"
                                         :value="old('assigned_user_id', auth()->id())" :options="$salesUsers->pluck('name', 'id')" />
                        @endcan
                        <x-ui.input name="deal_value" type="number" min="0" label="Giá trị dự kiến (VNĐ)" :value="old('deal_value', 0)" />
                        <div class="sm:col-span-2">
                            <x-ui.textarea name="notes" label="Ghi chú ban đầu" rows="3" />
                        </div>
                    </div>
                </details>

                <div class="flex items-center justify-end gap-md pt-lg">
                    <a href="{{ route('crm.customers.index') }}"
                       class="rounded-lg border border-outline-variant px-xl py-sm font-body-medium text-body-medium text-on-surface-variant transition-all hover:bg-surface-variant hover:text-on-surface active:scale-95">Hủy</a>
                    <button type="submit"
                            class="flex items-center gap-xs rounded-lg bg-primary-container px-xl py-sm font-body-medium text-body-medium text-white shadow-sm transition-all hover:shadow-md hover:brightness-110 active:scale-95">
                        Lưu thông tin
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
