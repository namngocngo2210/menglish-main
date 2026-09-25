<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.index') }}" class="w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600">payments</span>
                        <span>Chi Tiết Bảng Lương: {{ $period->title }}</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full border font-bold {{ $period->status_badge }}">
                            {{ $period->status_label }}
                        </span>
                    </h1>
                    <p class="text-xs text-gray-500 font-mono">Mã kỳ: {{ $period->code }} · Thời gian: {{ $period->start_date->format('d/m/Y') }} – {{ $period->end_date->format('d/m/Y') }}</p>
                </div>
            </div>
            
            <div class="flex flex-wrap items-center gap-2">
                @can('payroll.calculate')
                @unless(in_array($period->status, ['approved', 'paid']))
                <form action="{{ route('payroll.periods.calculate', $period->id) }}" method="POST">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">sync</span>
                        <span>Đồng bộ &amp; Tính lại</span>
                    </button>
                </form>
                @endunless
                @endcan

                @can('payroll.approve')
                @unless(in_array($period->status, ['approved', 'paid']))
                    <form action="{{ route('payroll.periods.approve', $period->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]">check_circle</span>
                            <span>Chốt bảng lương</span>
                        </button>
                    </form>
                @endunless
                @endcan

                @can('payroll.mark_paid')
                @if ($period->status === 'approved')
                    <form action="{{ route('payroll.periods.mark-paid', $period->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]">payments</span>
                            <span>Đánh dấu đã chi trả</span>
                        </button>
                    </form>
                @endif
                @endcan

                <button type="button" onclick="window.print();" class="px-3 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold shadow-2xs transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span>
                    <span>Xuất PDF</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        @error('period')
            <div class="p-4 rounded-xl bg-rose-50 text-rose-800 border border-rose-200 flex items-center gap-3 text-sm font-medium">
                <span class="material-symbols-outlined text-rose-600">error</span>
                <span>{{ $message }}</span>
            </div>
        @enderror

        <!-- Department / Role Sub-navigation Tabs -->
        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 text-xs">
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="px-4 py-2 font-bold rounded-xl bg-orange-600 text-white shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">groups</span>
                <span>Toàn bộ / GV Part-time</span>
            </a>
            <a href="{{ route('payroll.periods.fulltime', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">work</span>
                <span>Giáo viên Full-time</span>
            </a>
            <a href="{{ route('payroll.periods.academic', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">school</span>
                <span>Khối Học Thuật</span>
            </a>
            <a href="{{ route('payroll.periods.operations', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">support_agent</span>
                <span>Khối Học Vụ &amp; Vận Hành</span>
            </a>
        </div>

        <!-- KPI & Salary Statistics Bento Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng chi quỹ lương</span>
                <div class="text-xl font-black text-orange-600 font-mono">{{ number_format($period->total_amount) }}đ</div>
                <p class="text-[11px] text-gray-500 font-mono">{{ $period->records->count() }} nhân sự nhận lương</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng giờ giảng dạy</span>
                <div class="text-xl font-black text-indigo-900 font-mono">{{ $period->records->sum('actual_hours') }} giờ</div>
                <p class="text-[11px] text-indigo-600 font-medium">Đã đối soát với Chấm công</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng thưởng KPI / Giữ học sinh</span>
                <div class="text-xl font-black text-emerald-600 font-mono">{{ number_format($period->records->sum('kpi_bonus')) }}đ</div>
                <p class="text-[11px] text-emerald-700 font-medium">Theo bảng KPI công khai</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng khấu trừ &amp; Phạt</span>
                <div class="text-xl font-black text-rose-600 font-mono">-{{ number_format($period->records->sum('total_deductions')) }}đ</div>
                <p class="text-[11px] text-rose-600 font-medium">Bao gồm phạt, bảo hiểm, thuế &amp; giảm trừ GVNN</p>
            </div>
        </div>

        <!-- Master Salary Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-orange-600 text-base">table_rows</span>
                    <span>Chi Tiết Bảng Lương Từng Nhân Sự (Kỳ {{ $period->month }}/{{ $period->year }})</span>
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Giáo viên / Nhân sự</th>
                            <th class="py-3 px-4 text-right">Lương cơ bản</th>
                            <th class="py-3 px-4 text-center">Số giờ dạy</th>
                            <th class="py-3 px-4 text-right">Thù lao dạy</th>
                            <th class="py-3 px-4 text-right">Thưởng KPI</th>
                            <th class="py-3 px-4 text-right">Phụ cấp</th>
                            <th class="py-3 px-4 text-right">Hoa hồng</th>
                            <th class="py-3 px-4 text-right">Giảm trừ</th>
                            <th class="py-3 px-4 text-right font-black">Thực lĩnh</th>
                            <th class="py-3 px-4 text-center">Trạng thái</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($period->records as $r)
                            <tr class="hover:bg-orange-50/20 transition" x-data="{ openDeductionModal: false }">
                                <td class="py-3.5 px-4 font-bold text-gray-900">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center font-bold text-xs">
                                            {{ Str::substr($r->user?->name ?? 'U', 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold text-gray-900">{{ $r->user?->name }}</p>
                                            <p class="text-[10px] text-gray-400 font-mono">{{ $r->user?->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono font-semibold">{{ number_format($r->base_salary) }}đ</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold">{{ $r->actual_hours }}h</td>
                                <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">{{ number_format($r->teaching_salary) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono text-amber-600 font-semibold">{{ number_format($r->kpi_bonus) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono">{{ number_format($r->allowance) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">
                                    {{ number_format($r->commission_bonus + $r->renew_bonus) }}đ
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono text-rose-600">
                                    <div>-{{ number_format($r->total_deductions) }}đ</div>
                                    @if($r->foreign_teacher_deduction > 0 || $r->foreign_teacher_sessions_count > 0)
                                        <div class="text-[10px] text-amber-700 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block font-semibold">
                                            GVNN: -{{ number_format($r->foreign_teacher_deduction) }}đ ({{ $r->foreign_teacher_sessions_count }}b)
                                        </div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono font-black text-orange-600 text-sm">
                                    {{ number_format($r->net_salary) }}đ
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    @php
                                        [$rowBadge, $rowLabel] = match ($period->status) {
                                            'paid' => ['bg-purple-50 text-purple-700 border border-purple-200', 'Đã trả'],
                                            'approved' => ['bg-emerald-50 text-emerald-700 border border-emerald-200', 'Đã duyệt'],
                                            default => ['bg-blue-50 text-blue-700 border border-blue-200', 'Đang tính'],
                                        };
                                    @endphp
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $rowBadge }}">{{ $rowLabel }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    @if (! $period->isLocked())
                                    @can('payroll.edit')
                                    <button type="button" @click="openDeductionModal = true" class="px-2.5 py-1 text-[11px] font-bold rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700 transition">
                                        Trừ GVNN
                                    </button>

                                    <!-- Modal chỉnh sửa giảm trừ GVNN cùng dạy -->
                                    <div x-show="openDeductionModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/40 flex items-center justify-center p-4 text-left">
                                        <div class="bg-white rounded-2xl max-w-md w-full p-5 space-y-4 shadow-xl" @click.away="openDeductionModal = false" x-data="{
                                            sessions: {{ $r->foreign_teacher_sessions_count ?: 0 }},
                                            rate: {{ $r->foreign_teacher_deduction_rate ?: 50000 }},
                                            get total() { return this.sessions * this.rate; }
                                        }">
                                            <div class="flex items-center justify-between border-b pb-3">
                                                <div>
                                                    <h3 class="font-bold text-sm text-gray-900">Giảm trừ Giáo viên Nước ngoài cùng dạy</h3>
                                                    <p class="text-xs text-gray-500 font-medium">{{ $r->user?->name }}</p>
                                                </div>
                                                <button type="button" @click="openDeductionModal = false" class="text-gray-400 hover:text-gray-600">
                                                    <span class="material-symbols-outlined">close</span>
                                                </button>
                                            </div>

                                            <form action="{{ route('payroll.records.update', $r->id) }}" method="POST" class="space-y-3 text-xs">
                                                @csrf
                                                <div class="p-3 bg-amber-50/70 border border-amber-200 rounded-xl text-amber-800 text-[11px]">
                                                    <strong>Quy định:</strong> Nếu trong cùng lớp, ngày/ca học có cả GVVN và GVNN cùng dạy, giảm trừ 50.000đ/buổi cho GVVN.
                                                </div>

                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Số buổi có GVNN cùng dạy trong ca</label>
                                                    <input type="number" name="foreign_teacher_sessions_count" x-model.number="sessions" min="0" required class="w-full text-xs rounded-xl border-gray-200 p-2 font-mono font-bold">
                                                </div>

                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Đơn giá giảm trừ mỗi buổi (VNĐ)</label>
                                                    <input type="number" name="foreign_teacher_deduction_rate" x-model.number="rate" min="0" step="5000" required class="w-full text-xs rounded-xl border-gray-200 p-2 font-mono font-bold">
                                                </div>

                                                <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between">
                                                    <span class="font-bold text-gray-700">Tổng số tiền giảm trừ:</span>
                                                    <span class="text-sm font-mono font-black text-rose-600" x-text="new Intl.NumberFormat('vi-VN').format(total) + ' đ'"></span>
                                                </div>

                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Ghi chú điều chỉnh</label>
                                                    <textarea name="notes" rows="2" placeholder="Ghi chú thêm..." class="w-full text-xs rounded-xl border-gray-200 p-2">{{ $r->notes }}</textarea>
                                                </div>

                                                <div class="flex items-center justify-end gap-2 pt-2 border-t">
                                                    <button type="button" @click="openDeductionModal = false" class="px-3 py-1.5 border rounded-lg text-gray-600 hover:bg-gray-50">Hủy</button>
                                                    <button type="submit" class="px-4 py-1.5 bg-primary-container text-white font-bold rounded-lg shadow-sm hover:bg-primary-hover">Lưu giảm trừ</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center py-8 text-gray-400 text-xs">Chưa có chi tiết lương nhân sự cho kỳ này.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
