<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600">account_balance_wallet</span>
                    Danh Sách Bảng Lương Theo Kỳ
                </h1>
                <p class="text-xs text-gray-500">Quản lý, tổng hợp chấm công và chốt lương giáo viên, nhân sự theo từng kỳ học</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="document.getElementById('newPeriodModal').classList.remove('hidden')" class="px-3.5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">add_circle</span>
                    <span>Tạo Kỳ Lương Mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    <!-- Create Period Modal -->
    <div id="newPeriodModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-gray-100">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <h3 class="font-bold text-sm text-gray-900 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-orange-600 text-lg">calendar_month</span>
                    <span>Tạo Kỳ Tính Lương Mới</span>
                </h3>
                <button type="button" onclick="document.getElementById('newPeriodModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('payroll.periods.store') }}" method="POST" class="space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Tháng tính lương</label>
                        <select name="month" class="w-full text-xs font-semibold rounded-xl border border-gray-300 p-2.5 bg-slate-50">
                            @for ($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}" {{ $m == date('n') ? 'selected' : '' }}>Tháng {{ str_pad($m, 2, '0', STR_PAD_LEFT) }}</option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Năm</label>
                        <input type="number" name="year" value="{{ date('Y') }}" min="2025" max="2030" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50" />
                    </div>
                </div>
                <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-[11px] text-amber-800 space-y-1">
                    <p class="font-bold flex items-center gap-1">
                        <span class="material-symbols-outlined text-[15px]">info</span>
                        <span>Cơ chế tự động:</span>
                    </p>
                    <p>Hệ thống sẽ tự động quét chấm công, tính giờ dạy, đồng bộ KPI doanh số và trừ phạt theo quy chế của MEnglish.</p>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 text-xs">
                    <button type="button" onclick="document.getElementById('newPeriodModal').classList.add('hidden')" class="px-3.5 py-2 rounded-xl border border-gray-300 text-gray-700 hover:bg-gray-50">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white font-bold rounded-xl shadow-xs transition">Khởi tạo &amp; Tính toán</button>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-6">
        
        <!-- Filter & Header Summary Card -->
        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Chọn Kỳ Lương</label>
                    <div class="relative">
                        <select onchange="if(this.value) window.location.href=this.value;" class="text-xs font-bold rounded-xl border border-gray-300 bg-slate-50 py-2 pl-3 pr-8 focus:ring-primary-container focus:border-primary-container cursor-pointer">
                            <option value="">-- Mở kỳ lương --</option>
                            @foreach ($allPeriods as $p)
                                <option value="{{ route('payroll.periods.show', $p->id) }}">
                                    {{ $p->title }} ({{ $p->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <form method="GET" action="{{ route('payroll.periods.index') }}" class="flex items-center gap-3 flex-wrap">
                <select name="status" onchange="this.form.submit()" class="text-xs rounded-xl border border-gray-300 bg-slate-50 py-2 pl-3 pr-8">
                    <option value="">Mọi trạng thái</option>
                    @foreach (['draft' => 'Đang tính', 'reviewing' => 'Đang soát', 'approved' => 'Đã duyệt', 'paid' => 'Đã trả'] as $statusValue => $statusText)
                        <option value="{{ $statusValue }}" @selected($status === $statusValue)>{{ $statusText }}</option>
                    @endforeach
                </select>
                <div class="relative w-full sm:w-64">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                    <input type="search" name="search" value="{{ $search }}" placeholder="Tìm kiếm kỳ lương / nhân sự..." class="w-full text-xs rounded-xl border border-gray-300 pl-9 pr-3 py-2 bg-slate-50 focus:bg-white focus:ring-primary-container focus:border-primary-container transition" />
                </div>
            </form>
        </div>

        <!-- Smart Warning Banner (if any) -->
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-3 shadow-2xs">
            <span class="material-symbols-outlined text-amber-600 text-xl shrink-0 mt-0.5">warning</span>
            <div class="flex-1 text-xs">
                <p class="font-bold text-amber-950">Lưu ý chốt bảng lương định kỳ:</p>
                <p class="text-amber-800 mt-0.5">
                    Để đảm bảo tính chính xác, vui lòng hoàn tất duyệt Chấm công giáo viên và Chốt xếp hạng KPI trước khi bấm Chốt bảng lương chính thức.
                </p>
            </div>
            <a href="{{ route('payroll.kpi-leaderboard') }}" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-xl transition shrink-0">
                Xem Bảng KPI
            </a>
        </div>

        <!-- Periods Table Card -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-orange-600 text-base">format_list_bulleted</span>
                    <span>Danh Sách Kỳ Tính Lương Tổng Hợp</span>
                </h3>
                <span class="text-xs font-bold text-gray-500 font-mono">{{ $periods->total() }} kỳ lương</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Mã kỳ</th>
                            <th class="py-3 px-4">Tên kỳ tính lương</th>
                            <th class="py-3 px-4">Khoảng thời gian</th>
                            <th class="py-3 px-4 text-center">Số nhân sự</th>
                            <th class="py-3 px-4 text-right">Tổng giờ dạy</th>
                            <th class="py-3 px-4 text-right">Tổng chi lương</th>
                            <th class="py-3 px-4">Trạng thái</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($periods as $p)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3.5 px-4 font-mono font-bold text-gray-900">{{ $p->code }}</td>
                                <td class="py-3.5 px-4 font-bold text-gray-900">{{ $p->title }}</td>
                                <td class="py-3.5 px-4 text-gray-500 font-mono">{{ $p->start_date->format('d/m/Y') }} – {{ $p->end_date->format('d/m/Y') }}</td>
                                <td class="py-3.5 px-4 text-center font-bold">{{ $p->records_count > 0 ? $p->records_count : $p->total_staff }} người</td>
                                <td class="py-3.5 px-4 text-right font-mono font-semibold">{{ $p->total_hours }}h</td>
                                <td class="py-3.5 px-4 text-right font-mono font-black text-orange-600 text-sm">{{ number_format($p->total_amount) }}đ</td>
                                <td class="py-3.5 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $p->status_badge }}">
                                        {{ $p->status_label }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <a href="{{ route('payroll.periods.show', $p->id) }}" class="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-lg font-bold text-xs shadow-2xs transition inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[15px]">visibility</span>
                                        <span>Chi tiết bảng lương</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-8 text-gray-400 text-xs">Chưa có kỳ tính lương nào trong hệ thống.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($periods->hasPages())
                <div class="border-t border-gray-100 px-4 py-3">{{ $periods->links() }}</div>
            @endif
        </div>

    </div>
</x-app-layout>
