{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/luong-cua-toi-teacher-portal — chỉ hiện kỳ lương đã duyệt / đã chi trả (A6) --}}
<x-app-layout>
    @php
        $n = fn ($v) => number_format((float) $v);
        $incomeTotal = $record ? $record->gross_income : 0;
        $deductionTotal = $record ? $record->total_deductions : 0;
        $netSalary = $record ? $record->net_salary : 0;
        $change = $record && $previous && (float) $previous->gross_income > 0
            ? round(($incomeTotal - (float) $previous->gross_income) / (float) $previous->gross_income * 100)
            : null;
        $quantity = function (array $line) use ($record) {
            return match ($line['key']) {
                'teaching_salary' => $record->usesQ3Formula() ? (int) $record->teaching_sessions.' buổi' : rtrim(rtrim(number_format((float) $record->actual_hours, 2, '.', ''), '0'), '.').' giờ',
                'kpi_bonus' => $record->kpi_source === 'retention'
                    ? (int) $record->retention_students.'/'.(int) $record->retention_base_students.' HS'
                    : ($record->kpi_score !== null ? rtrim(rtrim(number_format((float) $record->kpi_score, 2), '0'), '.').'%' : '-'),
                'foreign_session_pay' => (int) $record->foreign_teacher_sessions_count.' buổi',
                'commission_bonus' => (int) $record->commission_closed_count.' HS chốt',
                default => '-',
            };
        };
        $classes = $timesheets->map(fn ($ts) => $ts->classModel?->code ?? $ts->classModel?->name)->filter()->unique()->values();
    @endphp

    @if ($record)
        @include('payroll.partials.payslip-print', ['record' => $record, 'period' => $record->period, 'variant' => $variant])
    @endif

    <x-ui.page-header title="Lương của tôi" :description="$record ? 'Kỳ lương hiện tại: '.$record->period->status_label.' — '.$record->period->title : 'Tra cứu phiếu lương cá nhân đã được duyệt.'">
        <x-slot:actions>
            @if ($records->isNotEmpty())
                <form method="GET" action="{{ route('portal.my-salary') }}">
                    <x-ui.select name="period_id" onchange="this.form.submit()" aria-label="Chọn kỳ lương">
                        @foreach ($records as $option)
                            <option value="{{ $option->payroll_period_id }}" @selected($record && $option->payroll_period_id === $record->payroll_period_id)>Tháng {{ str_pad($option->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $option->period->year }}</option>
                        @endforeach
                    </x-ui.select>
                </form>
            @endif
            @if ($record)
                <x-ui.button variant="secondary" icon="download" onclick="window.print()">Tải phiếu lương</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $record)
        <x-ui.empty-state icon="wallet" title="Chưa có kỳ lương đã duyệt" description="Phiếu lương chỉ hiển thị sau khi Giám đốc duyệt bảng lương của kỳ." />
    @else
        <div class="mb-lg grid grid-cols-1 gap-md md:grid-cols-3">
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                <div class="flex items-start justify-between"><h3 class="font-body-semibold text-body-semibold text-on-surface-variant">Tổng thu nhập</h3><span class="material-symbols-outlined text-primary-container" aria-hidden="true">payments</span></div>
                <p class="mt-sm font-h2 text-h2 font-mono text-on-surface">{{ $n($incomeTotal) }} ₫</p>
                @if ($change !== null)
                    <p class="mt-xs inline-flex items-center gap-xs font-body-small text-body-small {{ $change >= 0 ? 'text-tertiary' : 'text-error' }}">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ $change >= 0 ? 'trending_up' : 'trending_down' }}</span>{{ $change >= 0 ? '+' : '' }}{{ $change }}% so với tháng trước
                    </p>
                @else
                    <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Chưa có kỳ trước để so sánh</p>
                @endif
            </div>
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                <div class="flex items-start justify-between"><h3 class="font-body-semibold text-body-semibold text-on-surface-variant">Tổng khoản trừ</h3><span class="material-symbols-outlined text-error" aria-hidden="true">money_off</span></div>
                <p class="mt-sm font-h2 text-h2 font-mono text-error">{{ $n($deductionTotal) }} ₫</p>
                <p class="mt-xs inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span>Bao gồm phạt và các khoản khác</p>
            </div>
            <div class="rounded-xl bg-primary-container p-lg text-white">
                <div class="flex items-start justify-between"><h3 class="font-body-semibold text-body-semibold">Thực nhận</h3><span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span></div>
                <p class="mt-sm font-h2 text-h2 font-mono">{{ $n($netSalary) }} ₫</p>
                <p class="mt-xs font-body-small text-body-small text-white/80">{{ $record->period->status === 'paid' ? 'Đã chi trả qua tài khoản ngân hàng' : 'Đã duyệt — chờ chi trả qua tài khoản ngân hàng' }}</p>
            </div>
        </div>

        <div class="mb-lg grid grid-cols-1 gap-lg lg:grid-cols-2">
            <x-ui.data-table>
                <x-slot:header><h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">account_balance</span>Chi tiết thu nhập</h3></x-slot:header>
                <table>
                    <thead><tr><th>Hạng mục</th><th class="text-right">Số lượng</th><th class="text-right">Thành tiền (₫)</th></tr></thead>
                    <tbody>
                        @foreach ($record->earningLines() as $line)
                            <tr>
                                <td>{{ $line['label'] }}@if ($line['hint'])<span class="block font-caption text-caption text-on-surface-variant">{{ $line['hint'] }}</span>@endif</td>
                                <td class="text-right font-mono">{{ $quantity($line) }}</td>
                                <td class="text-right font-mono">{{ $n($line['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold"><td colspan="2">Tổng thu nhập</td><td class="text-right font-mono">{{ $n($incomeTotal) }}</td></tr>
                    </tbody>
                </table>
            </x-ui.data-table>

            <x-ui.data-table>
                <x-slot:header><h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">money_off</span>Các khoản trừ</h3></x-slot:header>
                <table>
                    <thead><tr><th>Lý do</th><th>Trạng thái</th><th class="text-right">Số tiền (₫)</th></tr></thead>
                    <tbody>
                        @foreach ($penalties as $pen)
                            <tr>
                                <td>{{ $pen->violation_type }}<span class="block font-caption text-caption text-on-surface-variant">Ngày {{ $pen->violation_date->format('d/m') }}{{ $pen->classModel ? ' - Lớp '.($pen->classModel->code ?? $pen->classModel->name) : '' }} · Biên bản {{ $pen->code }}</span></td>
                                <td><x-ui.badge color="error">Trừ lương (quá hạn nộp)</x-ui.badge></td>
                                <td class="text-right font-mono text-error">-{{ $n($pen->amount) }}</td>
                            </tr>
                        @endforeach
                        @foreach ($record->deductionLines() as $line)
                            @continue($line['key'] === 'penalty_deduction' && $penalties->isNotEmpty())
                            @continue((float) $line['amount'] == 0.0 && $line['key'] !== 'penalty_deduction')
                            <tr>
                                <td>{{ $line['label'] }}@if ($line['hint'] ?? null)<span class="block font-caption text-caption text-on-surface-variant">{{ $line['hint'] }}</span>@endif</td>
                                <td><x-ui.badge color="neutral">{{ str_starts_with($line['key'], 'manual_') ? 'Trừ khác' : 'Tự động' }}</x-ui.badge></td>
                                <td class="text-right font-mono text-error">-{{ $n($line['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold"><td colspan="2">Tổng khoản trừ</td><td class="text-right font-mono text-error">-{{ $n($deductionTotal) }}</td></tr>
                    </tbody>
                </table>
                @if ($record->adjustment_notes)
                    <x-slot:footer><p class="p-sm font-body-small text-body-small italic text-on-surface-variant">Ghi chú kế toán: {{ $record->adjustment_notes }}</p></x-slot:footer>
                @endif
            </x-ui.data-table>
        </div>

        <x-ui.data-table min-width="640px" x-data="{ all: false, cls: '' }">
            <x-slot:header>
                <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">history_edu</span>Chi tiết buổi dạy ({{ $timesheets->count() }})</h3>
                @if ($classes->count() > 1)
                    <label class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">filter_list</span>Lọc
                        <x-ui.select x-model="cls" placeholder="Tất cả lớp">
                            @foreach ($classes as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                        </x-ui.select>
                    </label>
                @endif
            </x-slot:header>
            <table>
                <thead><tr><th>Ngày dạy</th><th>Thời gian</th><th>Lớp học</th><th class="text-right">Đơn giá (₫)</th><th class="text-right">Thành tiền (₫)</th></tr></thead>
                <tbody>
                    @forelse ($timesheets as $i => $ts)
                        @php $pay = $ts->sessionPay($user); $code = $ts->classModel?->code ?? $ts->classModel?->name; @endphp
                        <tr x-show="(all || {{ $i }} < 10) && (! cls || cls === @js($code))" @if ($i >= 10) x-cloak @endif>
                            <td class="font-code text-code">{{ $ts->teaching_date->format('d/m/Y') }}</td>
                            <td class="font-mono">{{ $ts->scheduled_time ? str_replace('-', ' - ', $ts->scheduled_time) : trim(($ts->checkin_time ?? '').($ts->checkout_time ? ' - '.$ts->checkout_time : '')) }}</td>
                            <td>{{ $code ?? '—' }} @if ($ts->type === 'sub')<x-ui.badge color="info">Cover</x-ui.badge>@elseif ($ts->type !== 'regular')<span class="font-caption text-caption text-on-surface-variant">{{ $ts->type_label }}</span>@endif</td>
                            <td class="text-right font-mono">{{ $n($pay['rate']) }}<span class="font-caption text-caption text-on-surface-variant">/{{ $pay['unit'] === 'session' ? 'buổi' : 'giờ' }}</span></td>
                            <td class="text-right font-mono">
                                @if ($record->isFullTime())
                                    <span class="font-caption text-caption text-on-surface-variant">Trong lương cơ bản</span>
                                @else
                                    {{ $n($pay['amount']) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="schedule" title="Không có buổi dạy hợp lệ trong kỳ" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($timesheets->count() > 10)
                <x-slot:footer>
                    <div class="p-sm text-center"><x-ui.button variant="ghost" size="sm" x-on:click="all = ! all"><span x-text="all ? 'Thu gọn' : 'Xem thêm các buổi khác'">Xem thêm các buổi khác</span></x-ui.button></div>
                </x-slot:footer>
            @endif
        </x-ui.data-table>

        <p class="mt-md font-body-small text-body-small text-on-surface-variant">
            Mọi thắc mắc về số buổi dạy, KPI hoặc khoản trừ, vui lòng phản hồi phòng Kế toán trước ngày 03 hằng tháng. Trạng thái kỳ: <x-ui.badge :color="$record->period->status === 'paid' ? 'secondary' : 'success'">{{ $record->period->status_label }}</x-ui.badge>
        </p>
    @endif
</x-app-layout>
