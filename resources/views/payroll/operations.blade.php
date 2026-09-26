<x-app-layout>
    <x-ui.page-header title="Chi Tiết Bảng Lương Khối Học Vụ & Vận Hành (CSKH / Sales)" icon="support_agent" :back="route('payroll.periods.show', $period->id)">
        <x-slot:badges>
            <span class="text-xs px-2.5 py-0.5 rounded-full border font-bold {{ $period->status_badge }}">
                {{ $period->status_label }}
            </span>
        </x-slot:badges>
        <x-slot:meta>Kỳ tính lương: {{ $period->title }} ({{ $period->code }})</x-slot:meta>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="route('payroll.periods.export', [$period->id, 'department' => 'operations'])">Xuất Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">

        {{-- Navigation Sub-tabs --}}
        <nav class="flex flex-wrap gap-sm border-b border-surface-container pb-sm" aria-label="Bảng lương theo khối">
            <x-ui.button variant="secondary" size="sm" icon="groups" :href="route('payroll.periods.show', $period->id)">Toàn bộ / GV Part-time</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="work" :href="route('payroll.periods.fulltime', $period->id)">Giáo viên Full-time</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="school" :href="route('payroll.periods.academic', $period->id)">Khối Học Thuật</x-ui.button>
            <x-ui.button size="sm" icon="support_agent" :href="route('payroll.periods.operations', $period->id)">Khối Học Vụ &amp; Vận Hành</x-ui.button>
        </nav>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            
            {{-- Left Column: Operations Salary Records (Col 8) --}}
            <div class="xl:col-span-8 space-y-6">
                <x-ui.data-table class="shadow-sm">
                    <x-slot:header>
                        <h3 class="font-bold text-xs uppercase tracking-wider text-on-surface flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-primary text-base">support_agent</span>
                            <span>Danh Sách Nhân Sự Học Vụ &amp; Vận Hành</span>
                        </h3>
                        <span class="text-xs font-bold text-on-surface-variant font-mono">{{ $records->count() }} nhân sự</span>
                    </x-slot:header>
                    @include('payroll.partials.fulltime-table', ['records' => $records, 'emptyText' => 'Chưa có bản ghi lương nhân sự học vụ trong kỳ này.', 'avatarClass' => 'bg-secondary/10 text-secondary', 'showCommission' => true, 'showRenewal' => false])
                </x-ui.data-table>

                {{-- Operations Commission Guidelines --}}
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-5 shadow-sm space-y-3 text-xs">
                    <h4 class="font-bold text-xs uppercase tracking-wider text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-base">receipt_long</span>
                        <span>Cách tính hoa hồng khối vận hành</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        {{-- Theo SalesCommissionService / cấu hình mốc hoa hồng; không ghi cứng tỷ lệ ở đây. --}}
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">1. Hoa hồng tuyển mới</span>
                            <p class="text-on-surface-variant text-[11px]">% theo bậc số HS chốt trong kỳ (mặc định 3% / 4% / 5%) × tiền thực thu của khách mới. Chỉ trả khi đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc; chưa đủ thì hoãn sang kỳ sau.
                                @can('commission_config.manage')<a href="{{ route('payroll.config.commission-tiers') }}" class="text-primary font-semibold hover:underline">Xem mốc hoa hồng</a>@endcan
                            </p>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">2. KPI Học vụ (tự động)</span>
                            <p class="text-on-surface-variant text-[11px]">Quỹ KPI × điểm KPI 6 nhóm / 15 mục của đánh giá tháng đã chốt. Nhân viên không tự chấm. Vận hành khác: KPI nhập tay.</p>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">3. Thu hồi hoa hồng</span>
                            <p class="text-on-surface-variant text-[11px]">Khi hoàn phí có chọn thu hồi, khoản thu hồi được trừ ở lần tính lương kế tiếp.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right Column: Summary Card (Col 4) --}}
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
