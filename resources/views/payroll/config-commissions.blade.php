{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/cau-hinh-moc-hoa-hong-thuong-tai-tuc --}}
<x-app-layout>
    @include('partials.data-confirm')
    @php
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',').'%';
        $renewalRows = old('renewal', collect($settings['renewal_table'])->map(fn ($row, $quits) => ['quits' => $quits, 'percent' => $row['percent'], 'pending' => $row['pending']])->values()->all());
    @endphp

    <x-ui.page-header title="Cấu hình mốc hoa hồng & thưởng tái tục" description="Quản lý và thiết lập các mốc chính sách hoa hồng tuyển sinh và thưởng tái tục cho bảng lương.">
        <x-slot:actions>
            <label class="flex items-center gap-sm">
                <span class="sr-only">Loại cấu hình</span>
                <select onchange="window.location.href = this.value"
                        class="rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-medium text-body-medium text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                    <option value="{{ route('payroll.config.commission-tiers') }}" @selected($tab === 'commission')>Hoa hồng tuyển sinh</option>
                    <option value="{{ route('payroll.config.commission-tiers', ['tab' => 'renewal']) }}" @selected($tab === 'renewal')>Thưởng tái tục</option>
                </select>
            </label>
            @if ($tab === 'commission')
                <x-ui.button icon="add" @click="document.getElementById('new-tier-form')?.scrollIntoView({ behavior: 'smooth' }); document.getElementById('f_min_students')?.focus()">Thêm mốc mới</x-ui.button>
            @endif
            <x-ui.button variant="secondary" icon="price_change" :href="route('payroll.config.teacher-rates')">Đơn giá GV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-lg" x-data="{ editing: null }">
        @if ($errors->any())
            <x-ui.alert type="error">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.alert type="info">
            Thay đổi cấu hình sẽ được áp dụng cho các kỳ tính lương tiếp theo kể từ ngày hiệu lực. Hệ thống sẽ giữ nguyên lịch sử cấu hình cũ cho các kỳ đã quyết toán.
        </x-ui.alert>

        @if ($tab === 'commission')
            <x-ui.alert type="warning" title="Hoa hồng theo bậc số HS chốt × tiền thực thu — gate kép, hoãn không mất">
                Hoa hồng = % theo bậc (bậc chọn theo <strong>số HS chốt của sale trong kỳ</strong>) × tiền thực thu của khách mới (gồm giáo trình, đồ dùng); hệ thống tự tính.
                Từng khách chỉ được trả khi <strong>đủ {{ config('payroll.commission.gate_days') }} ngày từ ngày chốt</strong> và
                <strong>đủ {{ config('payroll.commission.gate_milestones') }}/3 mốc chăm sóc tháng đầu</strong>; chưa đủ thì hoãn sang kỳ sau (giữ % kỳ phát sinh).
                Sửa một mốc tạo <strong>phiên bản mới</strong>; kỳ lương dùng mốc hiệu lực tại ngày cuối kỳ. Ngưỡng mặc định 0–5 / 6–10 / từ 11 chờ BA xác nhận.
            </x-ui.alert>

            <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
                <div class="space-y-lg lg:col-span-8">
                    <x-ui.data-table min-width="640px">
                        <x-slot:header>
                            <h3 class="font-h3 text-h3 text-on-surface">Mốc đang hiệu lực ngày {{ $asOf->format('d/m/Y') }}</h3>
                            <form method="GET" class="flex items-center gap-sm">
                                <x-ui.date name="as_of" inline-label="Xem tại ngày:" :value="$asOf->toDateString()" />
                                <x-ui.button type="submit" variant="secondary" size="sm">Xem</x-ui.button>
                            </form>
                        </x-slot:header>
                        <table>
                            <thead>
                                <tr>
                                    <th class="text-right">Ngưỡng từ (HV)</th>
                                    <th class="text-right">Ngưỡng đến (HV)</th>
                                    <th class="text-center">Tỷ lệ (%)</th>
                                    <th>Ngày hiệu lực từ</th>
                                    <th class="text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tiers as $tier)
                                    <tr>
                                        <td class="text-right font-code text-code">
                                            {{ (int) $tier->min_students }}
                                            <span class="block font-caption text-caption text-on-surface-variant">{{ $tier->tier_name }}</span>
                                        </td>
                                        <td class="text-right font-code text-code">{{ $tier->max_students !== null ? (int) $tier->max_students : 'Không giới hạn' }}</td>
                                        <td class="text-center"><x-ui.badge color="success">{{ $pct($tier->new_sale_percent) }}</x-ui.badge></td>
                                        <td class="font-code text-code">{{ $tier->effective_from?->format('d/m/Y') ?? 'Từ đầu' }}</td>
                                        <td class="text-right">
                                            @if ($tier->effective_to === null)
                                                <div class="flex justify-end gap-xs">
                                                    <x-ui.button variant="ghost" size="sm" icon="edit" aria-label="Sửa (tạo phiên bản mới)"
                                                                 @click="editing = {{ Js::from($tier->only(['id', 'tier_name', 'min_students', 'max_students', 'new_sale_percent'])) }}; $dispatch('open-modal', 'edit-tier')" />
                                                    <form action="{{ route('payroll.config.commission-tiers.destroy', $tier) }}" method="POST" data-confirm="Ngừng áp dụng mốc {{ $tier->tier_name }} từ hôm nay?">
                                                        @csrf
                                                        @method('DELETE')
                                                        <x-ui.button type="submit" variant="danger-text" size="sm" icon="block" aria-label="Ngừng áp dụng" />
                                                    </form>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5"><x-ui.empty-state icon="percent" title="Chưa có mốc hoa hồng hiệu lực tại ngày này" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.data-table>

                    <x-ui.data-table min-width="760px">
                        <x-slot:header>
                            <h3 class="font-h3 text-h3 text-on-surface">Lịch sử các phiên bản</h3>
                        </x-slot:header>
                        <table>
                            <thead>
                                <tr>
                                    <th>Bậc</th>
                                    <th class="text-right">Số HS chốt trong kỳ</th>
                                    <th class="text-center">Tỷ lệ (%)</th>
                                    <th>Hiệu lực</th>
                                    <th>Người tạo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $version)
                                    <tr>
                                        <td>
                                            <span class="font-semibold">{{ $version->tier_name }}</span>
                                            @if ($version->replaces)
                                                <span class="block font-caption text-caption text-on-surface-variant">thay cho “{{ $version->replaces->tier_name }}”</span>
                                            @endif
                                        </td>
                                        <td class="text-right font-code text-code">{{ $version->student_range_label }}</td>
                                        <td class="text-center font-code text-code">{{ $pct($version->new_sale_percent) }}</td>
                                        <td class="whitespace-nowrap font-code text-code">
                                            {{ $version->effective_from?->format('d/m/Y') ?? 'Từ đầu' }} → {{ $version->effective_to?->format('d/m/Y') ?? 'nay' }}
                                            @if ($version->effective_to === null)
                                                <x-ui.badge color="success">Đang áp dụng</x-ui.badge>
                                            @endif
                                        </td>
                                        <td>{{ $version->creator?->name ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5"><x-ui.empty-state icon="history" title="Chưa có lịch sử" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <x-slot:footer><x-ui.pagination :paginator="$history" /></x-slot:footer>
                    </x-ui.data-table>
                </div>

                <div class="lg:col-span-4">
                    <form id="new-tier-form" action="{{ route('payroll.config.commission-tiers.store') }}" method="POST"
                          class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                        @csrf
                        <h3 class="font-h3 text-h3 text-on-surface">Thêm mốc cấu hình mới</h3>
                        <div class="grid grid-cols-2 gap-md">
                            <x-ui.input type="number" name="min_students" label="Từ (số học viên)" required min="0" step="1" placeholder="VD: 11" />
                            <x-ui.input type="number" name="max_students" label="Đến (số học viên)" min="0" step="1" placeholder="Để trống = Max" />
                        </div>
                        <x-ui.field label="Tỷ lệ (%)" name="new_sale_percent" for="f_new_sale_percent" required>
                            <div class="relative">
                                <input type="number" id="f_new_sale_percent" name="new_sale_percent" required min="0" max="100" step="0.1" placeholder="0.0" value="{{ old('new_sale_percent') }}"
                                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm pr-xl text-right font-mono text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-body-medium text-on-surface-variant">%</span>
                            </div>
                        </x-ui.field>
                        <x-ui.date name="effective_from" label="Hiệu lực từ ngày" required :value="old('effective_from', now()->toDateString())" />
                        <x-ui.input name="tier_name" label="Tên bậc (tuỳ chọn)" placeholder="Bỏ trống = tự đặt theo ngưỡng" />
                        <x-ui.button type="submit" icon="save" class="w-full">Lưu cấu hình</x-ui.button>
                        <p class="font-caption text-caption text-on-surface-variant">Không có hoa hồng tái tục cho sale (A6). Thưởng tái tục của GV phụ trách lớp ở tab "Thưởng tái tục".</p>
                    </form>
                </div>
            </div>

            <x-ui.modal name="edit-tier" title="Tạo phiên bản mới của mốc hoa hồng">
                <form id="edit-tier-form" method="POST" :action="editing ? '{{ url('payroll/config/commission-tiers') }}/' + editing.id : '#'" class="space-y-md">
                    @csrf
                    @method('PUT')
                    <template x-if="editing">
                        <div class="space-y-md">
                            <div class="grid grid-cols-2 gap-md">
                                <x-ui.field label="Từ (số học viên)" required>
                                    <input type="number" name="min_students" x-model="editing.min_students" min="0" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code">
                                </x-ui.field>
                                <x-ui.field label="Đến (số học viên)">
                                    <input type="number" name="max_students" x-model="editing.max_students" min="0" placeholder="Để trống = Max" class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code">
                                </x-ui.field>
                                <x-ui.field label="Tỷ lệ (%)" required>
                                    <input type="number" name="new_sale_percent" x-model="editing.new_sale_percent" min="0" max="100" step="0.1" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code">
                                </x-ui.field>
                                <x-ui.field label="Tên bậc">
                                    <input type="text" name="tier_name" x-model="editing.tier_name" class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base">
                                </x-ui.field>
                            </div>
                            <x-ui.date name="effective_from" label="Phiên bản mới hiệu lực từ" required :value="now()->addDay()->toDateString()"
                                       hint="Phiên bản cũ tự đóng vào ngày trước đó." />
                        </div>
                    </template>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'edit-tier')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="edit-tier-form" icon="save">Lưu phiên bản mới</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @else
            {{-- Thưởng tái tục (A6): % doanh thu lớp theo số HS nghỉ trong kỳ, cho GV Full-time phụ trách lớp --}}
            <form action="{{ route('payroll.config.renewal.store') }}" method="POST"
                  class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm"
                  x-data="{ rows: @js(array_values($renewalRows)) }">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container p-md">
                    <div>
                        <h3 class="font-h3 text-h3 text-on-surface">Thưởng tái tục — % doanh thu lớp theo số HS nghỉ trong kỳ</h3>
                        <p class="font-body-small text-body-small text-on-surface-variant">BA đã chốt: giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%. Các mốc khác gắn <strong>chờ BA</strong> tới khi có bảng đầy đủ.</p>
                    </div>
                    <x-ui.button variant="secondary" size="sm" icon="add" @click="rows.push({ quits: rows.length, percent: 0, pending: true })">Thêm mốc mới</x-ui.button>
                </div>
                <div class="custom-scrollbar overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-surface-container-low">
                            <tr class="font-label text-label uppercase text-on-surface-variant">
                                <th class="px-md py-sm">Số HS nghỉ trong lớp</th>
                                <th class="px-md py-sm">Tỷ lệ (% doanh thu lớp)</th>
                                <th class="px-md py-sm">Chờ BA</th>
                                <th class="px-md py-sm"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-t border-surface-container">
                                    <td class="px-md py-sm"><input type="number" min="0" :name="`renewal[${i}][quits]`" x-model="row.quits" aria-label="Số HS nghỉ" class="w-28 rounded-lg border border-outline-variant px-sm py-xs font-code text-code"></td>
                                    <td class="px-md py-sm">
                                        <span class="relative inline-block">
                                            <input type="number" min="0" max="100" step="0.05" :name="`renewal[${i}][percent]`" x-model="row.percent" aria-label="Tỷ lệ %" class="w-32 rounded-lg border border-outline-variant px-sm py-xs pr-lg text-right font-code text-code">
                                            <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant">%</span>
                                        </span>
                                    </td>
                                    <td class="px-md py-sm">
                                        <input type="hidden" :name="`renewal[${i}][pending]`" :value="row.pending ? 1 : 0">
                                        <label class="inline-flex items-center gap-xs"><input type="checkbox" x-model="row.pending" class="rounded border-outline-variant text-primary-container"><span x-show="row.pending" class="font-caption text-caption font-semibold text-amber-700">chờ BA</span></label>
                                    </td>
                                    <td class="px-md py-sm text-right"><x-ui.button variant="danger-text" size="sm" icon="delete" aria-label="Xoá mốc" @click="rows.splice(i, 1)" /></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-md border-t border-surface-container bg-surface-container-low/40 p-md">
                    <label class="flex items-center gap-sm font-body-medium text-body-medium">
                        Nghỉ nhiều hơn các mốc trên:
                        <input type="number" name="renewal_beyond_percent" min="0" max="100" step="0.05" value="{{ old('renewal_beyond_percent', rtrim(rtrim(number_format((float) $settings['renewal_beyond_percent'], 2, '.', ''), '0'), '.')) }}"
                               class="w-28 rounded-lg border border-outline-variant px-sm py-xs text-right font-code text-code"> % <span class="font-caption text-caption text-amber-700">(chờ BA)</span>
                    </label>
                    <x-ui.button type="submit" icon="save">Lưu cấu hình</x-ui.button>
                </div>
            </form>
            <p class="font-body-small text-body-small text-on-surface-variant">
                Thưởng tái tục = Σ theo lớp GV Full-time là GV chính: % theo số HS nghỉ trong kỳ × doanh thu lớp (phiếu thu đã duyệt trong kỳ, trừ tiền nhận chuyển nhượng).
                Bảng áp dụng cho lần <strong>tính / tính lại</strong> kỳ lương tiếp theo; kỳ đã duyệt không đổi.
            </p>
        @endif
    </div>
</x-app-layout>
