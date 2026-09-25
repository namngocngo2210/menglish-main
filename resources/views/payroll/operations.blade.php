<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.show', $period->id) }}" class="w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600">support_agent</span>
                        <span>Chi Tiết Bảng Lương Khối Học Vụ &amp; Vận Hành (CSKH / Sales)</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full border font-bold {{ $period->status_badge }}">
                            {{ $period->status_label }}
                        </span>
                    </h1>
                    <p class="text-xs text-gray-500">Kỳ tính lương: {{ $period->title }} ({{ $period->code }})</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('payroll.periods.export', [$period->id, 'department' => 'operations']) }}" class="px-3.5 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">download</span>
                    <span>Xuất Excel</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        <!-- Navigation Sub-tabs -->
        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 text-xs">
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
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
            <a href="{{ route('payroll.periods.operations', $period->id) }}" class="px-4 py-2 font-bold rounded-xl bg-orange-600 text-white shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">support_agent</span>
                <span>Khối Học Vụ &amp; Vận Hành</span>
            </a>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            
            <!-- Left Column: Operations Salary Records (Col 8) -->
            <div class="xl:col-span-8 space-y-6">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-orange-600 text-base">support_agent</span>
                            <span>Danh Sách Nhân Sự Học Vụ &amp; Vận Hành</span>
                        </h3>
                        <span class="text-xs font-bold text-gray-500 font-mono">{{ $records->count() }} nhân sự</span>
                    </div>

                    @include('payroll.partials.fulltime-table', ['records' => $records, 'emptyText' => 'Chưa có bản ghi lương nhân sự học vụ trong kỳ này.', 'avatarClass' => 'bg-blue-100 text-blue-700', 'showCommission' => true, 'showRenewal' => false])
                </div>

                <!-- Operations Commission Guidelines -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-3 text-xs">
                    <h4 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-orange-600 text-base">receipt_long</span>
                        <span>Cách tính hoa hồng khối vận hành</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        {{-- Theo SalesCommissionService / cấu hình mốc hoa hồng; không ghi cứng tỷ lệ ở đây. --}}
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">1. Hoa hồng tuyển mới</span>
                            <p class="text-gray-500 text-[11px]">% theo bậc số HS chốt trong kỳ (mặc định 3% / 4% / 5%) × tiền thực thu của khách mới. Chỉ trả khi đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc; chưa đủ thì hoãn sang kỳ sau.
                                @can('commission_config.manage')<a href="{{ route('payroll.config.commission-tiers') }}" class="text-primary font-semibold hover:underline">Xem mốc hoa hồng</a>@endcan
                            </p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">2. KPI Học vụ (tự động)</span>
                            <p class="text-gray-500 text-[11px]">Quỹ KPI × điểm KPI 6 nhóm / 15 mục của đánh giá tháng đã chốt. Nhân viên không tự chấm. Vận hành khác: KPI nhập tay.</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">3. Thu hồi hoa hồng</span>
                            <p class="text-gray-500 text-[11px]">Khi hoàn phí có chọn thu hồi, khoản thu hồi được trừ ở lần tính lương kế tiếp.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Summary Card (Col 4) -->
            <div class="xl:col-span-4 space-y-4">
                <div class="bg-gradient-to-br from-blue-900 to-slate-900 text-white rounded-2xl p-6 shadow-md space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-blue-200">Tổng chi Khối Vận Hành</span>
                        <span class="material-symbols-outlined text-2xl text-blue-300">support_agent</span>
                    </div>
                    <div>
                        <div class="text-3xl font-black font-mono tracking-tight">
                            {{ number_format($records->sum('net_salary')) }}đ
                        </div>
                        <p class="text-xs text-blue-200 mt-1">{{ $records->count() }} nhân viên vận hành &amp; học vụ</p>
                    </div>
                    <div class="pt-3 border-t border-white/20 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-blue-300 block text-[10px] uppercase font-bold">Lương cứng:</span>
                            <span class="font-bold font-mono text-sm">{{ number_format($records->sum('base_salary')) }}đ</span>
                        </div>
                        <div>
                            <span class="text-blue-300 block text-[10px] uppercase font-bold">Tổng hoa hồng:</span>
                            <span class="font-bold font-mono text-sm">{{ number_format($records->sum('commission_bonus')) }}đ</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</x-app-layout>
