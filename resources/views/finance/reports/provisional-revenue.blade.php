<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-orange-100 flex items-center justify-center text-primary-container">
                    <span class="material-symbols-outlined text-2xl font-semibold">query_stats</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl lg:text-2xl font-bold text-slate-800 tracking-tight">Báo cáo Doanh thu tạm tính</h1>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            Tính toán thời gian thực
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">Tổng hợp đối soát Thu (học phí &amp; phụ thu hợp lệ) trừ Chi vận hành (chi thường xuyên &amp; chi lương)</p>
                </div>
            </div>

            <!-- Filters & Actions -->
            <form id="revenueFilterForm" method="GET" action="{{ route('finance.reports.revenue') }}" class="flex flex-wrap items-center gap-3">
                <!-- Filter Kỳ tháng -->
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm focus-within:ring-2 focus-within:ring-primary-container/20 focus-within:border-primary-container transition-all">
                    <span class="material-symbols-outlined text-slate-400 text-lg mr-2">calendar_month</span>
                    <label for="filter-month" class="text-xs font-semibold text-slate-500 mr-2 uppercase">KỲ THÁNG:</label>
                    <select id="filter-month" name="month" onchange="this.form.submit()" class="bg-transparent font-bold text-slate-800 focus:outline-none cursor-pointer text-sm">
                        @foreach ($monthOptions as $val => $lbl)
                            <option value="{{ $val }}" {{ $month === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Filter Chi nhánh -->
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm focus-within:ring-2 focus-within:ring-primary-container/20 focus-within:border-primary-container transition-all">
                    <span class="material-symbols-outlined text-slate-400 text-lg mr-2">storefront</span>
                    <label for="filter-branch" class="text-xs font-semibold text-slate-500 mr-2 uppercase">CƠ SỞ:</label>
                    <select id="filter-branch" name="branch_id" onchange="this.form.submit()" class="bg-transparent font-bold text-slate-800 focus:outline-none cursor-pointer text-sm">
                        @unless ($branchScoped ?? false)<option value="all" {{ $branchId === 'all' ? 'selected' : '' }}>Tất cả chi nhánh</option>@endunless
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ (string)$branchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Nút Làm mới -->
                <button type="button" onclick="window.location.reload()" class="inline-flex items-center justify-center p-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 transition-colors shadow-xs" title="Làm mới số liệu thời gian thực">
                    <span class="material-symbols-outlined text-xl">sync</span>
                </button>

                <!-- Nút Xuất báo cáo -->
                <a href="{{ route('finance.reports.revenue.export', ['month' => $month]) }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-all shadow-xs">
                    <span class="material-symbols-outlined text-lg">download</span>
                    <span>Xuất báo cáo</span>
                </a>
            </form>
        </div>
    </x-slot>

    <div class="space-y-6">

        <!-- 2. Thẻ 3 Số Liệu Cốt Lõi: THU - CHI - DOANH THU TẠM TÍNH -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <!-- THẺ 1: TỔNG THU THỰC TẾ -->
            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between group hover:border-emerald-300 transition-all">
                <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-50 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none"></div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            Tổng thu thực tế
                        </span>
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">payments</span>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">{{ number_format($totalRevenue, 0, ',', '.') }}</span>
                        <span class="text-base font-bold text-slate-500">VNĐ</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium {{ $revenueDiffIsUp ? 'text-emerald-600' : 'text-rose-600' }}">
                        <span class="material-symbols-outlined text-sm font-bold">{{ $revenueDiffIsUp ? 'trending_up' : 'trending_down' }}</span>
                        <span>{{ $revenueDiffIsUp ? 'Tăng' : 'Giảm' }} {{ $revenueDiffPercent }}% so với tháng {{ \Carbon\Carbon::createFromFormat('Y-m', $prevMonth)->format('m/Y') }}</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-slate-400">receipt_long</span>
                        Gồm {{ $validReceiptsCount }} phiếu thu hợp lệ
                    </span>
                    <span class="text-slate-400 italic">Đã gồm phụ thu</span>
                </div>
            </div>

            <!-- THẺ 2: CHI PHÍ VẬN HÀNH -->
            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between group hover:border-rose-300 transition-all">
                <div class="absolute top-0 right-0 w-32 h-32 bg-rose-50 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none"></div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                            Chi phí vận hành
                        </span>
                        <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">receipt</span>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">{{ number_format($totalExpense, 0, ',', '.') }}</span>
                        <span class="text-base font-bold text-slate-500">VNĐ</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium text-slate-500">
                        <span class="material-symbols-outlined text-sm text-slate-400">info</span>
                        <span>Khoản tự nhập + Lương kỳ {{ \Carbon\Carbon::createFromFormat('Y-m', $month)->format('m/Y') }}</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                    <span class="text-rose-600 font-medium">Chiếm {{ $expensePercentageOfRevenue }}% tổng thu</span>
                    <span class="text-slate-400">{{ $totalExpenseItemsCount }} mục chi</span>
                </div>
            </div>

            <!-- THẺ 3: DOANH THU TẠM TÍNH (NỔI BẬT NHẤT = THU - CHI) -->
            <div class="bg-gradient-to-br from-primary-container via-primary-container to-sidebar rounded-2xl p-6 text-white shadow-lg shadow-orange-500/15 relative overflow-hidden flex flex-col justify-between border border-orange-400/30">
                <!-- Glow accents -->
                <div class="absolute -right-8 -bottom-8 w-40 h-40 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute -left-6 -top-6 w-32 h-32 bg-orange-300/20 rounded-full blur-xl pointer-events-none"></div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/20 backdrop-blur-xs text-xs font-bold uppercase tracking-wider text-orange-100">
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
                        <span class="text-base font-bold text-orange-200">VNĐ</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium text-orange-100/90">
                        <span class="material-symbols-outlined text-sm">calculate</span>
                        <span>= Tổng thu ({{ number_format($totalRevenue / 1000000, 1) }}M) − Chi vận hành ({{ number_format($totalExpense / 1000000, 2) }}M)</span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-white/20 flex items-center justify-between text-xs text-orange-100">
                    <span class="font-semibold">Tỷ suất lợi nhuận gộp ước tính:</span>
                    <span class="text-sm font-black text-white bg-white/20 px-2 py-0.5 rounded-md">{{ $grossProfitMargin }}%</span>
                </div>
            </div>

        </div>

        <!-- 3. Chi tiết Bóc tách Nguồn thu & Khoản chi (Breakdown Panel) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Cột Trái: Bóc tách Nguồn Thu -->
            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">pie_chart</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-base">Cơ cấu Nguồn Thu</h3>
                            <p class="text-xs text-slate-500">Tất cả phiếu thu còn hiệu lực (đã loại bỏ phiếu bị hủy hóa đơn)</p>
                        </div>
                    </div>
                    <span class="text-sm font-bold text-emerald-600">{{ number_format($totalRevenue, 0, ',', '.') }} đ</span>
                </div>

                <div class="space-y-3 pt-1">
                    <!-- Item 1: Học phí chính thức -->
                    <div class="p-3.5 rounded-xl bg-slate-50/80 border border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                            <div>
                                <p class="text-sm font-semibold text-slate-800">Học phí các khóa học</p>
                                <p class="text-xs text-slate-500">Gồm khóa IELTS Foundation, Speaking Master, v.v.</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-slate-800">{{ number_format($tuitionRevenue, 0, ',', '.') }} đ</p>
                            <p class="text-xs text-slate-400 font-medium">{{ $tuitionPercent }}%</p>
                        </div>
                    </div>

                    <!-- Item 2: Phụ thu phát sinh ngoài học phí -->
                    <div class="p-3.5 rounded-xl bg-slate-50/80 border border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-teal-500"></div>
                            <div>
                                <p class="text-sm font-semibold text-slate-800">Phụ thu phát sinh</p>
                                <p class="text-xs text-slate-500">Giáo trình in ấn bổ sung, lệ phí thi thử, thẻ học viên</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-slate-800">{{ number_format($surchargeRevenue, 0, ',', '.') }} đ</p>
                            <p class="text-xs text-slate-400 font-medium">{{ $surchargePercent }}%</p>
                        </div>
                    </div>

                    <!-- Phân theo hình thức thanh toán -->
                    <div class="pt-2">
                        <div class="flex justify-between text-xs font-semibold text-slate-600 mb-1.5">
                            <span>Hình thức: Chuyển khoản ({{ $revenueTransferPercent }}%)</span>
                            <span>Tiền mặt ({{ $revenueCashPercent }}%)</span>
                        </div>
                        <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden flex">
                            <div class="bg-emerald-500 h-full rounded-full transition-all" style="width: {{ $revenueTransferPercent }}%"></div>
                            <div class="bg-teal-400 h-full rounded-full transition-all" style="width: {{ $revenueCashPercent }}%"></div>
                        </div>
                    </div>
                </div>

                <div class="bg-emerald-50/60 rounded-xl p-3 border border-emerald-100 text-xs text-emerald-800 flex items-start gap-2">
                    <span class="material-symbols-outlined text-base text-emerald-600 shrink-0 mt-0.5">verified</span>
                    <div>
                        <p class="font-semibold">Nguyên tắc ghi nhận doanh thu:</p>
                        <p class="text-emerald-700/90 mt-0.5">Mọi phiếu ở trạng thái "Chờ duyệt", "Đã duyệt" hoặc "Tạm thu" đều được cộng dồn. Phiếu bị <strong class="underline">Hủy hóa đơn</strong> tự động bị loại trừ ngay lập tức.</p>
                    </div>
                </div>
            </div>

            <!-- Cột Phải: Bóc tách Chi Vận Hành -->
            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">donut_small</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-base">Cơ cấu Chi Vận Hành</h3>
                            <p class="text-xs text-slate-500">Khoản chi tự nhập thực tế + Chi lương tự động từ Epic 7</p>
                        </div>
                    </div>
                    <span class="text-sm font-bold text-rose-600">{{ number_format($totalExpense, 0, ',', '.') }} đ</span>
                </div>

                <div class="space-y-3 pt-1">
                    <!-- Chi Lương (Tự động từ Epic 7) -->
                    <div class="p-3.5 rounded-xl bg-blue-50/70 border border-blue-200/70 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-base">lock</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-bold text-blue-900">Chi trả lương nhân sự (Kỳ {{ \Carbon\Carbon::createFromFormat('Y-m', $month)->format('m/Y') }})</p>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-200 text-blue-800 uppercase tracking-tight">Tự động</span>
                                </div>
                                <p class="text-xs text-blue-700/80">Tổng thực nhận của toàn bộ GV, TA, Học vụ đã chốt bảng</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-black text-blue-900">{{ number_format($salaryTotal, 0, ',', '.') }} đ</p>
                            <p class="text-xs text-blue-600 font-semibold">{{ $salaryPercentOfExpense }}% chi phí</p>
                        </div>
                    </div>

                    <!-- Chi Mặt bằng & Tiện ích -->
                    <div class="p-3.5 rounded-xl bg-slate-50/80 border border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-rose-400"></div>
                            <div>
                                <p class="text-sm font-semibold text-slate-800">Mặt bằng &amp; Tiện ích</p>
                                <p class="text-xs text-slate-500">Tiền thuê trụ sở, điện nước, internet cáp quang</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-slate-800">{{ number_format($rentUtilitiesExpense, 0, ',', '.') }} đ</p>
                            <p class="text-xs text-slate-400 font-medium">{{ $rentPercentOfExpense }}%</p>
                        </div>
                    </div>

                    <!-- Chi Giáo trình & Vận hành lớp -->
                    <div class="p-3.5 rounded-xl bg-slate-50/80 border border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full bg-amber-400"></div>
                            <div>
                                <p class="text-sm font-semibold text-slate-800">In ấn giáo trình &amp; Vận hành lớp</p>
                                <p class="text-xs text-slate-500">Sách bổ trợ IELTS, nước uống, bảo dưỡng điều hòa</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-slate-800">{{ number_format($curriculumOperationsExpense, 0, ',', '.') }} đ</p>
                            <p class="text-xs text-slate-400 font-medium">{{ $curriculumPercentOfExpense }}%</p>
                        </div>
                    </div>
                </div>

                <div class="bg-blue-50/60 rounded-xl p-3 border border-blue-100 text-xs text-blue-800 flex items-start gap-2">
                    <span class="material-symbols-outlined text-base text-blue-600 shrink-0 mt-0.5">info</span>
                    <div>
                        <p class="font-semibold">Quy tắc chuẩn hóa kỳ lương:</p>
                        <p class="text-blue-700/90 mt-0.5">Chi lương tự động tính theo <strong class="underline">kỳ lương khớp tháng đã chọn</strong>, kể cả khi ngày thanh toán thực tế lệch sang tháng dương lịch tiếp theo.</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- 4. Thống kê theo Chi nhánh (Branch Matrix) -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h3 class="font-bold text-slate-800 text-base">So sánh Hiệu quả Doanh thu giữa các Chi nhánh</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Tổng hợp đối soát chi tiết theo từng cơ sở đào tạo</p>
                </div>
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-600 bg-slate-100/80 px-3 py-1.5 rounded-lg">
                    <span class="material-symbols-outlined text-sm text-slate-500">domain</span>
                    <span>Đang hiển thị {{ count($branchMatrix) }} chi nhánh đang hoạt động</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50/80 text-slate-600 font-semibold border-b border-slate-200 text-xs uppercase tracking-wider">
                            <th class="py-3.5 px-6">Chi nhánh / Cơ sở</th>
                            <th class="py-3.5 px-6 text-right">Tổng thu</th>
                            <th class="py-3.5 px-6 text-right">Chi vận hành</th>
                            <th class="py-3.5 px-6 text-right">Doanh thu tạm tính</th>
                            <th class="py-3.5 px-6 text-center">Tỷ suất gộp</th>
                            <th class="py-3.5 px-6 text-center">Tình trạng</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($branchMatrix as $row)
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="py-4 px-6 font-semibold text-slate-800">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-2 h-2 rounded-full bg-primary-container"></span>
                                        <span>{{ $row['branch']->name }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-right font-bold text-emerald-600">
                                    {{ number_format($row['revenue'], 0, ',', '.') }} đ
                                </td>
                                <td class="py-4 px-6 text-right font-semibold text-rose-600">
                                    {{ number_format($row['expense'], 0, ',', '.') }} đ
                                </td>
                                <td class="py-4 px-6 text-right font-extrabold text-slate-900">
                                    {{ $row['profit'] >= 0 ? '+' : '' }}{{ number_format($row['profit'], 0, ',', '.') }} đ
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold {{ $row['status']['badge_bg'] }}">
                                        {{ $row['margin'] }}%
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $row['status']['class'] }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $row['status']['dot'] }}"></span>
                                        {{ $row['status']['label'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-50 font-bold border-t-2 border-slate-200 text-slate-900">
                            <td class="py-4 px-6 uppercase text-xs tracking-wider">Tổng cộng toàn hệ thống</td>
                            <td class="py-4 px-6 text-right text-emerald-600 text-base font-extrabold">{{ number_format($totalMatrixRevenue, 0, ',', '.') }} đ</td>
                            <td class="py-4 px-6 text-right text-rose-600 text-base font-extrabold">{{ number_format($totalMatrixExpense, 0, ',', '.') }} đ</td>
                            <td class="py-4 px-6 text-right text-primary-container text-base font-black">{{ $totalMatrixProfit >= 0 ? '+' : '' }}{{ number_format($totalMatrixProfit, 0, ',', '.') }} đ</td>
                            <td class="py-4 px-6 text-center text-xs font-black">{{ $totalMatrixMargin }}%</td>
                            <td class="py-4 px-6 text-center text-xs font-semibold text-emerald-700">Tăng trưởng</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 5. Ghi chú quy tắc nghiệp vụ cho Quản trị viên (Chị Liên) -->
        <div class="bg-amber-50/70 rounded-2xl p-4 md:p-5 border border-amber-200/80 text-xs text-amber-900 space-y-2">
            <div class="flex items-center gap-2 text-amber-800 font-bold text-sm">
                <span class="material-symbols-outlined text-lg">lightbulb</span>
                <span>Quy tắc vận hành &amp; Tính toán số liệu Doanh thu tạm tính (Real-time):</span>
            </div>
            <ul class="list-disc list-inside space-y-1 text-amber-900/90 pl-1">
                <li><strong>Nguồn Thu:</strong> Tổng hợp toàn bộ phiếu thu còn hiệu lực (bao gồm cả học phí khóa học và phụ thu) phát sinh trong tháng đang lọc. Phiếu bị Admin duyệt <em>"Đã hủy"</em> hóa đơn sẽ tự động bị loại bỏ ngay lập tức khỏi tổng thu.</li>
                <li><strong>Nguồn Chi vận hành:</strong> Tổng hợp từ 2 nguồn: các khoản chi quản lý tự nhập trực tiếp tại Sổ khoản chi + Khoản chi lương tự động <code>SUM(thuc_nhan)</code> lấy từ các bảng lương có <code>ky_luong</code> khớp với tháng đã chọn (không phụ thuộc vào ngày chuyển tiền thực tế).</li>
                <li><strong>Cập nhật tức thì (Không cache):</strong> Khi Quản trị viên thay đổi bộ lọc kỳ tháng hoặc chuyển đổi giữa các chi nhánh, 3 chỉ số chính sẽ được hệ thống tính toán lại tức thì để đảm bảo tính minh bạch và chuẩn xác tuyệt đối.</li>
            </ul>
        </div>

    </div>
</x-app-layout>
