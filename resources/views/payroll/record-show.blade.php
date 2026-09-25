<x-app-layout>
    <x-ui.page-header :title="'Phiếu lương: '.($record->user?->name ?? 'Nhân sự')"
                      :description="$period->title.' · '.$period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y')">
        <x-slot:breadcrumbs>
            <a href="{{ route('payroll.periods.index') }}" class="hover:text-primary">Bảng lương</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="hover:text-primary">{{ $period->title }}</a>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <span class="inline-block rounded-full border px-sm py-xs text-[11px] font-bold {{ $period->status_badge }}">{{ $period->status_label }}</span>
            <x-ui.button variant="secondary" icon="print" onclick="window.print()">In phiếu</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng thu nhập" :value="number_format($record->gross_income, 0, ',', '.').'đ'" icon="add_circle" tone="success" />
        <x-ui.stat-card label="Tổng khấu trừ" :value="'-'.number_format($record->total_deductions, 0, ',', '.').'đ'" icon="remove_circle" tone="error" />
        <x-ui.stat-card label="Thực lĩnh" :value="number_format((float) $record->net_salary, 0, ',', '.').'đ'" icon="payments" tone="primary"
                        :hint="$record->user?->email" />
    </div>

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
                            <td>{{ $line['label'] }}</td>
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

    {{-- Điều chỉnh tay của Kế toán --}}
    <div class="mt-lg rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
        <h3 class="font-h3 text-h3 text-on-surface mb-md">Điều chỉnh của Kế toán</h3>
        @if (! $period->isLocked() && auth()->user()->can('payroll.edit'))
            <form action="{{ route('payroll.records.adjust', $record->id) }}" method="POST" class="grid grid-cols-1 gap-md md:grid-cols-3">
                @csrf
                <x-ui.input type="number" name="allowance_override" label="Phụ cấp (VNĐ)" min="0" step="1000"
                            :value="$record->allowance_override !== null ? (int) $record->allowance_override : null"
                            :placeholder="'Theo cấu hình: '.number_format((float) $record->allowance, 0, ',', '.')"
                            hint="Bỏ trống = phụ cấp theo cấu hình tham số lương." />
                <x-ui.input type="number" name="other_bonus" label="Thưởng / cộng khác (VNĐ)" min="0" step="1000" :value="(int) $record->other_bonus" />
                <x-ui.input type="number" name="other_deduction" label="Khấu trừ khác (VNĐ)" min="0" step="1000" :value="(int) $record->other_deduction" />
                <div class="md:col-span-3">
                    <x-ui.textarea name="adjustment_notes" label="Ghi chú điều chỉnh" rows="2" :value="$record->adjustment_notes"
                                   hint="Bắt buộc khi có khoản cộng/trừ khác. Các điều chỉnh được giữ khi bấm Đồng bộ & Tính lại." />
                </div>
                <div class="md:col-span-3 flex justify-end">
                    <x-ui.button type="submit" icon="save">Lưu điều chỉnh</x-ui.button>
                </div>
            </form>
        @else
            <p class="font-body-base text-body-base text-on-surface-variant">
                {{ $record->adjustment_notes ?: 'Không có điều chỉnh tay.' }}
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
            <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Ca dạy hợp lệ ({{ $timesheets->count() }})</h3></x-slot:header>
            <table>
                <thead><tr><th>Ngày</th><th>Lớp</th><th class="text-right">Giờ</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th></tr></thead>
                <tbody>
                    @forelse ($timesheets as $ts)
                        @php $rate = $ts->effectiveHourlyRate($record->user); @endphp
                        <tr>
                            <td class="font-code text-code">{{ $ts->teaching_date->format('d/m') }}</td>
                            <td>{{ $ts->classModel?->name ?? '—' }} <span class="font-caption text-caption text-on-surface-variant">{{ $ts->type_label }}</span></td>
                            <td class="text-right font-code text-code">{{ (float) $ts->hours }}</td>
                            <td><x-ui.money :value="$rate" suffix="" /></td>
                            <td><x-ui.money :value="(float) $ts->hours * $rate" suffix="đ" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="schedule" title="Không có ca dạy trong kỳ" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        <div class="space-y-lg">
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
