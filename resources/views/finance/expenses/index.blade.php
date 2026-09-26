{{-- Mockup: ui-full-tinh-nang-menglish/epic-13-bao-cao-thu-chi/kho_n_chi_v_n_h_nh_menglish_admin --}}
<x-app-layout title="Sổ khoản chi vận hành">
    <x-ui.page-header title="Sổ khoản chi vận hành" icon="payments" description="Quản lý và ghi nhận các khoản chi phí hành chính, cơ sở vật chất và chi lương tự động">
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-600">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                Quyền thao tác: <span class="font-semibold text-slate-800">Quản trị nhân sự &amp; Tài chính</span>
            </div>
            <x-ui.button icon="add_circle" onclick="openCreateModal()">Thêm khoản chi mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">


        {{-- 1. Thống kê KPI tóm tắt 4 thẻ --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- Thẻ 1: Tổng chi kỳ này --}}
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs relative overflow-hidden">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Tổng chi kỳ này</span>
                    <span class="p-2 rounded-lg bg-orange-50 text-primary-container material-symbols-outlined text-[20px]">account_balance_wallet</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-slate-900 tracking-tight">
                        {{ number_format($grandTotalExpense, 0, ',', '.') }} <span class="text-sm font-semibold text-slate-400">VNĐ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-500">
                        @if ($percentDiff != 0)
                            <span class="{{ $isDecreased ? 'text-emerald-600' : 'text-rose-600' }} font-semibold flex items-center">
                                <span class="material-symbols-outlined text-[14px]">{{ $isDecreased ? 'arrow_downward' : 'arrow_upward' }}</span>
                                {{ abs($percentDiff) }}%
                            </span>
                            <span>so với tháng {{ \Carbon\Carbon::createFromFormat('Y-m', $prevMonth)->format('m/Y') }}</span>
                        @else
                            <span class="text-slate-400">Tương đương tháng trước</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Thẻ 2: Chi lương tự động (bảng lương) --}}
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Chi lương tự động (bảng lương)</span>
                    <span class="p-2 rounded-lg bg-blue-50 text-blue-600 material-symbols-outlined text-[20px]">badge</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-blue-900 tracking-tight">
                        {{ number_format($autoSalaryAmount, 0, ',', '.') }} <span class="text-sm font-semibold text-slate-400">VNĐ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-500">
                        @if ($autoSalaryAmount > 0)
                            <span class="inline-flex items-center gap-1 text-blue-600 font-medium">
                                <span class="material-symbols-outlined text-[14px]">sync_alt</span> Đã chốt &amp; Đã trả ({{ $autoSalaryStaffCount }} nhân sự)
                            </span>
                        @else
                            <span class="text-slate-400 italic">Chưa có bảng lương chốt</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Thẻ 3: Chi phí vận hành tự nhập --}}
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Chi phí vận hành tự nhập</span>
                    <span class="p-2 rounded-lg bg-emerald-50 text-emerald-600 material-symbols-outlined text-[20px]">shopping_bag</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-slate-900 tracking-tight">
                        {{ number_format($totalManualExpense, 0, ',', '.') }} <span class="text-sm font-semibold text-slate-400">VNĐ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-500">
                        <span>Tổng số <strong>{{ $manualExpensesCount }}</strong> phiếu chi tự nhập</span>
                    </div>
                </div>
            </div>

            {{-- Thẻ 4: Cơ cấu hình thức --}}
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Cơ cấu hình thức</span>
                    <span class="p-2 rounded-lg bg-purple-50 text-purple-600 material-symbols-outlined text-[20px]">credit_card</span>
                </div>
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-600">
                        <span>Chuyển khoản ({{ $transferPercent }}%)</span>
                        <span class="font-bold text-slate-800">{{ number_format($totalTransfer, 0, ',', '.') }}đ</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2 mt-1.5 overflow-hidden flex">
                        <div class="bg-purple-600 h-full rounded-full transition-all" style="width: {{ $transferPercent }}%"></div>
                        <div class="bg-amber-400 h-full rounded-full transition-all" style="width: {{ $cashPercent }}%"></div>
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                        <span>Tiền mặt: {{ number_format($totalCash, 0, ',', '.') }}đ ({{ $cashPercent }}%)</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Thanh bộ lọc & Tìm kiếm --}}
        <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
            <form id="filterForm" method="GET" action="{{ route('finance.expenses.index') }}" class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    {{-- Bộ lọc Kỳ tháng --}}
                    <div class="flex items-center gap-2">
                        <label for="monthSelect" class="text-xs font-semibold text-slate-500 uppercase whitespace-nowrap">Kỳ tháng:</label>
                        <div class="relative">
                            <select id="monthSelect" name="month" onchange="this.form.submit()" class="appearance-none bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-800 text-sm font-semibold rounded-xl pl-3 pr-8 py-2 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all cursor-pointer">
                                @foreach ($monthOptions as $val => $lbl)
                                    <option value="{{ $val }}" {{ $month === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-[18px]">expand_more</span>
                        </div>
                    </div>

                    {{-- Bộ lọc Chi nhánh --}}
                    <div class="flex items-center gap-2">
                        <label for="branchSelect" class="text-xs font-semibold text-slate-500 uppercase whitespace-nowrap">Chi nhánh:</label>
                        <div class="relative">
                            <select id="branchSelect" name="branch_id" onchange="this.form.submit()" class="appearance-none bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-800 text-sm font-medium rounded-xl pl-3 pr-8 py-2 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all cursor-pointer">
                                @unless ($branchScoped ?? false)<option value="all" {{ $branchId === 'all' ? 'selected' : '' }}>Tất cả chi nhánh</option>@endunless
                                @foreach ($branches as $b)
                                    <option value="{{ $b->id }}" {{ (string)$branchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                            <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-[18px]">expand_more</span>
                        </div>
                    </div>

                    <div class="hidden lg:flex items-center pl-2 border-l border-slate-200 gap-1 text-xs text-slate-500">
                        <span class="material-symbols-outlined text-[16px] text-slate-400">info</span>
                        <span>Dòng lương tự động ẩn khi kỳ chưa có bảng lương chốt/trả</span>
                    </div>
                </div>

                {{-- Tìm kiếm & Xuất Excel --}}
                <div class="flex items-center gap-2 w-full md:w-auto justify-end">
                    <div class="relative w-full sm:w-64">
                        <input type="text" name="search" value="{{ $search }}" placeholder="Tìm theo nội dung, người lập..." class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition-all">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[18px]">search</span>
                    </div>
                    <button type="submit" class="hidden"></button>
                    <a href="{{ route('finance.expenses.export', ['month' => $month, 'branch_id' => $branchId, 'search' => $search]) }}" title="Xuất dữ liệu Excel (CSV)" class="p-2 bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-600 rounded-xl transition-all inline-flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">download</span>
                    </a>
                </div>
            </form>
        </div>

        {{-- 3. Bảng dữ liệu các khoản chi --}}
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-bold text-slate-800">Danh sách các khoản chi</h2>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">{{ $totalItemsCount }} khoản chi</span>
                </div>
                <div class="text-xs text-slate-500 flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-xs bg-blue-100 border border-blue-300"></span> Dòng tự động tổng hợp từ hệ thống Lương
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-200 text-slate-500 text-xs font-bold uppercase tracking-wider">
                            <th class="py-3.5 px-4">Ngày chi</th>
                            <th class="py-3.5 px-4">Nội dung khoản chi</th>
                            <th class="py-3.5 px-4 text-right">Số tiền (VNĐ)</th>
                            <th class="py-3.5 px-4 text-center">Hình thức</th>
                            <th class="py-3.5 px-4">Chi nhánh</th>
                            <th class="py-3.5 px-4">Ghi chú &amp; Chứng từ</th>
                            <th class="py-3.5 px-4 text-center w-28">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">

                        {{-- DÒNG CHI LƯƠNG TỰ ĐỘNG (BẮT BUỘC KHÁC BIỆT VISUAL, KHÔNG CÓ SỬA/XÓA, ICON KHÓA + TOOLTIP) --}}
                        @if ($autoSalaryRow)
                            <tr class="bg-blue-50/60 hover:bg-blue-50/90 transition-colors border-l-4 border-l-blue-600">
                                <td class="py-4 px-4 font-semibold text-blue-900 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-blue-600 text-[18px]">event_repeat</span>
                                        {{ $autoSalaryRow->expense_date }}
                                    </div>
                                    <div class="text-[11px] text-blue-600 font-normal pl-6">{{ $autoSalaryRow->date_sub }}</div>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-bold bg-blue-100 text-blue-700 border border-blue-200">
                                            <span class="material-symbols-outlined text-[15px]">lock</span>
                                            TỰ ĐỘNG
                                        </span>
                                        <span class="font-bold text-slate-900 text-[15px]">{{ $autoSalaryRow->title }}</span>
                                    </div>
                                    <p class="text-xs text-blue-700/80 mt-1 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[14px]">info</span>
                                        {{ $autoSalaryRow->description }}
                                    </p>
                                </td>
                                <td class="py-4 px-4 text-right whitespace-nowrap">
                                    <div class="text-base font-extrabold text-blue-900">{{ number_format($autoSalaryRow->amount, 0, ',', '.') }}</div>
                                    <div class="text-[11px] text-slate-500">{{ $autoSalaryRow->staff_count }} nhân sự đủ điều kiện</div>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-200">
                                        <span class="material-symbols-outlined text-[14px]">account_balance</span>
                                        Chuyển khoản
                                    </span>
                                </td>
                                <td class="py-4 px-4 whitespace-nowrap">
                                    <span class="px-2 py-1 rounded-md text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200">
                                        {{ $autoSalaryRow->branch_name }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="text-xs text-slate-600 max-w-xs truncate" title="{{ $autoSalaryRow->notes }}">
                                        {{ $autoSalaryRow->notes }}
                                    </div>
                                </td>
                                {{-- Thao tác: KHÔNG CÓ NÚT SỬA/XÓA - THAY BẰNG BADGE KHÓA CỐ ĐỊNH --}}
                                <td class="py-4 px-4 text-center">
                                    <div class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 border border-slate-200 text-slate-500 text-xs font-medium cursor-help" title="Số liệu tự động từ bảng lương đã chốt — không sửa được tại đây">
                                        <span class="material-symbols-outlined text-[16px] text-slate-400">lock</span>
                                        <span>Cố định</span>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        {{-- CÁC DÒNG CHI TỰ NHẬP (CÓ ĐỦ NÚT SỬA / XÓA) --}}
                        @forelse ($manualExpenses as $exp)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-3.5 px-4 text-slate-700 whitespace-nowrap font-medium">
                                    {{ \Carbon\Carbon::parse($exp->expense_date)->format('d/m/Y') }}
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-semibold text-slate-900">{{ $exp->title }}</div>
                                    <div class="text-xs text-slate-400">
                                        Người lập: {{ $exp->creator?->name ?? '—' }} • {{ $exp->created_at ? $exp->created_at->format('d/m H:i') : '' }}
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-right font-bold text-slate-800 whitespace-nowrap">
                                    {{ number_format($exp->amount, 0, ',', '.') }}
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    @if ($exp->payment_method === 'chuyen_khoan')
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-purple-50 text-purple-700">
                                            <span class="material-symbols-outlined text-[13px]">account_balance</span>
                                            Chuyển khoản
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-800">
                                            <span class="material-symbols-outlined text-[13px]">payments</span>
                                            Tiền mặt
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-slate-700 whitespace-nowrap">
                                    {{ $exp->branch?->name ?? 'Toàn hệ thống' }}
                                </td>
                                <td class="py-3.5 px-4 text-xs text-slate-500">
                                    {{ $exp->notes ?: '—' }}
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" onclick='openEditModal(@json($exp))' title="Sửa khoản chi" class="p-1.5 text-slate-500 hover:text-primary-container hover:bg-orange-50 rounded-lg transition-colors">
                                            <span class="material-symbols-outlined text-[18px]">edit</span>
                                        </button>
                                        <form method="POST" action="{{ route('finance.expenses.destroy', $exp->id) }}" onsubmit="return confirm('Bạn có chắc chắn muốn xóa khoản chi: \'{{ addslashes($exp->title) }}\' khỏi sổ chi vận hành?')" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="Xóa khoản chi" class="p-1.5 text-slate-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                                <span class="material-symbols-outlined text-[18px]">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            @if (!$autoSalaryRow)
                                <tr>
                                    <td colspan="7" class="py-12 text-center text-slate-400">
                                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-2 text-slate-400">
                                            <span class="material-symbols-outlined text-2xl">receipt_long</span>
                                        </div>
                                        <p class="font-medium">Chưa có khoản chi nào trong kỳ tháng này</p>
                                        <p class="text-xs mt-1">Bấm "+ Thêm khoản chi mới" để ghi nhận chi phí</p>
                                    </td>
                                </tr>
                            @endif
                        @endforelse

                    </tbody>
                </table>
            </div>

            {{-- Footer: Tổng kết --}}
            <div class="p-4 bg-slate-50/70 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span>Hiển thị toàn bộ <strong>{{ $totalItemsCount }}</strong> bản ghi của tháng</span>
                    <span class="text-slate-300">|</span>
                    <span class="text-slate-600">Tổng cộng thực chi: <strong class="text-slate-900 text-sm">{{ number_format($grandTotalExpense, 0, ',', '.') }} VNĐ</strong></span>
                </div>
            </div>
        </div>

    </div>

    {{-- MODAL THÊM / SỬA KHOẢN CHI (FORM ĐẦY ĐỦ 6 Ô THEO SPEC) --}}
    <div id="expenseModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            
            {{-- Modal Header --}}
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-orange-100 text-primary-container flex items-center justify-center">
                        <span id="modalIcon" class="material-symbols-outlined text-[20px]">post_add</span>
                    </div>
                    <div>
                        <h3 id="modalTitle" class="font-bold text-slate-900 text-base">Thêm khoản chi vận hành mới</h3>
                        <p class="text-xs text-slate-500">Lưu ngay vào sổ chi, không cần duyệt</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-700 transition-colors">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>

            {{-- Modal Form Body --}}
            <form id="expenseForm" method="POST" action="{{ route('finance.expenses.store') }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" id="formMethod" name="_method" value="POST">

                {{-- Ô 1: Ngày chi (date, bắt buộc) --}}
                <div>
                    <label for="formExpenseDate" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Ngày chi <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="formExpenseDate" name="expense_date" value="{{ date('Y-m-d') }}" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-800 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all">
                </div>

                {{-- Ô 2: Nội dung (text tự do, bắt buộc) --}}
                <div>
                    <label for="formTitle" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Nội dung khoản chi <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="formTitle" name="title" placeholder="Ví dụ: Mua rèm cửa phòng học, Nạp mực máy in..." required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all">
                </div>

                {{-- Grid 2 cột: Số tiền & Hình thức --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Ô 3: Số tiền (number > 0, bắt buộc) --}}
                    <div>
                        <label for="formAmount" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Số tiền (VNĐ) <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="number" id="formAmount" name="amount" min="1000" step="1000" placeholder="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3 pr-10 py-2.5 text-sm font-semibold text-slate-900 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">đ</span>
                        </div>
                    </div>

                    {{-- Ô 4: Hình thức chi (dropdown Tiền mặt/Chuyển khoản) --}}
                    <div>
                        <label for="formPaymentMethod" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                            Hình thức chi <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <select id="formPaymentMethod" name="payment_method" required class="w-full appearance-none bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all cursor-pointer">
                                <option value="chuyen_khoan">Chuyển khoản</option>
                                <option value="tien_mat">Tiền mặt</option>
                            </select>
                            <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-[18px]">expand_more</span>
                        </div>
                    </div>
                </div>

                {{-- Ô 5: Chi nhánh (dropdown, bắt buộc) --}}
                <div>
                    <label for="formBranchId" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Chi nhánh áp dụng <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <select id="formBranchId" name="branch_id" required class="w-full appearance-none bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all cursor-pointer">
                            <option value="" disabled selected>-- Chọn chi nhánh cơ sở --</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-[18px]">expand_more</span>
                    </div>
                </div>

                {{-- Phân loại danh mục (Tùy chọn) --}}
                <div>
                    <label for="formCategory" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Phân loại chi phí <span class="text-slate-400 font-normal text-[11px]">(Tự động nhận diện nếu để trống)</span>
                    </label>
                    <div class="relative">
                        <select id="formCategory" name="category" class="w-full appearance-none bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all cursor-pointer">
                            <option value="">Tự động theo nội dung</option>
                            <option value="mat_bang_tien_ich">Mặt bằng &amp; Tiện ích (Thuê nhà, điện, nước, internet...)</option>
                            <option value="giao_trinh_van_hanh">In ấn &amp; Vận hành lớp (Giáo trình, VPP, điều hòa, nước uống...)</option>
                            <option value="khac">Chi phí khác</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-[18px]">expand_more</span>
                    </div>
                </div>

                {{-- Ô 6: Ghi chú (textarea, optional) --}}
                <div>
                    <label for="formNotes" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Ghi chú &amp; Thông tin chứng từ <span class="text-slate-400 font-normal text-[11px]">(Tùy chọn)</span>
                    </label>
                    <textarea id="formNotes" name="notes" rows="3" placeholder="Nhập mã hóa đơn, thông tin nhà cung cấp hoặc lưu ý nội bộ..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-sm text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container focus:outline-none transition-all resize-none"></textarea>
                </div>

                {{-- Thông tin tự động --}}
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200/60 text-xs text-slate-500 flex items-center justify-between">
                    <span>Người lập: <strong class="text-slate-700">{{ Auth::user()->name ?? 'Admin' }}</strong></span>
                    <span>Thời điểm: <strong class="text-slate-700">Tự động khi lưu</strong></span>
                </div>

                {{-- Action Buttons --}}
                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2.5">
                    <button type="button" onclick="closeModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 font-semibold text-sm transition-colors">
                        Hủy bỏ
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-dark text-white font-bold text-sm transition-all shadow-sm shadow-orange-500/20 active:scale-[0.98]">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        <span id="submitBtnText">Lưu khoản chi</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('expenseModal');
        const expenseForm = document.getElementById('expenseForm');
        const formMethod = document.getElementById('formMethod');
        const modalTitle = document.getElementById('modalTitle');
        const modalIcon = document.getElementById('modalIcon');
        const submitBtnText = document.getElementById('submitBtnText');

        function openCreateModal() {
            expenseForm.reset();
            formMethod.value = 'POST';
            expenseForm.action = "{{ route('finance.expenses.store') }}";
            modalTitle.innerText = 'Thêm khoản chi vận hành mới';
            modalIcon.innerText = 'post_add';
            submitBtnText.innerText = 'Lưu khoản chi';
            document.getElementById('formExpenseDate').value = "{{ date('Y-m-d') }}";
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function openEditModal(exp) {
            expenseForm.reset();
            formMethod.value = 'PUT';
            expenseForm.action = "/finance/expenses/" + exp.id;
            modalTitle.innerText = 'Chỉnh sửa khoản chi vận hành';
            modalIcon.innerText = 'edit_note';
            submitBtnText.innerText = 'Cập nhật khoản chi';

            document.getElementById('formExpenseDate').value = exp.expense_date ? exp.expense_date.substring(0, 10) : '';
            document.getElementById('formTitle').value = exp.title || '';
            document.getElementById('formAmount').value = exp.amount ? parseInt(exp.amount) : '';
            document.getElementById('formPaymentMethod').value = exp.payment_method || 'chuyen_khoan';
            document.getElementById('formBranchId').value = exp.branch_id || '';
            document.getElementById('formCategory').value = exp.category || '';
            document.getElementById('formNotes').value = exp.notes || '';

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeModal() {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    </script>
</x-app-layout>
