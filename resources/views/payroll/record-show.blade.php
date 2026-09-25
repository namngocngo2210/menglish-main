<x-app-layout>
    <x-ui.page-header :title="'Phiếu lương: '.($record->user?->name ?? 'Nhân sự')"
                      :description="$period->title.' · '.$period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y')">
        <x-slot:breadcrumbs>
            <a href="{{ route('payroll.periods.index') }}" class="hover:text-primary">Bảng lương</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="hover:text-primary">{{ $period->title }}</a>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @if ($record->usesQ3Formula())
                <span class="inline-block rounded-full border border-outline-variant px-sm py-xs text-[11px] font-bold text-on-surface">{{ $record->employee_type_label }} · {{ $record->salary_role_label }}</span>
            @endif
            <span class="inline-block rounded-full border px-sm py-xs text-[11px] font-bold {{ $period->status_badge }}">{{ $period->status_label }}</span>
            <x-ui.button variant="secondary" icon="print" onclick="window.print()">In phiếu</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');
        $canEdit = ! $period->isLocked() && auth()->user()->can('payroll.edit');
    @endphp

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng thu nhập" :value="$money($record->gross_income).'đ'" icon="add_circle" tone="success" />
        <x-ui.stat-card label="Tổng khấu trừ" :value="'-'.$money($record->total_deductions).'đ'" icon="remove_circle" tone="error" />
        <x-ui.stat-card label="Thực lĩnh" :value="$money($record->net_salary).'đ'" icon="payments" tone="primary"
                        :hint="$record->user?->email" />
    </div>

    @if ($record->usesQ3Formula())
        <x-ui.alert type="info" class="mb-md">
            @if ($record->isPartTime())
                Công thức Part-time (BA chốt Q3): <strong>số buổi × đơn giá buổi riêng + KPI giữ HS + buổi có GVNN + phụ cấp tự do − khoản trừ</strong>. Không trừ BHXH / Công đoàn.
            @else
                Công thức Full-time (BA chốt Q3): <strong>lương cơ bản + các khoản cộng − BHXH {{ rtrim(rtrim(number_format($settings['insurance_rate_percent'], 2, ',', '.'), '0'), ',') }}% − Công đoàn {{ rtrim(rtrim(number_format($settings['union_rate_percent'], 2, ',', '.'), '0'), ',') }}% (trên lương cơ bản) − thuế TNCN − trừ vi phạm</strong>.
            @endif
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
        <x-ui.data-table>
            <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Các khoản thu nhập</h3></x-slot:header>
            <table>
                <tbody>
                    @foreach ($record->earningLines() as $line)
                        <tr>
                            <td>
                                {{ $line['label'] }}
                                @if ($line['hint'])
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ $line['hint'] }}</span>
                                @endif
                            </td>
                            <td><x-ui.money :value="$line['amount']" suffix="đ" /></td>
                        </tr>
                    @endforeach
                    <tr class="bg-surface-container-low font-semibold">
                        <td>Tổng thu nhập</td>
                        <td><x-ui.money :value="$record->gross_income" suffix="đ" /></td>
                    </tr>
                </tbody>
            </table>
        </x-ui.data-table>

        <x-ui.data-table>
            <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Các khoản khấu trừ</h3></x-slot:header>
            <table>
                <tbody>
                    @foreach ($record->deductionLines() as $line)
                        <tr>
                            <td>
                                {{ $line['label'] }}
                                @if ($line['hint'] ?? null)
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ $line['hint'] }}</span>
                                @endif
                            </td>
                            <td><x-ui.money :value="-$line['amount']" suffix="đ" /></td>
                        </tr>
                    @endforeach
                    <tr class="bg-surface-container-low font-semibold">
                        <td>Tổng khấu trừ</td>
                        <td><x-ui.money :value="-$record->total_deductions" suffix="đ" /></td>
                    </tr>
                    <tr class="font-semibold">
                        <td>Thực lĩnh</td>
                        <td><x-ui.money :value="$record->net_salary" suffix="đ" /></td>
                    </tr>
                </tbody>
            </table>
        </x-ui.data-table>
    </div>

    {{-- Khoản nhập tay của Kế toán / Admin --}}
    <div class="mt-lg rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
        <h3 class="font-h3 text-h3 text-on-surface mb-md">Khoản nhập tay</h3>
        @if ($canEdit)
            @php
                $lines = old('lines', $record->manualLines() ?: [['kind' => 'earning', 'label' => '', 'amount' => '']]);
            @endphp
            <form action="{{ route('payroll.records.adjust', $record->id) }}" method="POST" class="space-y-md"
                  x-data="{ lines: @js(array_values($lines)) }">
                @csrf
                <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                    @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_RETENTION)
                        <x-ui.select name="retention_tier" label="Bậc KPI giữ học sinh (đ / HS / tháng)"
                                     :options="collect($settings['retention_tiers'])->mapWithKeys(fn ($t) => [(string) (int) $t => $money($t).'đ / HS'])->all()"
                                     :value="$record->retention_tier !== null ? (string) (int) $record->retention_tier : ''"
                                     placeholder="— Chưa chọn bậc —"
                                     :hint="'HS giữ được: '.(int) $record->retention_students.'/'.(int) $record->retention_base_students.' · Admin chọn bậc cho từng GV từng kỳ'" />
                        <x-ui.input type="number" name="foreign_session_pay" label="Buổi có GVNN (VNĐ) — chờ BA chốt" min="0" step="1000"
                                    :value="(int) $record->foreign_session_pay"
                                    :hint="(int) $record->foreign_teacher_sessions_count.' buổi có GVNN cùng lớp trong kỳ. Cách tính chờ BA chốt — nhập tổng tiền.'" />
                    @endif
                    @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_MANUAL)
                        <x-ui.input type="number" name="kpi_manual_amount" label="KPI (VNĐ) — nhập tự do" min="0" step="1000"
                                    :value="$record->kpi_manual_amount !== null ? (int) $record->kpi_manual_amount : null"
                                    hint="GV Full-time / Học thuật: Admin / Kế toán nhập số tiền KPI." />
                    @endif
                    @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_ACADEMIC)
                        <div class="rounded-lg border border-outline-variant bg-surface-container-low p-md font-body-small text-body-small text-on-surface-variant">
                            KPI Học vụ tự tính từ <a href="{{ route('kpi.evaluate', ['userId' => $record->user_id, 'month' => $period->month, 'year' => $period->year]) }}" class="font-semibold text-primary hover:underline">đánh giá KPI tháng {{ $period->month }}/{{ $period->year }}</a> — không sửa tay.
                        </div>
                    @endif
                    @if ($record->isFullTime() || ! $record->usesQ3Formula())
                        <x-ui.input type="number" name="tax_deduction" label="Thuế TNCN (VNĐ)" min="0" step="1000"
                                    :value="(int) $record->tax_deduction" hint="Admin / Kế toán nhập tay." />
                    @endif
                </div>

                <div class="space-y-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-label text-label uppercase text-on-surface-variant">Phụ cấp / thưởng / khấu trừ tự do</span>
                        <x-ui.button type="button" variant="secondary" icon="add" @click="lines.push({kind: 'earning', label: '', amount: ''})">Thêm dòng</x-ui.button>
                    </div>
                    <template x-for="(line, i) in lines" :key="i">
                        <div class="grid grid-cols-1 gap-sm md:grid-cols-12">
                            <select :name="`lines[${i}][kind]`" x-model="line.kind" class="md:col-span-3 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                                <option value="earning">Khoản cộng</option>
                                <option value="deduction">Khoản trừ</option>
                            </select>
                            <input type="text" :name="`lines[${i}][label]`" x-model="line.label" placeholder="Tên khoản (VD: Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác, Tạm ứng)"
                                   class="md:col-span-6 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <input type="number" min="0" step="1000" :name="`lines[${i}][amount]`" x-model="line.amount" placeholder="Số tiền"
                                   class="md:col-span-2 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <button type="button" class="md:col-span-1 text-error hover:underline font-body-small text-body-small" @click="lines.splice(i, 1)">Xoá</button>
                        </div>
                    </template>
                </div>

                <x-ui.textarea name="adjustment_notes" label="Ghi chú" rows="2" :value="$record->adjustment_notes"
                               hint="Các khoản nhập tay được giữ khi bấm Đồng bộ & Tính lại." />
                <div class="flex justify-end">
                    <x-ui.button type="submit" icon="save">Lưu điều chỉnh</x-ui.button>
                </div>
            </form>
        @else
            <p class="font-body-base text-body-base text-on-surface-variant">
                {{ $record->adjustment_notes ?: 'Không có ghi chú điều chỉnh.' }}
                @if ($period->isLocked())
                    <span class="block font-caption text-caption">Kỳ lương đã {{ mb_strtolower($period->status_label) }} — phiếu lương đã khoá.</span>
                @endif
            </p>
        @endif
        <p class="mt-sm font-caption text-caption text-on-surface-variant">Diễn giải tự động: {{ $record->notes }}</p>
    </div>

    {{-- Căn cứ tính --}}
    <div class="mt-lg grid grid-cols-1 gap-lg xl:grid-cols-2">
        <x-ui.data-table min-width="480px">
            <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Buổi dạy hợp lệ ({{ $timesheets->count() }})</h3></x-slot:header>
            <table>
                <thead><tr><th>Ngày</th><th>Lớp</th><th class="text-right">Giờ</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th></tr></thead>
                <tbody>
                    @forelse ($timesheets as $ts)
                        @php $pay = $ts->sessionPay($record->user); @endphp
                        <tr>
                            <td class="font-code text-code">{{ $ts->teaching_date->format('d/m') }}</td>
                            <td>{{ $ts->classModel?->name ?? '—' }} <span class="font-caption text-caption text-on-surface-variant">{{ $ts->type_label }}</span></td>
                            <td class="text-right font-code text-code">{{ (float) $ts->hours }}</td>
                            <td class="text-right font-code text-code">{{ $money($pay['rate']) }} {{ $pay['unit'] === 'session' ? 'đ/buổi' : 'đ/giờ' }}</td>
                            <td>
                                @if ($record->isFullTime())
                                    <span class="font-caption text-caption text-on-surface-variant">Trong lương cơ bản</span>
                                @else
                                    <x-ui.money :value="$pay['amount']" suffix="đ" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="schedule" title="Không có buổi dạy trong kỳ" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        <div class="space-y-lg">
            @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_RETENTION)
                <x-ui.data-table>
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">KPI giữ học sinh</h3></x-slot:header>
                    <table>
                        <tbody>
                            <tr><td>HS trong lớp đầu kỳ</td><td class="text-right font-code text-code">{{ (int) $record->retention_base_students }}</td></tr>
                            <tr><td>HS nghỉ (Thôi học) trong kỳ</td><td class="text-right font-code text-code">{{ (int) data_get($record->calculation_details, 'retention.lost', 0) }}</td></tr>
                            <tr class="font-semibold"><td>HS giữ được</td><td class="text-right font-code text-code">{{ (int) $record->retention_students }}</td></tr>
                            @foreach ($lostStudents as $st)
                                <tr><td colspan="2" class="font-caption text-caption text-on-surface-variant">Nghỉ: {{ $st->code }} — {{ $st->name }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if ($record->kpi_source === \App\Models\PayrollRecord::KPI_ACADEMIC && ! empty(data_get($record->calculation_details, 'kpi.items')))
                <x-ui.data-table>
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">KPI Học vụ — điểm {{ rtrim(rtrim(number_format((float) $record->kpi_score, 2, ',', '.'), '0'), ',') }}%</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Mục</th><th class="text-right">Trọng số</th><th class="text-right">Điểm</th><th class="text-right">Tiền</th></tr></thead>
                        <tbody>
                            @foreach (data_get($record->calculation_details, 'kpi.items', []) as $kpiItem)
                                <tr>
                                    <td>{{ $kpiItem['code'] }} {{ $kpiItem['name'] }} <span class="block font-caption text-caption text-on-surface-variant">{{ $kpiItem['group'] }}</span></td>
                                    <td class="text-right font-code text-code">{{ rtrim(rtrim(number_format($kpiItem['weight'], 2), '0'), '.') }}%</td>
                                    <td class="text-right font-code text-code">{{ rtrim(rtrim(number_format($kpiItem['score'], 2), '0'), '.') }}%</td>
                                    <td><x-ui.money :value="$kpiItem['amount']" suffix="đ" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if (! empty(data_get($record->calculation_details, 'renewal.classes')))
                <x-ui.data-table>
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Thưởng tái tục theo lớp</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Lớp</th><th class="text-right">HS nghỉ</th><th class="text-right">%</th><th class="text-right">Doanh thu lớp</th><th class="text-right">Thưởng</th></tr></thead>
                        <tbody>
                            @foreach (data_get($record->calculation_details, 'renewal.classes', []) as $row)
                                <tr>
                                    <td>{{ $row['class'] }} <span class="block font-caption text-caption text-on-surface-variant">{{ $row['base'] }} HS đầu kỳ</span></td>
                                    <td class="text-right font-code text-code">{{ $row['quits'] }}</td>
                                    <td class="text-right font-code text-code">{{ rtrim(rtrim(number_format($row['percent'], 2, ',', '.'), '0'), ',') }}%@if ($row['pending']) <span class="block font-caption text-caption text-amber-700">chờ BA</span>@endif</td>
                                    <td><x-ui.money :value="$row['revenue']" suffix="đ" /></td>
                                    <td><x-ui.money :value="$row['amount']" suffix="đ" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if ($record->usesQ3Formula())
                <x-ui.data-table>
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Hoa hồng tuyển sinh</h3>
                        <p class="font-caption text-caption text-on-surface-variant">
                            {{ (int) $record->commission_closed_count }} HS chốt trong kỳ{{ $record->commission_percent !== null ? ' → bậc '.rtrim(rtrim(number_format((float) $record->commission_percent, 2, ',', '.'), '0'), ',').'%' : '' }}.
                            Trả khi đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc; chưa đủ thì hoãn sang kỳ sau.
                        </p>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Học viên / phiếu</th><th class="text-right">Thực thu</th><th class="text-right">%</th><th class="text-right">Hoa hồng</th></tr></thead>
                        <tbody>
                            @foreach ($paidCommission as $item)
                                <tr>
                                    <td>{{ $item->student?->name ?? '—' }} <span class="block font-caption text-caption text-on-surface-variant">{{ $item->receipt?->receipt_number }} · phát sinh {{ $item->earned_period_start->format('m/Y') }} · Trả trong kỳ</span></td>
                                    <td><x-ui.money :value="$item->base_amount" suffix="đ" /></td>
                                    <td class="text-right font-code text-code">{{ rtrim(rtrim(number_format((float) $item->percent, 2, ',', '.'), '0'), ',') }}%</td>
                                    <td><x-ui.money :value="$item->amount" suffix="đ" /></td>
                                </tr>
                            @endforeach
                            @foreach ($deferredCommission as $item)
                                <tr>
                                    <td>{{ $item->student?->name ?? '—' }} <span class="block font-caption text-caption text-amber-700">{{ $item->receipt?->receipt_number }} · {{ $item->deferred_reason ?? 'Hoãn sang kỳ sau' }}</span></td>
                                    <td><x-ui.money :value="$item->base_amount" suffix="đ" /></td>
                                    <td class="text-right font-code text-code">{{ rtrim(rtrim(number_format((float) $item->percent, 2, ',', '.'), '0'), ',') }}%</td>
                                    <td class="text-right font-code text-code text-on-surface-variant">Hoãn {{ $money($item->amount) }}đ</td>
                                </tr>
                            @endforeach
                            @if ($paidCommission->isEmpty() && $deferredCommission->isEmpty())
                                <tr><td colspan="4"><x-ui.empty-state icon="receipt_long" title="Không có hoa hồng trả / hoãn trong kỳ" /></td></tr>
                            @endif
                        </tbody>
                    </table>
                </x-ui.data-table>
            @else
                <x-ui.data-table>
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Phiếu thu tính hoa hồng ({{ $commissionReceipts->count() }})</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Ngày duyệt</th><th>Học viên</th><th class="text-right">Thực thu</th></tr></thead>
                        <tbody>
                            @forelse ($commissionReceipts as $receipt)
                                <tr>
                                    <td class="font-code text-code">{{ $receipt->approved_at?->format('d/m/Y') }}</td>
                                    <td>{{ $receipt->student?->name ?? '—' }}</td>
                                    <td><x-ui.money :value="$receipt->amount" suffix="đ" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="3"><x-ui.empty-state icon="receipt_long" title="Không có phiếu thu khách mới trong kỳ" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            <x-ui.data-table>
                <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Phạt & thu hồi hoa hồng</h3></x-slot:header>
                <table>
                    <tbody>
                        @foreach ($penalties as $pen)
                            <tr>
                                <td>Biên bản {{ $pen->code }} — {{ $pen->violation_type }} <span class="font-caption text-caption text-on-surface-variant">hạn nộp {{ $pen->due_date?->format('d/m/Y') ?? '—' }}</span></td>
                                <td><x-ui.money :value="-$pen->amount" suffix="đ" /></td>
                            </tr>
                        @endforeach
                        @foreach ($clawbacks as $adj)
                            <tr>
                                <td>{{ $adj->reason }}</td>
                                <td><x-ui.money :value="$adj->amount" suffix="đ" /></td>
                            </tr>
                        @endforeach
                        @if ($penalties->isEmpty() && $clawbacks->isEmpty())
                            <tr><td colspan="2"><x-ui.empty-state icon="task_alt" title="Không có khoản phạt hay thu hồi" /></td></tr>
                        @endif
                    </tbody>
                </table>
            </x-ui.data-table>
        </div>
    </div>
</x-app-layout>
