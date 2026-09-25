{{--
    Phiếu lương từng người — 4 mẫu mockup theo loại nhân sự (epic-7/chi-tiet-bang-luong = GV Part-time,
    chi-tiet-bang-luong-gv-fulltime, chi-tiet-bang-luong-hoc-vu (+ roundcuoi 02/11), chi-tiet-bang-luong-hoc-thuat).
    Sale / nhân sự khác dùng mẫu Full-time. Công thức theo A6 (Q3).
--}}
<x-app-layout>
    @php
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $canEdit = ! $period->isLocked() && auth()->user()->can('payroll.edit');
        $isPT = $record->isPartTime();
        $isFT = $record->isFullTime();
        $key = $variant['key'];
        [$statusColor, $statusText] = match ($period->status) {
            'paid' => ['secondary', 'Đã trả'],
            'approved' => ['success', 'Đã chốt'],
            default => ['info', 'Đang tính'],
        };
        $lines = old('lines', $record->manualLines());
        if ($canEdit && $isPT && empty($lines)) {
            // Gợi ý các khoản phụ cấp của mockup GV Part-time (khoản 0đ không được lưu).
            $lines = [
                ['kind' => 'earning', 'label' => 'Hỗ trợ thỏa thuận', 'amount' => ''],
                ['kind' => 'earning', 'label' => 'Phụ cấp gửi xe', 'amount' => ''],
                ['kind' => 'earning', 'label' => 'Thưởng khác', 'amount' => ''],
            ];
        } elseif ($canEdit && $key === 'academic_lead' && empty($lines)) {
            $lines = [
                ['kind' => 'earning', 'label' => 'Lương giảng dạy', 'amount' => ''],
                ['kind' => 'earning', 'label' => 'Hỗ trợ', 'amount' => ''],
            ];
        }
        $kpiItems = collect(data_get($record->calculation_details, 'kpi.items', []));
        $kpiGroups = $kpiItems->groupBy('group');
        $renewalClasses = data_get($record->calculation_details, 'renewal.classes', []);
        [$kpiStateKey, $kpiStateLabel] = $record->kpi_state;
    @endphp

    @include('payroll.partials.payslip-print', ['record' => $record, 'period' => $period, 'variant' => $variant])

    <x-ui.page-header :title="$variant['title']">
        <x-slot:breadcrumbs>
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="inline-flex items-center gap-xs hover:text-primary">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_back</span>Quay lại danh sách
            </a>
            <span aria-hidden="true">/</span>
            <span>{{ $period->title }}</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="picture_as_pdf" onclick="window.print()">In phiếu lương / Xuất PDF</x-ui.button>
            @can('payroll.mark_paid')
                @if ($period->status === 'approved')
                    <form action="{{ route('payroll.periods.mark-paid', $period->id) }}" method="POST">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="payments">Đánh dấu đã trả</x-ui.button>
                    </form>
                @endif
            @endcan
            @can('payroll.approve')
                @unless ($period->isLocked())
                    <form action="{{ route('payroll.periods.approve', $period->id) }}" method="POST" data-confirm="Chốt toàn bộ bảng lương {{ $period->title }}? Dữ liệu kỳ sẽ bị khóa và nhân sự nhận thông báo.">
                        @csrf
                        <x-ui.button type="submit" icon="check_circle">Chốt bảng lương</x-ui.button>
                    </form>
                @endunless
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Người nhận + kỳ + loại + trạng thái --}}
    <div class="mb-lg flex flex-wrap items-center gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <x-ui.avatar :name="$record->user?->name ?? 'U'" />
        <div class="min-w-0">
            <h2 class="font-h2 text-h2 text-on-surface">Phiếu lương: {{ $record->user?->name ?? 'Nhân sự' }}</h2>
            <div class="mt-xs flex flex-wrap items-center gap-md font-body-small text-body-small text-on-surface-variant">
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">badge</span>{{ $record->user?->employee_code ?: 'Chưa có mã NV' }}</span>
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">calendar_month</span>Kỳ lương {{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }}</span>
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">work</span>{{ $variant['type'] }} · {{ $record->employee_type_label }}</span>
                <x-ui.badge :color="$statusColor">{{ $statusText }}</x-ui.badge>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    @if ($record->usesQ3Formula())
        <x-ui.alert type="info" class="mb-md">
            @if ($isPT)
                Công thức Part-time (BA chốt Q3): <strong>số buổi × đơn giá buổi riêng + KPI giữ HS + buổi có GVNN + phụ cấp tự do − khoản trừ</strong>. Không trừ BHXH / Công đoàn / TNCN.
            @else
                Công thức Full-time (BA chốt Q3): <strong>lương cơ bản + các khoản cộng − BHXH {{ $pct($settings['insurance_rate_percent']) }}% − Công đoàn {{ $pct($settings['union_rate_percent']) }}% (trên lương cơ bản) − thuế TNCN (nhập tay) − trừ vi phạm</strong>.
            @endif
        </x-ui.alert>
    @endif

    @if ($canEdit)
        <form id="payslip-form" action="{{ route('payroll.records.adjust', $record->id) }}" method="POST" x-data="{ lines: @js(array_values($lines)), newEarning: { label: '', amount: '' }, newDeduction: { label: '', amount: '' } }">
        @csrf
    @else
        <div x-data="{ lines: @js(array_values($lines)) }">
    @endif

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
        <div class="space-y-lg lg:col-span-8">
            @if ($isPT)
                {{-- ── GV Part-time: tổng thu nhập buổi dạy ── --}}
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                    <h3 class="mb-md flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">account_balance_wallet</span>Tổng thu nhập <span class="ml-auto font-mono text-primary">{{ $money($record->gross_income) }}đ</span></h3>
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                        <div class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Số buổi dạy</p><p class="font-h3 text-h3">{{ (int) $record->teaching_sessions }} buổi</p></div>
                        <div class="rounded-lg bg-surface-container-low p-md">
                            <p class="font-body-small text-body-small text-on-surface-variant">Đơn giá cơ bản</p>
                            <p class="font-h3 text-h3">{{ $currentRate ? $money($currentRate->hourly_rate).'đ' : '—' }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ $currentRate ? $currentRate->unit_label.' · hiệu lực '.$currentRate->effective_from->format('d/m/Y') : 'Chưa có đơn giá riêng' }}</p>
                        </div>
                        <div class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Thành tiền</p><p class="font-h3 text-h3 text-tertiary">{{ $money($record->teaching_salary) }}đ</p></div>
                    </div>
                </section>

                <div class="grid grid-cols-1 gap-lg md:grid-cols-2">
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                        <h3 class="font-h3 text-h3 text-on-surface">Bậc KPI giữ học sinh</h3>
                        @if ($canEdit)
                            <x-ui.select name="retention_tier" label="Đơn giá KPI (đ/hs/tháng)"
                                         :options="collect($settings['retention_tiers'])->mapWithKeys(fn ($t) => [(string) (int) $t => $money($t).'đ / HS'])->all()"
                                         :value="$record->retention_tier !== null ? (string) (int) $record->retention_tier : ''"
                                         placeholder="— Chưa chọn bậc —"
                                         :hint="'Gợi ý: '.collect($settings['retention_tiers'])->map(fn ($t) => $money($t))->implode(' / ').'đ/hs/tháng'" />
                        @else
                            <p class="font-body-medium text-body-medium">Đơn giá KPI: {{ $record->retention_tier !== null ? $money($record->retention_tier).'đ/hs/tháng' : 'chưa chọn bậc' }}</p>
                        @endif
                        <div>
                            <p class="font-body-small text-body-small text-on-surface-variant">Số học sinh duy trì</p>
                            <p class="font-h3 text-h3">{{ (int) $record->retention_students }} / {{ (int) $record->retention_base_students }} HS</p>
                            <p class="font-caption text-caption text-on-surface-variant">Nghỉ trong kỳ: {{ (int) data_get($record->calculation_details, 'retention.lost', 0) }} HS{{ $lostStudents->isNotEmpty() ? ' ('.$lostStudents->map(fn ($s) => $s->name)->implode(', ').')' : '' }}</p>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center gap-xs font-body-small text-body-small {{ $kpiStateKey === 'done' ? 'text-tertiary' : ($kpiStateKey === 'pending' ? 'text-error' : 'text-on-surface-variant') }}">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ $kpiStateKey === 'done' ? 'check' : 'info' }}</span>{{ $kpiStateLabel }}
                            </span>
                            @if ($canEdit)
                                <x-ui.button type="submit" size="sm" name="intent" value="kpi" icon="task_alt">Chốt KPI</x-ui.button>
                            @endif
                        </div>
                    </section>

                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                        <h3 class="font-h3 text-h3 text-on-surface">Phụ cấp mở rộng</h3>
                        @if ($canEdit)
                            <x-ui.input type="number" name="foreign_session_pay" label="Lớp GVNN đan xen (đ) — chờ BA chốt" min="0" step="1000"
                                        :value="(int) $record->foreign_session_pay"
                                        :hint="(int) $record->foreign_teacher_sessions_count.' buổi có GVNN cùng lớp trong kỳ. Kế toán nhập tổng tiền.'" />
                        @else
                            <p class="flex justify-between font-body-medium text-body-medium"><span>Lớp GVNN đan xen</span><span class="font-mono">{{ $money($record->foreign_session_pay) }}đ</span></p>
                        @endif
                        @include('payroll.partials.payslip-lines', ['kind' => 'earning', 'canEdit' => $canEdit, 'addLabel' => 'Thêm phụ cấp', 'placeholder' => 'VD: Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác'])
                    </section>
                </div>

                {{-- Chi tiết buổi dạy --}}
                <x-ui.data-table min-width="640px" x-data="{ all: false }">
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Chi tiết buổi dạy</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Ngày</th><th>Ca học</th><th>Lớp</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th><th>Ghi chú</th></tr></thead>
                        <tbody>
                            @forelse ($timesheets as $i => $ts)
                                @php $pay = $ts->sessionPay($record->user); @endphp
                                <tr @if ($i >= 10) x-show="all" x-cloak @endif>
                                    <td class="font-code text-code">{{ $ts->teaching_date->format('d/m/Y') }}</td>
                                    <td>{{ $ts->scheduled_time ? 'Ca '.$ts->scheduled_time : (($ts->checkin_time ?? '').($ts->checkout_time ? ' - '.$ts->checkout_time : '')) }}</td>
                                    <td>{{ $ts->classModel?->code ?? $ts->classModel?->name ?? '—' }}</td>
                                    <td class="text-right font-code text-code">{{ $money($pay['rate']) }}{{ $pay['unit'] === 'session' ? 'đ/buổi' : 'đ/giờ' }}</td>
                                    <td><x-ui.money :value="$pay['amount']" suffix="đ" /></td>
                                    <td class="font-body-small text-body-small text-on-surface-variant">{{ $ts->type === 'sub' ? 'Dạy thay' : ($ts->type === 'regular' ? '' : $ts->type_label) }}{{ $ts->source === 'manual' ? ' · Chấm tay' : '' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><x-ui.empty-state icon="schedule" title="Không có buổi dạy hợp lệ trong kỳ" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($timesheets->count() > 10)
                        <x-slot:footer>
                            <div class="p-sm text-center"><x-ui.button variant="ghost" size="sm" @click="all = ! all" x-text="all ? 'Thu gọn' : 'Xem toàn bộ ({{ $timesheets->count() }} buổi)'">Xem toàn bộ ({{ $timesheets->count() }} buổi)</x-ui.button></div>
                        </x-slot:footer>
                    @endif
                </x-ui.data-table>
            @else
                {{-- ── Full-time (GV Full-time / Học vụ / Học thuật / Sale / khác) ── --}}
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">payments</span>
                        {{ $key === 'academic_staff' ? 'Thu nhập cố định & KPI' : ($key === 'academic_lead' ? 'Thành phần Học thuật' : 'Thu nhập chính') }}
                    </h3>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                        <div class="rounded-lg bg-surface-container-low p-md">
                            <p class="font-body-small text-body-small text-on-surface-variant">{{ $key === 'academic_lead' ? 'Lương cứng (VNĐ)' : 'Lương cơ bản (VNĐ)' }}</p>
                            <p class="font-h3 text-h3 font-mono">{{ $money($record->base_salary) }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">Theo hồ sơ nhân sự · căn cứ tính BHXH, Công đoàn</p>
                        </div>
                        @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_MANUAL)
                            @if ($canEdit)
                                <x-ui.input type="number" name="kpi_manual_amount" label="Lương KPI (VNĐ) — nhập tự do" min="0" step="1000"
                                            :value="$record->kpi_manual_amount !== null ? (int) $record->kpi_manual_amount : null"
                                            :hint="$kpiStateLabel.' · Admin / Kế toán nhập (0đ vẫn tính là đã chốt).'" />
                            @else
                                <div class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Lương KPI (VNĐ)</p><p class="font-h3 text-h3 font-mono">{{ $money($record->kpi_bonus) }}</p></div>
                            @endif
                        @elseif ($record->kpi_source === \App\Models\PayrollRecord::KPI_ACADEMIC)
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Lương KPI (tự động theo 6 nhóm / 15 mục)</p>
                                <p class="font-h3 text-h3 font-mono">{{ $money($record->kpi_bonus) }}</p>
                                <p class="font-caption text-caption {{ $record->kpi_score !== null ? 'text-on-surface-variant' : 'text-error' }}">
                                    {{ $record->kpi_score !== null ? 'Quỹ '.$money(data_get($record->calculation_details, 'kpi.fund', $settings['academic_kpi_fund'])).'đ × '.$pct($record->kpi_score).'% điểm KPI · Đã chốt KPI tháng' : 'Chưa chốt đánh giá KPI tháng' }}
                                </p>
                            </div>
                        @endif
                    </div>

                    @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_ACADEMIC)
                        <details class="rounded-lg border border-surface-container" @if ($kpiItems->isEmpty()) open @endif>
                            <summary class="cursor-pointer px-md py-sm font-body-medium text-body-medium text-primary">Xem bảng kê chi tiết 6 nhóm / 15 mục</summary>
                            <div class="border-t border-surface-container p-md space-y-sm">
                                @forelse ($kpiGroups as $group => $items)
                                    <div>
                                        <p class="flex justify-between font-body-semibold text-body-semibold">
                                            <span>{{ $loop->iteration }}. {{ $group }} (trọng số {{ $pct($items->sum('weight')) }}%)</span>
                                            <span class="font-mono">{{ $money($items->sum('amount')) }}đ</span>
                                        </p>
                                        <ul class="ml-md list-disc font-body-small text-body-small text-on-surface-variant">
                                            @foreach ($items as $item)
                                                <li>{{ $item['code'] }} {{ $item['name'] }} — điểm {{ $pct($item['score']) }}% × trọng số {{ $pct($item['weight']) }}% = {{ $money($item['amount']) }}đ</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @empty
                                    <p class="font-body-small text-body-small text-on-surface-variant">Chưa có đánh giá KPI tháng {{ $period->month }}/{{ $period->year }} được chốt.</p>
                                @endforelse
                                <x-ui.button variant="ghost" size="sm" icon="open_in_new" :href="route('kpi.evaluate', ['userId' => $record->user_id, 'month' => $period->month, 'year' => $period->year])">Chốt / xem đánh giá KPI tháng</x-ui.button>
                            </div>
                        </details>
                    @endif
                </section>

                @if ($record->salary_role === 'sales' || (float) $record->commission_bonus != 0.0 || (float) $record->commission_deferred != 0.0 || (float) $record->renew_bonus != 0.0 || ! empty($renewalClasses))
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md" x-data="{ tiers: false }">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">trending_up</span>Hoa hồng tuyển sinh &amp; Thưởng tái tục (chỉ đọc)</h3>
                        <x-ui.alert type="info">Dữ liệu này được hệ thống tính toán tự động, không thể chỉnh sửa thủ công.</x-ui.alert>
                        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Hoa hồng tuyển sinh</p>
                                <p class="font-h3 text-h3 font-mono">{{ $money($record->commission_bonus) }} VNĐ</p>
                                <p class="font-caption text-caption text-on-surface-variant">
                                    {{ (int) $record->commission_closed_count }} HS chốt trong kỳ{{ $record->commission_percent !== null ? ' · bậc '.$pct($record->commission_percent).'%' : '' }}{{ (float) $record->commission_deferred > 0 ? ' · hoãn '.$money($record->commission_deferred).'đ' : '' }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Thưởng tái tục</p>
                                <p class="font-h3 text-h3 font-mono">{{ $money($record->renew_bonus) }} VNĐ</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ count($renewalClasses) }} lớp phụ trách</p>
                            </div>
                        </div>
                        @if ($commissionTiers->isNotEmpty())
                            <x-ui.button variant="ghost" size="sm" @click="tiers = ! tiers">Chi tiết bậc áp dụng <span class="material-symbols-outlined text-[16px]" aria-hidden="true" x-text="tiers ? 'expand_less' : 'expand_more'">expand_more</span></x-ui.button>
                            <table x-show="tiers" x-cloak class="w-full text-left font-body-small text-body-small">
                                <thead><tr class="text-on-surface-variant"><th class="py-xs">Bậc</th><th class="py-xs">Ngưỡng số HS chốt</th><th class="py-xs text-right">Tỷ lệ %</th></tr></thead>
                                <tbody>
                                    @foreach ($commissionTiers as $tier)
                                        @php $applied = $record->commission_percent !== null && abs((float) $tier->new_sale_percent - (float) $record->commission_percent) < 0.001; @endphp
                                        <tr class="border-t border-surface-container {{ $applied ? 'font-semibold text-tertiary' : '' }}">
                                            <td class="py-xs">{{ $tier->tier_name }} @if ($applied)<span class="material-symbols-outlined align-middle text-[16px]" aria-label="Bậc áp dụng">check_circle</span>@endif</td>
                                            <td class="py-xs">{{ $tier->student_range_label }}</td>
                                            <td class="py-xs text-right font-mono">{{ $pct($tier->new_sale_percent) }}%</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>
                @endif

                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">add_circle</span>Phụ cấp mở rộng</h3>
                    @include('payroll.partials.payslip-lines', ['kind' => 'earning', 'canEdit' => $canEdit, 'addLabel' => 'Thêm phụ cấp mới', 'placeholder' => 'VD: Phụ cấp trách nhiệm, Thưởng khác'])
                </section>
            @endif

            {{-- Trừ vi phạm & khoản trừ --}}
            <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                <div class="flex flex-wrap items-center justify-between gap-sm">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">gavel</span>{{ $isPT ? 'Các khoản trừ' : 'Trừ vi phạm' }}</h3>
                    @can('violation.create')
                        <x-ui.button variant="ghost" size="sm" icon="open_in_new" :href="route('penalties.index')">Theo danh mục vi phạm</x-ui.button>
                    @endcan
                </div>
                <table class="w-full text-left font-body-medium text-body-medium">
                    <thead><tr class="font-label text-label uppercase text-on-surface-variant"><th class="py-xs">Lý do / Hạng mục</th><th class="py-xs text-right">Số tiền (VNĐ)</th><th class="py-xs">Trạng thái</th></tr></thead>
                    <tbody>
                        @foreach ($penalties as $pen)
                            <tr class="border-t border-surface-container">
                                <td class="py-xs">{{ $pen->violation_type }} <span class="block font-caption text-caption text-on-surface-variant">Biên bản {{ $pen->code }} · ngày {{ $pen->violation_date->format('d/m/Y') }} · quá hạn nộp {{ $pen->due_date?->format('d/m/Y') ?? '—' }}</span></td>
                                <td class="py-xs text-right font-mono text-error">-{{ $money($pen->amount) }}</td>
                                <td class="py-xs"><span class="inline-flex items-center gap-xs text-tertiary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">check</span>Đã xác nhận</span></td>
                            </tr>
                        @endforeach
                        @foreach ($clawbacks as $adj)
                            <tr class="border-t border-surface-container">
                                <td class="py-xs">{{ $adj->reason }} <span class="block font-caption text-caption text-on-surface-variant">Thu hồi hoa hồng</span></td>
                                <td class="py-xs text-right font-mono text-error">{{ $money($adj->amount) }}</td>
                                <td class="py-xs">Tự động</td>
                            </tr>
                        @endforeach
                        @if ($penalties->isEmpty() && $clawbacks->isEmpty())
                            <tr><td colspan="3" class="py-sm text-on-surface-variant">Không có biên bản phạt quá hạn hay thu hồi hoa hồng trong kỳ.</td></tr>
                        @endif
                    </tbody>
                </table>
                <p class="font-caption text-caption text-on-surface-variant">Phạt từ biên bản vi phạm đã chốt, quá hạn nộp 2 ngày chưa nộp → trừ lương (xử lý ở màn Danh sách vi phạm). Khoản trừ khác (tạm ứng…) nhập bên dưới.</p>
                @include('payroll.partials.payslip-lines', ['kind' => 'deduction', 'canEdit' => $canEdit, 'addLabel' => 'Thêm khoản trừ', 'placeholder' => 'VD: Tạm ứng, Vi phạm nội quy'])
            </section>

            @if ($isFT || ! $record->usesQ3Formula())
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">remove_circle</span>Khấu trừ bắt buộc</h3>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        @if ($record->usesQ3Formula())
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">BHXH ({{ $pct(data_get($record->calculation_details, 'rates.insurance', $settings['insurance_rate_percent'])) }}% lương CB)</p>
                                <p class="font-h3 text-h3 font-mono text-error">-{{ $money($record->insurance_deduction) }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">Tự động tính, chỉ đọc</p>
                            </div>
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Phí Công đoàn ({{ $pct(data_get($record->calculation_details, 'rates.union', $settings['union_rate_percent'])) }}% lương CB)</p>
                                <p class="font-h3 text-h3 font-mono text-error">-{{ $money($record->union_deduction) }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">Tự động tính, chỉ đọc</p>
                            </div>
                        @endif
                        @if ($canEdit)
                            <x-ui.input type="number" name="tax_deduction" label="Thuế TNCN (VNĐ)" min="0" step="1000" :value="(int) $record->tax_deduction" hint="Admin / Kế toán nhập thủ công." />
                        @else
                            <div class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Thuế TNCN (VNĐ)</p><p class="font-h3 text-h3 font-mono text-error">-{{ $money($record->tax_deduction) }}</p></div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Căn cứ chi tiết --}}
            @if (! $isPT && $timesheets->isNotEmpty())
                <x-ui.data-table min-width="480px">
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Buổi dạy trong kỳ ({{ $timesheets->count() }}) — đối soát, đã gồm trong lương cơ bản</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Ngày</th><th>Ca học</th><th>Lớp</th><th class="text-right">Giờ</th></tr></thead>
                        <tbody>
                            @foreach ($timesheets as $ts)
                                <tr>
                                    <td class="font-code text-code">{{ $ts->teaching_date->format('d/m/Y') }}</td>
                                    <td>{{ $ts->scheduled_time ? 'Ca '.$ts->scheduled_time : '—' }}</td>
                                    <td>{{ $ts->classModel?->name ?? '—' }} <span class="font-caption text-caption text-on-surface-variant">{{ $ts->type_label }}</span></td>
                                    <td class="text-right font-code text-code">{{ (float) $ts->hours }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if (! empty($renewalClasses))
                <x-ui.data-table>
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Thưởng tái tục theo lớp</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Lớp</th><th class="text-right">HS nghỉ</th><th class="text-right">%</th><th class="text-right">Doanh thu lớp</th><th class="text-right">Thưởng</th></tr></thead>
                        <tbody>
                            @foreach ($renewalClasses as $row)
                                <tr>
                                    <td>{{ $row['class'] }} <span class="block font-caption text-caption text-on-surface-variant">{{ $row['base'] }} HS đầu kỳ</span></td>
                                    <td class="text-right font-code text-code">{{ $row['quits'] }}</td>
                                    <td class="text-right font-code text-code">{{ $pct($row['percent']) }}%@if ($row['pending']) <span class="block font-caption text-caption text-amber-700">chờ BA</span>@endif</td>
                                    <td><x-ui.money :value="$row['revenue']" suffix="đ" /></td>
                                    <td><x-ui.money :value="$row['amount']" suffix="đ" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if ($record->usesQ3Formula() && ($paidCommission->isNotEmpty() || $deferredCommission->isNotEmpty() || $record->salary_role === 'sales'))
                <x-ui.data-table>
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Hoa hồng tuyển sinh — từng khoản</h3>
                        <p class="font-caption text-caption text-on-surface-variant">Trả khi đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc; chưa đủ thì hoãn sang kỳ sau.</p>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Học viên / phiếu</th><th class="text-right">Thực thu</th><th class="text-right">%</th><th class="text-right">Hoa hồng</th></tr></thead>
                        <tbody>
                            @foreach ($paidCommission as $item)
                                <tr>
                                    <td>{{ $item->student?->name ?? '—' }} <span class="block font-caption text-caption text-on-surface-variant">{{ $item->receipt?->receipt_number }} · phát sinh {{ $item->earned_period_start->format('m/Y') }} · Trả trong kỳ</span></td>
                                    <td><x-ui.money :value="$item->base_amount" suffix="đ" /></td>
                                    <td class="text-right font-code text-code">{{ $pct($item->percent) }}%</td>
                                    <td><x-ui.money :value="$item->amount" suffix="đ" /></td>
                                </tr>
                            @endforeach
                            @foreach ($deferredCommission as $item)
                                <tr>
                                    <td>{{ $item->student?->name ?? '—' }} <span class="block font-caption text-caption text-amber-700">{{ $item->receipt?->receipt_number }} · {{ $item->deferred_reason ?? 'Hoãn sang kỳ sau' }}</span></td>
                                    <td><x-ui.money :value="$item->base_amount" suffix="đ" /></td>
                                    <td class="text-right font-code text-code">{{ $pct($item->percent) }}%</td>
                                    <td class="text-right font-code text-code text-on-surface-variant">Hoãn {{ $money($item->amount) }}đ</td>
                                </tr>
                            @endforeach
                            @if ($paidCommission->isEmpty() && $deferredCommission->isEmpty())
                                <tr><td colspan="4"><x-ui.empty-state icon="receipt_long" title="Không có hoa hồng trả / hoãn trong kỳ" /></td></tr>
                            @endif
                        </tbody>
                    </table>
                </x-ui.data-table>
            @elseif (! $record->usesQ3Formula())
                <x-ui.data-table>
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Phiếu thu tính hoa hồng ({{ $commissionReceipts->count() }})</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Ngày duyệt</th><th>Học viên</th><th class="text-right">Thực thu</th></tr></thead>
                        <tbody>
                            @forelse ($commissionReceipts as $receipt)
                                <tr><td class="font-code text-code">{{ $receipt->approved_at?->format('d/m/Y') }}</td><td>{{ $receipt->student?->name ?? '—' }}</td><td><x-ui.money :value="$receipt->amount" suffix="đ" /></td></tr>
                            @empty
                                <tr><td colspan="3"><x-ui.empty-state icon="receipt_long" title="Không có phiếu thu khách mới trong kỳ" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif
        </div>

        {{-- Tổng kết --}}
        <aside class="space-y-md lg:sticky lg:top-20 lg:col-span-4">
            <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-sm">
                <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">receipt_long</span>Tổng kết thực nhận</h3>
                <div class="space-y-xs font-body-small text-body-small">
                    @foreach ($record->earningLines() as $line)
                        <div class="flex justify-between gap-sm">
                            <span>{{ $line['label'] }}@if ($line['hint'])<span class="block font-caption text-caption text-on-surface-variant">{{ $line['hint'] }}</span>@endif</span>
                            <span class="whitespace-nowrap font-mono">{{ $money($line['amount']) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between border-t border-surface-container pt-sm font-body-semibold text-body-semibold"><span>Tổng thu nhập</span><span class="font-mono text-tertiary">{{ $money($record->gross_income) }}</span></div>
                <div class="space-y-xs font-body-small text-body-small">
                    @foreach ($record->deductionLines() as $line)
                        <div class="flex justify-between gap-sm">
                            <span>{{ $line['label'] }}@if ($line['hint'] ?? null)<span class="block font-caption text-caption text-on-surface-variant">{{ $line['hint'] }}</span>@endif</span>
                            <span class="whitespace-nowrap font-mono text-error">-{{ $money($line['amount']) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between border-t border-surface-container pt-sm font-body-semibold text-body-semibold"><span>Tổng khoản trừ</span><span class="font-mono text-error">-{{ $money($record->total_deductions) }}</span></div>
                <div class="flex items-end justify-between rounded-lg bg-primary-fixed/40 p-md">
                    <span class="font-body-semibold text-body-semibold">Thực nhận</span>
                    <span class="font-h2 text-h2 font-mono text-primary">{{ $money($record->net_salary) }} đ</span>
                </div>

                @if ($canEdit)
                    <x-ui.textarea name="adjustment_notes" label="Ghi chú" rows="2" :value="$record->adjustment_notes" hint="Các khoản nhập tay được giữ khi bấm Đồng bộ & Tính lại." />
                    <x-ui.button type="submit" icon="save" class="w-full">Lưu điều chỉnh</x-ui.button>
                    <x-ui.alert type="info">Bảng lương này đang ở trạng thái tính toán. Chốt bảng lương để khóa dữ liệu và gửi thông báo cho nhân sự.</x-ui.alert>
                @else
                    <p class="font-body-small text-body-small text-on-surface-variant">
                        {{ $record->adjustment_notes ?: 'Không có ghi chú điều chỉnh.' }}
                        @if ($period->isLocked())
                            <span class="block font-caption text-caption">Kỳ lương đã {{ mb_strtolower($period->status_label) }} — phiếu lương đã khoá.</span>
                        @endif
                    </p>
                @endif
                <p class="font-caption text-caption text-on-surface-variant">Diễn giải tự động: {{ $record->notes }}</p>
            </section>
        </aside>
    </div>

    @if ($canEdit)
        </form>
    @else
        </div>
    @endif
</x-app-layout>
