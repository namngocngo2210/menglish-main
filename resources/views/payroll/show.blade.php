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

                <a href="{{ route('payroll.periods.export', $period->id) }}" class="px-3 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold shadow-2xs transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">download</span>
                    <span>Xuất Excel</span>
                </a>
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
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng buổi dạy (Part-time)</span>
                <div class="text-xl font-black text-indigo-900 font-mono">{{ $period->records->where('employee_type', 'parttime')->sum('teaching_sessions') }} buổi</div>
                <p class="text-[11px] text-indigo-600 font-medium">{{ $period->records->sum('actual_hours') }} giờ chấm công hợp lệ</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng thưởng KPI / Giữ học sinh</span>
                <div class="text-xl font-black text-emerald-600 font-mono">{{ number_format($period->records->sum('kpi_bonus')) }}đ</div>
                <p class="text-[11px] text-emerald-700 font-medium">Giữ HS (PT) · KPI tự do · KPI Học vụ</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tổng khấu trừ &amp; Phạt</span>
                <div class="text-xl font-black text-rose-600 font-mono">-{{ number_format($period->records->sum('total_deductions')) }}đ</div>
                <p class="text-[11px] text-rose-600 font-medium">BHXH, Công đoàn, thuế TNCN, phạt, thu hồi hoa hồng, khấu trừ tự do</p>
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
                            <th class="py-3 px-4 text-center">Loại</th>
                            <th class="py-3 px-4 text-right">Lương CB / Buổi dạy</th>
                            <th class="py-3 px-4 text-right">KPI</th>
                            <th class="py-3 px-4 text-right">Buổi GVNN</th>
                            <th class="py-3 px-4 text-right">Hoa hồng</th>
                            <th class="py-3 px-4 text-right">Tái tục</th>
                            <th class="py-3 px-4 text-right">Phụ cấp tự do</th>
                            <th class="py-3 px-4 text-right">BHXH + CĐ</th>
                            <th class="py-3 px-4 text-right">Thuế TNCN</th>
                            <th class="py-3 px-4 text-right">Phạt & trừ khác</th>
                            <th class="py-3 px-4 text-right font-black">Thực lĩnh</th>
                            <th class="py-3 px-4 text-center">Trạng thái</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($period->records as $r)
                            <tr class="hover:bg-orange-50/20 transition" x-data="{ openForeignModal: false }">
                                <td class="py-3.5 px-4 font-bold text-gray-900">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center font-bold text-xs">
                                            {{ Str::substr($r->user?->name ?? 'U', 0, 1) }}
                                        </div>
                                        <div>
                                            <a href="{{ route('payroll.records.show', $r->id) }}" class="text-xs font-bold text-gray-900 hover:text-orange-600 hover:underline" title="Xem phiếu lương">{{ $r->user?->name }}</a>
                                            <p class="text-[10px] text-gray-400 font-mono">{{ $r->user?->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $r->isPartTime() ? 'bg-sky-50 text-sky-700 border border-sky-200' : 'bg-slate-50 text-slate-700 border border-slate-200' }}">{{ $r->employee_type_label }}</span>
                                    <p class="text-[10px] text-gray-400 mt-0.5">{{ $r->salary_role_label }}</p>
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono font-semibold">
                                    @if ($r->isPartTime())
                                        <span class="text-emerald-600">{{ number_format($r->teaching_salary) }}đ</span>
                                        <p class="text-[10px] text-gray-400">{{ (int) $r->teaching_sessions }} buổi</p>
                                    @else
                                        {{ number_format((float) $r->base_salary + (float) $r->teaching_salary) }}đ
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono text-amber-600 font-semibold">
                                    {{ number_format($r->kpi_bonus) }}đ
                                    @if ($r->kpi_source === 'retention')
                                        <p class="text-[10px] text-gray-400">{{ (int) $r->retention_students }} HS × {{ $r->retention_tier !== null ? number_format($r->retention_tier) : 'chưa chọn bậc' }}</p>
                                    @elseif ($r->kpi_source === 'academic_kpi')
                                        <p class="text-[10px] text-gray-400">{{ $r->kpi_score !== null ? rtrim(rtrim(number_format((float) $r->kpi_score, 2), '0'), '.').'% quỹ' : 'chưa chấm' }}</p>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono">{{ $r->isPartTime() ? number_format($r->foreign_session_pay).'đ' : '—' }}</td>
                                <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">
                                    {{ number_format($r->commission_bonus) }}đ
                                    @if ((float) $r->commission_deferred > 0)
                                        <p class="text-[10px] text-amber-700 font-semibold">Hoãn {{ number_format($r->commission_deferred) }}đ</p>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono">{{ number_format($r->renew_bonus) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono">{{ number_format((float) $r->allowance + (float) $r->other_bonus) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format((float) $r->insurance_deduction + (float) $r->union_deduction) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format($r->tax_deduction) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono text-rose-600">
                                    -{{ number_format((float) $r->penalty_deduction + (float) $r->commission_clawback + (float) $r->other_deduction + (float) $r->foreign_teacher_deduction) }}đ
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
                                    @if (! $period->isLocked() && $r->isPartTime())
                                    @can('payroll.edit')
                                    <button type="button" @click="openForeignModal = true" class="px-2.5 py-1 text-[11px] font-bold rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700 transition">
                                        Buổi GVNN
                                    </button>

                                    <!-- Modal nhập lương buổi có GVNN (chờ BA chốt cách tính) -->
                                    <div x-show="openForeignModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/40 flex items-center justify-center p-4 text-left">
                                        <div class="bg-white rounded-2xl max-w-md w-full p-5 space-y-4 shadow-xl" @click.away="openForeignModal = false">
                                            <div class="flex items-center justify-between border-b pb-3">
                                                <div>
                                                    <h3 class="font-bold text-sm text-gray-900">Lương buổi có GVNN</h3>
                                                    <p class="text-xs text-gray-500 font-medium">{{ $r->user?->name }}</p>
                                                </div>
                                                <button type="button" @click="openForeignModal = false" class="text-gray-400 hover:text-gray-600">
                                                    <span class="material-symbols-outlined">close</span>
                                                </button>
                                            </div>

                                            <form action="{{ route('payroll.records.update', $r->id) }}" method="POST" class="space-y-3 text-xs">
                                                @csrf
                                                <div class="p-3 bg-amber-50/70 border border-amber-200 rounded-xl text-amber-800 text-[11px]">
                                                    <strong>Chờ BA chốt:</strong> cách tính lương buổi có GVNN chưa được xác nhận. Kế toán nhập tổng tiền cộng cho GV.
                                                    Kỳ này có {{ (int) $r->foreign_teacher_sessions_count }} buổi GVNN cùng lớp.
                                                </div>

                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Số tiền (VNĐ)</label>
                                                    <input type="number" name="foreign_session_pay" value="{{ (int) $r->foreign_session_pay }}" min="0" step="1000" required class="w-full text-xs rounded-xl border-gray-200 p-2 font-mono font-bold">
                                                </div>

                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Ghi chú</label>
                                                    <textarea name="notes" rows="2" placeholder="Ghi chú thêm..." class="w-full text-xs rounded-xl border-gray-200 p-2">{{ $r->adjustment_notes }}</textarea>
                                                </div>

                                                <div class="flex items-center justify-end gap-2 pt-2 border-t">
                                                    <button type="button" @click="openForeignModal = false" class="px-3 py-1.5 border rounded-lg text-gray-600 hover:bg-gray-50">Hủy</button>
                                                    <button type="submit" class="px-4 py-1.5 bg-primary-container text-white font-bold rounded-lg shadow-sm hover:bg-primary-hover">Lưu</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    @endcan
                                    @endif
                                    <a href="{{ route('payroll.records.show', $r->id) }}" class="ml-1 px-2.5 py-1 text-[11px] font-bold rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700 transition inline-block">Phiếu</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="14" class="text-center py-8 text-gray-400 text-xs">Chưa có chi tiết lương nhân sự cho kỳ này.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
