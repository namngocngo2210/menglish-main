<x-app-layout title="Sửa thông tin khách">
    {{-- Mockup crm-ui-mockup/sua-thong-tin-khach: Họ tên*, SĐT* | Tên phụ huynh | Nguồn*, Chi nhánh* ("Không thể thay đổi nếu học viên đã có lớp"), Hủy / Lưu thay đổi.
         Trường hợp đồng khóa sau chốt (A3): Giá trị hợp đồng, Cơ sở, Khóa đăng ký. --}}
    @php($locked = $customer->isContractLocked())
    <div class="mx-auto w-full max-w-[720px] py-md">
        <div class="overflow-hidden rounded-lg border border-surface-container-highest bg-surface-container-lowest shadow-level-3">
            <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low px-lg py-md">
                <div class="flex min-w-0 items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1;">edit_square</span>
                    <div class="min-w-0">
                        <h1 class="font-h2 text-h2 text-on-surface">Sửa thông tin khách</h1>
                        <p class="truncate font-code text-caption text-on-surface-variant">{{ $customer->name }} · {{ $customer->code }}</p>
                    </div>
                </div>
                <a href="{{ route('crm.customers.show', $customer->id) }}" aria-label="Đóng" class="group rounded-full p-xs transition-colors hover:bg-surface-container-highest">
                    <span class="material-symbols-outlined text-on-surface-variant group-hover:text-on-surface">close</span>
                </a>
            </div>

            <form action="{{ route('crm.customers.update', $customer->id) }}" method="POST" class="space-y-md p-lg">
                @csrf
                @method('PUT')

                @if ($locked)
                    <x-ui.alert type="warning">Khách đã <strong>{{ $customer->stage_label }}</strong>: {{ implode(', ', \App\Models\CrmCustomer::CONTRACT_LOCKED_FIELDS) }} đã khóa, không sửa được tại đây.</x-ui.alert>
                @endif

                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <x-ui.input name="name" label="Họ tên" required placeholder="Nhập họ và tên" :value="$customer->name" />
                    <x-ui.input name="phone" type="tel" label="Số điện thoại" required placeholder="0xxx xxx xxx" :value="$customer->phone" hint="10 số, bắt đầu bằng 0 (hoặc +84)" />
                </div>

                <x-ui.input name="parent_name" label="Tên phụ huynh" placeholder="Nhập tên phụ huynh (nếu có)" :value="$customer->parent_name" />

                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <x-ui.select name="source" label="Nguồn" required placeholder="Chọn nguồn" :value="$customer->source"
                                 :options="$leadSources->mapWithKeys(fn ($s) => [$s => $s])->when($customer->source && ! $leadSources->contains($customer->source), fn ($o) => $o->put($customer->source, $customer->source.' (hiện tại)'))" />
                    <div class="flex flex-col gap-xs">
                        <x-ui.select name="branch_id" label="Chi nhánh" required placeholder="Chọn chi nhánh" :value="$customer->branch_id"
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
                        <x-ui.input name="parent_phone" type="tel" label="SĐT phụ huynh" placeholder="VD: 0912 345 678" :value="$customer->parent_phone" />
                        <x-ui.input name="next_follow_up_at" type="datetime-local" label="Hạn liên hệ tiếp theo" id="next_follow_up_at"
                                    :value="$customer->next_follow_up_at?->format('Y-m-d\TH:i')" hint='Pipeline báo "Sắp hết hạn" trước 24 giờ và "Quá hạn" khi quá hạn.' />
                        <x-ui.input name="email" type="email" label="Email" :value="$customer->email" />
                        <x-ui.date name="dob" label="Ngày sinh" :value="old('dob', $customer->dob?->format('Y-m-d'))" />
                        <x-ui.select name="gender" label="Giới tính" placeholder="-- Chọn --" :value="$customer->gender" :options="['Nam' => 'Nam', 'Nữ' => 'Nữ', 'Khác' => 'Khác']" />
                        <x-ui.field label="Giai đoạn">
                            <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-medium text-body-medium text-primary">{{ $customer->stage_label }}</div>
                            <p class="font-caption text-caption text-on-surface-variant">Đổi giai đoạn tại Pipeline; "Đã chốt" chỉ tạo qua Chốt &amp; Xếp lớp.</p>
                        </x-ui.field>
                        @can('lead.assign')
                            <x-ui.select name="assigned_user_id" label="Người phụ trách" :value="$customer->assigned_user_id" :options="$salesUsers->pluck('name', 'id')" />
                        @endcan
                        <x-ui.field label="Khóa học quan tâm" name="course_interest" for="f_course_interest">
                            <input type="text" id="f_course_interest" name="course_interest" @readonly($locked) value="{{ old('course_interest', $customer->course_interest) }}" placeholder="Chọn hoặc nhập khóa học" list="course-interest-options"
                                   class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 {{ $locked ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-lowest' }}" />
                            <datalist id="course-interest-options">
                                @foreach (\App\Models\Course::where('is_active', true)->orderBy('name')->pluck('name') as $courseName)
                                    <option value="{{ $courseName }}"></option>
                                @endforeach
                            </datalist>
                        </x-ui.field>
                        <x-ui.field label="Giá trị hợp đồng (VNĐ)" name="deal_value" for="f_deal_value">
                            <div class="relative">
                                <input type="number" id="f_deal_value" name="deal_value" @readonly($locked) value="{{ old('deal_value', $customer->deal_value) }}"
                                       class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 {{ $locked ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-lowest' }}" />
                                @if ($locked)<span class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[18px] text-amber-600" title="Đã khóa">lock</span>@endif
                            </div>
                        </x-ui.field>
                        <div class="md:col-span-2"><x-ui.input name="address" label="Địa chỉ" :value="$customer->address" /></div>
                        <div class="md:col-span-2"><x-ui.textarea name="notes" label="Ghi chú nhu cầu" rows="3" :value="$customer->notes" /></div>
                    </div>
                </details>

                <div class="flex items-center justify-end gap-md pt-lg">
                    <a href="{{ route('crm.customers.show', $customer->id) }}"
                       class="rounded-lg border border-outline-variant px-lg py-sm font-body-medium text-body-medium text-on-surface transition-all hover:bg-surface-container-highest active:scale-95">Hủy</a>
                    <button type="submit"
                            class="flex items-center gap-sm rounded-lg bg-primary-container px-xl py-sm font-body-medium text-body-medium text-white shadow-md transition-all hover:opacity-90 hover:shadow-lg active:scale-95">
                        <span class="material-symbols-outlined text-[20px]">save</span>
                        Lưu thay đổi
                    </button>
                </div>
            </form>
            <div class="h-1 w-full bg-gradient-to-r from-primary-container to-secondary"></div>
        </div>
    </div>
</x-app-layout>
