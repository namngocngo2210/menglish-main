{{--
    Form Sửa thông tin khách — nằm trong tab "Thông tin khách hàng" của hồ sơ (crm/show). ($asModal: id tiền tố "modal-", nút Lưu ở footer.)
    Trường hợp đồng khóa sau chốt (A3): Giá trị hợp đồng, Cơ sở, Khóa đăng ký.
    Biến: $customer, $branches, $salesUsers, $leadSources, $courseNames, $asModal (bool, tuỳ chọn).
--}}
@php
    $asModal = $asModal ?? false;
    $locked = $customer->isContractLocked();
    $id = fn (string $field) => $asModal ? 'modal-customer-'.$field : 'f_'.$field;
    $datalistId = ($asModal ? 'modal-' : '').'course-interest-options';
@endphp
<form action="{{ route('crm.customers.update', $customer->id) }}" method="POST" id="{{ $asModal ? 'modal-customer-form' : 'edit-lead-form' }}" @class(['space-y-md', 'p-lg' => ! $asModal])>
    @csrf
    @method('PUT')
    {{-- Lỗi validate → quay lại hồ sơ mở sẵn tab "Thông tin khách hàng" --}}
    <input type="hidden" name="_tab" value="info">

    @if ($locked)
        <x-ui.alert type="warning">Khách đã <strong>{{ $customer->stage_label }}</strong>: {{ implode(', ', \App\Models\CrmCustomer::CONTRACT_LOCKED_FIELDS) }} đã khóa, không sửa được tại đây.</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-md md:grid-cols-2">
        <x-ui.input name="name" :id="$id('name')" label="Họ tên" required placeholder="Nhập họ và tên" :value="$customer->name" />
        <x-ui.input name="phone" :id="$id('phone')" type="tel" label="Số điện thoại" required placeholder="0xxx xxx xxx" :value="$customer->phone" hint="10 số, bắt đầu bằng 0 (hoặc +84)" />
    </div>

    <x-ui.input name="parent_name" :id="$id('parent_name')" label="Tên phụ huynh" placeholder="Nhập tên phụ huynh (nếu có)" :value="$customer->parent_name" />

    <div class="grid grid-cols-1 gap-md md:grid-cols-2">
        <x-ui.select name="source" :id="$id('source')" label="Nguồn" required placeholder="Chọn nguồn" :value="$customer->source"
                     :options="$leadSources->mapWithKeys(fn ($s) => [$s => $s])->when($customer->source && ! $leadSources->contains($customer->source), fn ($o) => $o->put($customer->source, $customer->source.' (hiện tại)'))" />
        <div class="flex flex-col gap-xs">
            <x-ui.select name="branch_id" :id="$id('branch_id')" label="Chi nhánh" required placeholder="Chọn chi nhánh" :value="$customer->branch_id"
                         :options="$branches->pluck('name', 'id')" :disabled="$locked" />
            <p class="font-caption text-caption italic text-on-surface-variant">* Không thể thay đổi nếu học viên đã có lớp</p>
        </div>
    </div>

    <details class="group rounded-lg border border-surface-container-highest" open>
        <summary class="flex cursor-pointer select-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface-variant">
            Thông tin bổ sung
            <span class="material-symbols-outlined transition-transform group-open:rotate-180">expand_more</span>
        </summary>
        <div class="grid grid-cols-1 gap-md border-t border-surface-container-highest p-md md:grid-cols-2">
            <x-ui.input name="parent_phone" :id="$id('parent_phone')" type="tel" label="SĐT phụ huynh" placeholder="VD: 0912 345 678" :value="$customer->parent_phone" />
            <x-ui.input name="next_follow_up_at" type="datetime-local" label="Hạn liên hệ tiếp theo" :id="$asModal ? 'modal-customer-next_follow_up_at' : 'next_follow_up_at'"
                        :value="$customer->next_follow_up_at?->format('Y-m-d\TH:i')" hint='Pipeline báo "Sắp hết hạn" trước 24 giờ và "Quá hạn" khi quá hạn.' />
            <x-ui.input name="email" :id="$id('email')" type="email" label="Email" :value="$customer->email" />
            <x-ui.date name="dob" :id="$id('dob')" label="Ngày sinh" :value="old('dob', $customer->dob?->format('Y-m-d'))" />
            <x-ui.select name="gender" :id="$id('gender')" label="Giới tính" placeholder="-- Chọn --" :value="$customer->gender" :options="['Nam' => 'Nam', 'Nữ' => 'Nữ', 'Khác' => 'Khác']" />
            <x-ui.field label="Giai đoạn">
                <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-medium text-body-medium text-primary">{{ $customer->stage_label }}</div>
                <p class="font-caption text-caption text-on-surface-variant">Đổi giai đoạn tại Pipeline; "Đã chốt" chỉ tạo qua Chốt &amp; Xếp lớp.</p>
            </x-ui.field>
            @can('lead.assign')
                <x-ui.select name="assigned_user_id" :id="$id('assigned_user_id')" label="Người phụ trách" :value="$customer->assigned_user_id" :options="$salesUsers->pluck('name', 'id')" />
            @endcan
            <x-ui.field label="Khóa học quan tâm" name="course_interest" :for="$id('course_interest')">
                <input type="text" id="{{ $id('course_interest') }}" name="course_interest" @readonly($locked) value="{{ old('course_interest', $customer->course_interest) }}" placeholder="Chọn hoặc nhập khóa học" list="{{ $datalistId }}"
                       class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 {{ $locked ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-lowest' }}" />
                <datalist id="{{ $datalistId }}">
                    @foreach ($courseNames as $courseName)
                        <option value="{{ $courseName }}"></option>
                    @endforeach
                </datalist>
            </x-ui.field>
            <x-ui.field label="Giá trị hợp đồng (VNĐ)" name="deal_value" :for="$id('deal_value')">
                <div class="relative">
                    <input type="number" id="{{ $id('deal_value') }}" name="deal_value" @readonly($locked) value="{{ old('deal_value', $customer->deal_value) }}"
                           class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 {{ $locked ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-lowest' }}" />
                    @if ($locked)<span class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[18px] text-warning" title="Đã khóa">lock</span>@endif
                </div>
            </x-ui.field>
            <div class="md:col-span-2"><x-ui.input name="address" :id="$id('address')" label="Địa chỉ" :value="$customer->address" /></div>
            <div class="md:col-span-2"><x-ui.textarea name="notes" :id="$id('notes')" label="Ghi chú nhu cầu" rows="3" :value="$customer->notes" /></div>
        </div>
    </details>

    @unless ($asModal)
        <div class="flex items-center justify-end gap-md pt-lg">
            <x-ui.button variant="secondary" type="reset">Hoàn tác</x-ui.button>
            <x-ui.button type="submit" icon="save">Lưu thay đổi</x-ui.button>
        </div>
    @endunless
</form>
