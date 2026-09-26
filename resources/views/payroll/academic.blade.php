<x-app-layout>
    <x-ui.page-header title="Chi Tiết Bảng Lương Khối Học Thuật (R&D / Khảo Thí)" icon="school" :back="route('payroll.periods.show', $period->id)">
        <x-slot:badges>
            <span class="text-xs px-2.5 py-0.5 rounded-full border font-bold {{ $period->status_badge }}">
                {{ $period->status_label }}
            </span>
        </x-slot:badges>
        <x-slot:meta>Kỳ tính lương: {{ $period->title }} ({{ $period->code }})</x-slot:meta>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="route('payroll.periods.export', [$period->id, 'department' => 'academic'])">Xuất Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">

        {{-- Navigation Sub-tabs --}}
        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 text-xs">
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">groups</span>
                <span>Toàn bộ / GV Part-time</span>
            </a>
            <a href="{{ route('payroll.periods.fulltime', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">work</span>
                <span>Giáo viên Full-time</span>
            </a>
            <a href="{{ route('payroll.periods.academic', $period->id) }}" class="px-4 py-2 font-bold rounded-xl bg-orange-600 text-white shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">school</span>
                <span>Khối Học Thuật</span>
            </a>
            <a href="{{ route('payroll.periods.operations', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">support_agent</span>
                <span>Khối Học Vụ &amp; Vận Hành</span>
            </a>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            
            {{-- Left Column: Academic Salary Records (Col 8) --}}
            <div class="xl:col-span-8 space-y-6">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-orange-600 text-base">psychology</span>
                            <span>Danh Sách Nhân Sự Khối Học Thuật</span>
                        </h3>
                        <span class="text-xs font-bold text-gray-500 font-mono">{{ $records->count() }} nhân sự</span>
                    </div>

                    @include('payroll.partials.fulltime-table', ['records' => $records, 'emptyText' => 'Chưa có bản ghi lương nhân sự khối học thuật trong kỳ này.', 'avatarClass' => 'bg-purple-100 text-purple-700', 'showCommission' => false, 'showRenewal' => true])
                </div>

                {{-- Academic Role Guidelines Reference --}}
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-3 text-xs">
                    <h4 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-orange-600 text-base">menu_book</span>
                        <span>Cơ Cấu Thu Nhập Khối Học Thuật</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">1. Lương cơ bản &amp; khấu trừ</span>
                            <p class="text-gray-500 text-[11px]">BHXH, Công đoàn tự động trên lương cơ bản; thuế TNCN Admin nhập tay; trừ vi phạm quá hạn nộp.</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">2. KPI (nhập tự do)</span>
                            <p class="text-gray-500 text-[11px]">Học thuật: Admin / Kế toán nhập số tiền KPI trên phiếu lương.</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">3. Phụ cấp &amp; thưởng tái tục</span>
                            <p class="text-gray-500 text-[11px]">Phụ cấp / thưởng là các dòng tự do có tên. Thưởng tái tục nếu phụ trách lớp.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right Column: Summary Card (Col 4) --}}
            <div class="xl:col-span-4 space-y-4">
                <div class="bg-gradient-to-br from-indigo-900 to-purple-900 text-white rounded-2xl p-6 shadow-md space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-purple-200">Tổng chi Khối Học Thuật</span>
                        <span class="material-symbols-outlined text-2xl text-purple-300">school</span>
                    </div>
                    <div>
                        <div class="text-3xl font-black font-mono tracking-tight">
                            {{ number_format($records->sum('net_salary')) }}đ
                        </div>
                        <p class="text-xs text-purple-200 mt-1">{{ $records->count() }} chuyên viên học thuật</p>
                    </div>
                    <div class="pt-3 border-t border-white/20 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-purple-300 block text-[10px] uppercase font-bold">Lương cứng:</span>
                            <span class="font-bold font-mono text-sm">{{ number_format($records->sum('base_salary')) }}đ</span>
                        </div>
                        <div>
                            <span class="text-purple-300 block text-[10px] uppercase font-bold">KPI &amp; Phụ cấp:</span>
                            <span class="font-bold font-mono text-sm">{{ number_format($records->sum('kpi_bonus') + $records->sum('allowance')) }}đ</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</x-app-layout>
