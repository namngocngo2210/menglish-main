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
        <nav class="flex flex-wrap gap-sm border-b border-surface-container pb-sm" aria-label="Bảng lương theo khối">
            <x-ui.button variant="secondary" size="sm" icon="groups" :href="route('payroll.periods.show', $period->id)">Toàn bộ / GV Part-time</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="work" :href="route('payroll.periods.fulltime', $period->id)">Giáo viên Full-time</x-ui.button>
            <x-ui.button size="sm" icon="school" :href="route('payroll.periods.academic', $period->id)">Khối Học Thuật</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="support_agent" :href="route('payroll.periods.operations', $period->id)">Khối Học Vụ &amp; Vận Hành</x-ui.button>
        </nav>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            
            {{-- Left Column: Academic Salary Records (Col 8) --}}
            <div class="xl:col-span-8 space-y-6">
                <x-ui.data-table class="shadow-sm">
                    <x-slot:header>
                        <h3 class="font-bold text-xs uppercase tracking-wider text-on-surface flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-primary text-base">psychology</span>
                            <span>Danh Sách Nhân Sự Khối Học Thuật</span>
                        </h3>
                        <span class="text-xs font-bold text-on-surface-variant font-mono">{{ $records->count() }} nhân sự</span>
                    </x-slot:header>
                    @include('payroll.partials.fulltime-table', ['records' => $records, 'emptyText' => 'Chưa có bản ghi lương nhân sự khối học thuật trong kỳ này.', 'avatarClass' => 'bg-purple-100 text-purple-700', 'showCommission' => false, 'showRenewal' => true])
                </x-ui.data-table>

                {{-- Academic Role Guidelines Reference --}}
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-5 shadow-sm space-y-3 text-xs">
                    <h4 class="font-bold text-xs uppercase tracking-wider text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-base">menu_book</span>
                        <span>Cơ Cấu Thu Nhập Khối Học Thuật</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">1. Lương cơ bản &amp; khấu trừ</span>
                            <p class="text-on-surface-variant text-[11px]">BHXH, Công đoàn tự động trên lương cơ bản; thuế TNCN Admin nhập tay; trừ vi phạm quá hạn nộp.</p>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">2. KPI (nhập tự do)</span>
                            <p class="text-on-surface-variant text-[11px]">Học thuật: Admin / Kế toán nhập số tiền KPI trên phiếu lương.</p>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">3. Phụ cấp &amp; thưởng tái tục</span>
                            <p class="text-on-surface-variant text-[11px]">Phụ cấp / thưởng là các dòng tự do có tên. Thưởng tái tục nếu phụ trách lớp.</p>
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
