{{-- Mockup: ui-full-tinh-nang-menglish/epic-13-bao-cao-thu-chi/b_o_c_o_doanh_thu_t_m_t_nh_menglish_admin --}}
<x-app-layout title="Báo cáo doanh thu tạm tính">
    <x-ui.page-header title="Báo cáo Doanh thu tạm tính" icon="query_stats" description="Tổng hợp đối soát Thu (học phí & phụ thu hợp lệ) trừ Chi vận hành (chi thường xuyên & chi lương)">
        <x-slot:badges>
            <x-ui.badge color="success" :pill="true">Tính toán thời gian thực</x-ui.badge>
        </x-slot:badges>
        <x-slot:actions>
        {{-- Filters & Actions --}}
        <form id="revenueFilterForm" method="GET" action="{{ route('finance.reports.revenue') }}" class="flex flex-wrap items-center gap-3">
            {{-- Filter Kỳ tháng --}}
            <x-ui.select id="filter-month" name="month" inline-label="KỲ THÁNG:" onchange="this.form.submit()" :value="$month" :options="$monthOptions" />

            {{-- Filter Chi nhánh --}}
            <x-ui.select id="filter-branch" name="branch_id" inline-label="CƠ SỞ:" onchange="this.form.submit()">
                @unless ($branchScoped ?? false)<option value="all" {{ $branchId === 'all' ? 'selected' : '' }}>Tất cả chi nhánh</option>@endunless
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$branchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </x-ui.select>

            {{-- Nút Làm mới --}}
            <x-ui.button variant="secondary" icon="sync" onclick="window.location.reload()" title="Làm mới số liệu thời gian thực" />

            {{-- Nút Xuất báo cáo --}}
            <x-ui.button variant="secondary" icon="download" :href="route('finance.reports.revenue.export', ['month' => $month, 'branch_id' => $branchId])">Xuất báo cáo</x-ui.button>
        </form>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">

        {{-- 2. Thẻ 3 Số Liệu Cốt Lõi: THU - CHI - DOANH THU TẠM TÍNH --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            {{-- THẺ 1: TỔNG THU THỰC TẾ --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest/80 shadow-xs relative overflow-hidden flex flex-col justify-between group hover:border-tertiary/30 transition-all">
                <div class="absolute top-0 right-0 w-32 h-32 bg-tertiary/10 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none"></div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-tertiary"></span>
                            Tổng thu thực tế
                        </span>
                        <div class="w-9 h-9 rounded-xl bg-tertiary/10 text-tertiary flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">payments</span>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl lg:text-4xl font-extrabold text-on-surface tracking-tight">{{ number_format($totalRevenue, 0, ',', '.') }}</span>
                        <span class="text-base font-bold text-on-surface-variant">VNĐ</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium {{ $revenueDiffIsUp ? 'text-tertiary' : 'text-error' }}">
                        <span class="material-symbols-outlined text-sm font-bold">{{ $revenueDiffIsUp ? 'trending_up' : 'trending_down' }}</span>
                        <span>{{ $revenueDiffIsUp ? 'Tăng' : 'Giảm' }} {{ $revenueDiffPercent }}% so với tháng {{ \Carbon\Carbon::createFromFormat('Y-m', $prevMonth)->format('m/Y') }}</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-surface-container-highest flex items-center justify-between text-xs text-on-surface-variant">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-on-surface-variant/70">receipt_long</span>
                        Gồm {{ $validReceiptsCount }} phiếu thu hợp lệ
                    </span>
                    <span class="text-on-surface-variant/70 italic">Đã gồm phụ thu</span>
                </div>
            </div>

            {{-- THẺ 2: CHI PHÍ VẬN HÀNH --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest/80 shadow-xs relative overflow-hidden flex flex-col justify-between group hover:border-error/30 transition-all">
                <div class="absolute top-0 right-0 w-32 h-32 bg-error/10 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none"></div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-error"></span>
                            Chi phí vận hành
                        </span>
                        <div class="w-9 h-9 rounded-xl bg-error/10 text-error flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">receipt</span>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl lg:text-4xl font-extrabold text-on-surface tracking-tight">{{ number_format($totalExpense, 0, ',', '.') }}</span>
                        <span class="text-base font-bold text-on-surface-variant">VNĐ</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium text-on-surface-variant">
                        <span class="material-symbols-outlined text-sm text-on-surface-variant/70">info</span>
                        <span>Khoản tự nhập + Lương kỳ {{ \Carbon\Carbon::createFromFormat('Y-m', $month)->format('m/Y') }}</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-surface-container-highest flex items-center justify-between text-xs text-on-surface-variant">
                    <span class="text-error font-medium">Chiếm {{ $expensePercentageOfRevenue }}% tổng thu</span>
                    <span class="text-on-surface-variant/70">{{ $totalExpenseItemsCount }} mục chi</span>
                </div>
            </div>

            {{-- THẺ 3: DOANH THU TẠM TÍNH (NỔI BẬT NHẤT = THU - CHI) --}}
            <div class="bg-gradient-to-br from-primary-container via-primary-container to-sidebar rounded-2xl p-6 text-white shadow-lg shadow-primary-container/15 relative overflow-hidden flex flex-col justify-between border border-primary-container/30">
                {{-- Glow accents --}}
                <div class="absolute -right-8 -bottom-8 w-40 h-40 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute -left-6 -top-6 w-32 h-32 bg-primary-fixed-dim/20 rounded-full blur-xl pointer-events-none"></div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/20 backdrop-blur-xs text-xs font-bold uppercase tracking-wider text-primary-fixed">
                            <span class="material-symbols-outlined text-sm">stars</span>
                            Doanh thu tạm tính
                        </div>
                        <div class="w-9 h-9 rounded-xl bg-white/15 backdrop-blur-xs text-white flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-2 mt-1">
                        <span class="text-3xl lg:text-4xl font-black tracking-tight text-white drop-shadow-xs">
                            {{ $isProfitPositive ? '+' : '' }}{{ number_format($provisionalRevenue, 0, ',', '.') }}
                        </span>
                        <span class="text-base font-bold text-primary-fixed-dim">VNĐ</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium text-primary-fixed/90">
                        <span class="material-symbols-outlined text-sm">calculate</span>
                        <span>= Tổng thu ({{ number_format($totalRevenue / 1000000, 1) }}M) − Chi vận hành ({{ number_format($totalExpense / 1000000, 2) }}M)</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-white/20 flex items-center justify-between text-xs text-primary-fixed">
                    <span class="font-semibold">Tỷ suất lợi nhuận gộp ước tính:</span>
                    <span class="text-sm font-black text-white bg-white/20 px-2 py-0.5 rounded-md">{{ $grossProfitMargin }}%</span>
                </div>
            </div>

        </div>

        {{-- 3. Chi tiết Bóc tách Nguồn thu & Khoản chi (Breakdown Panel) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Cột Trái: Bóc tách Nguồn Thu --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest/80 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-surface-container-highest">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-tertiary/10 text-tertiary flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">pie_chart</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-on-surface text-base">Cơ cấu Nguồn Thu</h3>
                            <p class="text-xs text-on-surface-variant">Tất cả phiếu thu còn hiệu lực (đã loại bỏ phiếu bị hủy hóa đơn)</p>
                        </div>
                    </div>
                    <x-ui.money :value="$totalRevenue" tone="success" class="font-bold" />
                </div>

                <div class="space-y-3 pt-1">
                    {{-- Item 1: Học phí chính thức --}}
                    <div class="p-3.5 rounded-xl bg-surface-container-low/80 border border-surface-container-highest flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Học phí các khóa học</p>
                                <p class="text-xs text-on-surface-variant">Phần học phí của các phiếu thu đã duyệt trong tháng</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <x-ui.money :value="$tuitionRevenue" class="text-sm font-bold" />
                            <p class="text-xs text-on-surface-variant/70 font-medium">{{ $tuitionPercent }}%</p>
                        </div>
                    </div>

                    {{-- Item 2: Phụ thu phát sinh ngoài học phí --}}
                    <div class="p-3.5 rounded-xl bg-surface-container-low/80 border border-surface-container-highest flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-teal-500"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Phụ thu phát sinh</p>
                                <p class="text-xs text-on-surface-variant">Giáo trình in ấn bổ sung, lệ phí thi thử, thẻ học viên</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <x-ui.money :value="$surchargeRevenue" class="text-sm font-bold" />
                            <p class="text-xs text-on-surface-variant/70 font-medium">{{ $surchargePercent }}%</p>
                        </div>
                    </div>

                    {{-- Phân theo hình thức thanh toán --}}
                    <div class="pt-2">
                        <div class="flex justify-between text-xs font-semibold text-on-surface-variant mb-1.5">
                            <span>Hình thức: Chuyển khoản ({{ $revenueTransferPercent }}%)</span>
                            <span>Tiền mặt ({{ $revenueCashPercent }}%)</span>
                        </div>
                        <div class="w-full bg-surface-container h-2 rounded-full overflow-hidden flex">
                            <div class="bg-emerald-500 h-full rounded-full transition-all" style="width: {{ $revenueTransferPercent }}%"></div>
                            <div class="bg-teal-400 h-full rounded-full transition-all" style="width: {{ $revenueCashPercent }}%"></div>
                        </div>
                    </div>
                </div>

                <x-ui.alert type="success" title="Nguyên tắc ghi nhận doanh thu:" class="text-xs">
                    <p>Chỉ cộng phiếu thu <strong>đã duyệt</strong> theo ngày thu (phiếu nháp / chờ duyệt / bị từ chối chưa là doanh thu). Phiếu bị <strong class="underline">Hủy hóa đơn</strong> tự động bị loại trừ; phiếu hoàn phí / chuyển nhượng (số âm) được trừ vào tổng thu.</p>
                </x-ui.alert>
            </div>

            {{-- Cột Phải: Bóc tách Chi Vận Hành --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest/80 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-surface-container-highest">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-error/10 text-error flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">donut_small</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-on-surface text-base">Cơ cấu Chi Vận Hành</h3>
                            <p class="text-xs text-on-surface-variant">Khoản chi tự nhập thực tế + Chi lương tự động từ bảng lương đã chốt</p>
                        </div>
                    </div>
                    <x-ui.money :value="$totalExpense" tone="error" class="font-bold" />
                </div>

                <div class="space-y-3 pt-1">
                    {{-- Chi Lương (tự động từ bảng lương đã chốt) --}}
                    <div class="p-3.5 rounded-xl bg-secondary/5 border border-secondary/20 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-secondary/10 text-secondary flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-base">lock</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-bold text-on-secondary-fixed">Chi trả lương nhân sự (Kỳ {{ \Carbon\Carbon::createFromFormat('Y-m', $month)->format('m/Y') }})</p>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-secondary/20 text-on-secondary-fixed uppercase tracking-tight">Tự động</span>
                                </div>
                                <p class="text-xs text-secondary/80">Tổng thực nhận của toàn bộ GV, TA, Học vụ đã chốt bảng</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-black text-on-secondary-fixed">{{ number_format($salaryTotal, 0, ',', '.') }} đ</p>
                            <p class="text-xs text-secondary font-semibold">{{ $salaryPercentOfExpense }}% chi phí</p>
                        </div>
                    </div>

                    {{-- Chi Mặt bằng & Tiện ích --}}
                    <div class="p-3.5 rounded-xl bg-surface-container-low/80 border border-surface-container-highest flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-rose-400"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Mặt bằng &amp; Tiện ích</p>
                                <p class="text-xs text-on-surface-variant">Tiền thuê trụ sở, điện nước, internet cáp quang</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <x-ui.money :value="$rentUtilitiesExpense" class="text-sm font-bold" />
                            <p class="text-xs text-on-surface-variant/70 font-medium">{{ $rentPercentOfExpense }}%</p>
                        </div>
                    </div>

                    {{-- Chi Giáo trình & Vận hành lớp --}}
                    <div class="p-3.5 rounded-xl bg-surface-container-low/80 border border-surface-container-highest flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-amber-400"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">In ấn giáo trình &amp; Vận hành lớp</p>
                                <p class="text-xs text-on-surface-variant">Sách bổ trợ, văn phòng phẩm, nước uống, bảo dưỡng thiết bị</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <x-ui.money :value="$curriculumOperationsExpense" class="text-sm font-bold" />
                            <p class="text-xs text-on-surface-variant/70 font-medium">{{ $curriculumPercentOfExpense }}%</p>
                        </div>
                    </div>

                    {{-- Chi khác --}}
                    <div class="p-3.5 rounded-xl bg-surface-container-low/80 border border-surface-container-highest flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-slate-400"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Chi khác</p>
                                <p class="text-xs text-on-surface-variant">Các khoản chi không thuộc 2 nhóm trên</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <x-ui.money :value="$otherExpense" class="text-sm font-bold" />
                            <p class="text-xs text-on-surface-variant/70 font-medium">{{ $totalExpense > 0 ? round($otherExpense / $totalExpense * 100, 1) : 0 }}%</p>
                        </div>
                    </div>
                </div>

                <x-ui.alert type="info" title="Quy tắc chuẩn hóa kỳ lương:" class="text-xs">
                    <p>Chi lương tự động tính theo <strong class="underline">kỳ lương khớp tháng đã chọn</strong>, kể cả khi ngày thanh toán thực tế lệch sang tháng dương lịch tiếp theo.</p>
                </x-ui.alert>
            </div>

        </div>

        {{-- 4. Thống kê theo Chi nhánh (Branch Matrix) --}}
        <x-ui.data-table>
            <x-slot:header>
                <div>
                    <h3 class="font-bold text-on-surface text-base">So sánh Hiệu quả Doanh thu giữa các Chi nhánh</h3>
                    <p class="text-xs text-on-surface-variant mt-0.5">Tổng hợp đối soát chi tiết theo từng cơ sở đào tạo</p>
                </div>
                <div class="flex items-center gap-2 text-xs font-semibold text-on-surface-variant bg-surface-container/80 px-3 py-1.5 rounded-lg">
                    <span class="material-symbols-outlined text-sm text-on-surface-variant">domain</span>
                    <span>Đang hiển thị {{ count($branchMatrix) }} chi nhánh đang hoạt động</span>
                </div>
            </x-slot:header>

                <table class="text-sm">
                    <thead>
                        <tr>
                            <th>Chi nhánh / Cơ sở</th>
                            <th class="text-right">Tổng thu</th>
                            <th class="text-right">Chi vận hành</th>
                            <th class="text-right">Doanh thu tạm tính</th>
                            <th class="text-center">Tỷ suất gộp</th>
                            <th class="text-center">Tình trạng</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($branchMatrix as $row)
                            <tr>
                                <td class="font-semibold">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-2 h-2 rounded-full bg-primary-container"></span>
                                        <span>{{ $row['branch']->name }}</span>
                                    </div>
                                </td>
                                <td class="text-right font-bold">
                                    <x-ui.money :value="$row['revenue']" tone="success" />
                                </td>
                                <td class="text-right font-semibold">
                                    <x-ui.money :value="$row['expense']" tone="error" />
                                </td>
                                <td class="text-right font-extrabold">
                                    <x-ui.money :value="$row['profit']" :sign="true" />
                                </td>
                                <td class="text-center">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold {{ $row['status']['badge_bg'] }}">
                                        {{ $row['margin'] }}%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $row['status']['class'] }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $row['status']['dot'] }}"></span>
                                        {{ $row['status']['label'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-surface-container-low font-bold border-t-2 border-surface-container-highest text-on-surface">
                            <td class="uppercase text-xs tracking-wider">{{ ($branchScoped ?? false) ? 'Tổng cộng' : 'Tổng cộng toàn hệ thống' }}</td>
                            <td class="text-right font-extrabold"><x-ui.money :value="$totalMatrixRevenue" tone="success" /></td>
                            <td class="text-right font-extrabold"><x-ui.money :value="$totalMatrixExpense" tone="error" /></td>
                            <td class="text-right font-black"><x-ui.money :value="$totalMatrixProfit" :sign="true" tone="primary" /></td>
                            <td class="text-center text-xs font-black">{{ $totalMatrixMargin }}%</td>
                            <td class="text-center text-xs font-semibold {{ $totalMatrixStatus['class'] }}">{{ $totalMatrixStatus['label'] }}</td>
                        </tr>
                    </tfoot>
                </table>
        </x-ui.data-table>

    </div>
</x-app-layout>
