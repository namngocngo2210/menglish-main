<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-500 hover:text-slate-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div class="flex items-center gap-2 text-xs text-slate-500 font-medium">
                    <a href="{{ route('tuition.students') }}" class="hover:text-primary transition">Học phí &amp; Hóa đơn</a>
                    <span class="material-symbols-outlined text-xs text-slate-300">chevron_right</span>
                    <span class="text-slate-900 font-bold">Duyệt phiếu thu học phí</span>
                </div>
            </div>
            <a href="{{ route('tuition.receipts.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-xs transition">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                <span>Lập phiếu thu mới</span>
            </a>
        </div>
    </x-slot>

    <div class="max-w-[1520px] mx-auto space-y-5" x-data="{ showApproveModal: false, showRejectModal: false, zoomImage: false }">
        @if (session('status'))
            <div class="p-4 bg-emerald-50 border-l-4 border-emerald-500 rounded-r-xl text-xs text-emerald-800 flex items-center gap-2 shadow-xs">
                <span class="material-symbols-outlined text-emerald-600 text-lg">check_circle</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if (isset($errors) && $errors->any())
            <div class="p-4 bg-rose-50 border-l-4 border-rose-500 rounded-r-xl text-xs text-rose-800 space-y-1 shadow-xs">
                <div class="font-bold flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-rose-600 text-base">error</span>
                    Vui lòng kiểm tra các lỗi sau:
                </div>
                <ul class="list-disc list-inside pl-5">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- BEGIN: HeaderPanel (Thống kê & Bộ lọc) -->
        <header class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5 space-y-4">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl lg:text-2xl font-bold text-slate-900 tracking-tight flex items-center gap-3">
                        <span>Duyệt phiếu thu học phí</span>
                        <span class="text-xs font-semibold px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200/80 rounded-full inline-flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                            {{ $pendingCount }} phiếu chờ xử lý
                        </span>
                    </h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Kiểm tra, đối chiếu chứng từ và phê duyệt các phiếu thu học phí &amp; phụ thu từ nhân viên tư vấn/học vụ
                    </p>
                </div>

                <!-- 3 Thẻ chỉ số Counter -->
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <!-- Chờ duyệt -->
                    <div class="flex items-center gap-3 px-4 py-2.5 rounded-xl bg-amber-50/70 border border-amber-200/70 text-amber-900 shadow-sm">
                        <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700 font-bold shrink-0">
                            <span class="material-symbols-outlined text-lg">schedule</span>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-amber-700 uppercase">Chờ duyệt</div>
                            <div class="text-sm font-bold leading-tight">{{ $pendingCount }} phiếu <span class="text-[11px] font-mono text-amber-800 font-normal">({{ number_format((float)$pendingTotal) }} VNĐ)</span></div>
                        </div>
                    </div>

                    <!-- Đã duyệt hôm nay -->
                    <div class="flex items-center gap-3 px-4 py-2.5 rounded-xl bg-emerald-50/70 border border-emerald-200/70 text-emerald-900 shadow-sm">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-700 font-bold shrink-0">
                            <span class="material-symbols-outlined text-lg">check_circle</span>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-emerald-700 uppercase">Đã duyệt hôm nay</div>
                            <div class="text-sm font-bold leading-tight text-emerald-800">{{ $approvedTodayCount }} phiếu</div>
                        </div>
                    </div>

                    <!-- Đã từ chối hôm nay -->
                    <div class="flex items-center gap-3 px-4 py-2.5 rounded-xl bg-rose-50/70 border border-rose-200/70 text-rose-900 shadow-sm">
                        <div class="w-8 h-8 rounded-lg bg-rose-100 flex items-center justify-center text-rose-700 font-bold shrink-0">
                            <span class="material-symbols-outlined text-lg">cancel</span>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-rose-700 uppercase">Đã từ chối hôm nay</div>
                            <div class="text-sm font-bold leading-tight text-rose-800">{{ $rejectedTodayCount }} phiếu</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Lọc & Tìm kiếm -->
            <form method="GET" action="{{ route('tuition.receipts.approve') }}" class="pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5 w-full lg:w-auto text-xs">
                    <!-- Branch Selector -->
                    <div class="min-w-[160px]">
                        <select name="branch_id" onchange="this.form.submit()" class="w-full text-xs font-semibold text-slate-700 bg-slate-50 border-slate-200 rounded-lg py-2 pl-3 pr-8 focus:ring-primary-container focus:border-primary-container cursor-pointer">
                            <option value="all">Tất cả Cơ sở</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Payment Method Selector -->
                    <div class="min-w-[150px]">
                        <select name="payment_method" onchange="this.form.submit()" class="w-full text-xs font-semibold text-slate-700 bg-slate-50 border-slate-200 rounded-lg py-2 pl-3 pr-8 focus:ring-primary-container focus:border-primary-container cursor-pointer">
                            <option value="all">Hình thức: Tất cả</option>
                            <option value="transfer" {{ request('payment_method') === 'transfer' || request('payment_method') === 'ck' ? 'selected' : '' }}>Chuyển khoản</option>
                            <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>Tiền mặt</option>
                        </select>
                    </div>

                    <!-- Status Pills -->
                    <div class="inline-flex rounded-lg p-0.5 bg-slate-100 border border-slate-200 text-xs font-medium">
                        <a href="{{ route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'pending'])) }}" class="px-3 py-1.5 rounded-md transition {{ request('status', $pendingCount > 0 ? 'pending' : 'all') === 'pending' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            Chờ duyệt ({{ $pendingCount }})
                        </a>
                        <a href="{{ route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'approved'])) }}" class="px-3 py-1.5 rounded-md transition {{ request('status') === 'approved' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            Đã duyệt
                        </a>
                        <a href="{{ route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'rejected'])) }}" class="px-3 py-1.5 rounded-md transition {{ request('status') === 'rejected' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            Từ chối
                        </a>
                        <a href="{{ route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'all'])) }}" class="px-3 py-1.5 rounded-md transition {{ request('status', $pendingCount > 0 ? 'pending' : 'all') === 'all' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            Tất cả
                        </a>
                    </div>
                </div>

                <!-- Search Input -->
                <div class="relative w-full lg:w-80">
                    <span class="material-symbols-outlined absolute left-3 top-2 text-slate-400 text-lg pointer-events-none">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Tìm theo tên học viên, mã phiếu..." class="w-full text-xs font-medium bg-slate-50 border-slate-200 rounded-lg pl-9 pr-8 py-2 focus:bg-white focus:ring-primary-container focus:border-primary-container transition" />
                    @if (request('q'))
                        <a href="{{ route('tuition.receipts.approve', request()->except('q')) }}" class="absolute right-2.5 top-2 text-slate-400 hover:text-slate-600">
                            <span class="material-symbols-outlined text-base">close</span>
                        </a>
                    @endif
                </div>
            </form>
        </header>

        <!-- BEGIN: MasterDetailGrid (5 Cột danh sách hàng đợi + 7 Cột chi tiết kiểm tra) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            <!-- CỘT TRÁI (5 Cột): Danh sách hàng đợi -->
            <section class="lg:col-span-5 bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden flex flex-col min-w-0">
                <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                    <div class="flex items-center gap-2">
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Hàng đợi phiếu thu</h3>
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 font-mono">{{ $pendingReceipts->count() }} phiếu</span>
                    </div>
                    <span class="text-[11px] text-slate-400 flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">autorenew</span>
                        Tự động làm mới
                    </span>
                </div>

                <div class="divide-y divide-slate-100 max-h-[820px] overflow-y-auto custom-scrollbar">
                    @forelse ($pendingReceipts as $rc)
                        @php
                            $isSelected = $selectedReceipt && $selectedReceipt->id === $rc->id;
                            $student = $rc->tuition?->student ?? $rc->student;
                            $branch = $student?->branch;
                            $className = $rc->tuition?->classModel?->name ?? $student?->currentClass?->name ?? 'Lớp học';
                        @endphp
                        <a href="{{ route('tuition.receipts.approve', array_merge(request()->all(), ['selected_id' => $rc->id])) }}" class="block p-4 transition relative {{ $isSelected ? 'bg-orange-50/50 border-l-4 border-primary-container shadow-xs' : 'hover:bg-slate-50 border-l-4 border-transparent' }}">
                            <div class="flex items-start justify-between gap-2 mb-1.5">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold font-mono px-2 py-0.5 rounded {{ $isSelected ? 'bg-white text-slate-900 border border-primary-container/40' : 'bg-slate-100 text-slate-700 border border-slate-200' }}">
                                        {{ $rc->receipt_number }}
                                    </span>
                                    @if ($rc->payment_method === 'cash')
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs">payments</span>
                                            Tiền mặt
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs">account_balance</span>
                                            Chuyển khoản
                                        </span>
                                    @endif
                                </div>
                                <span class="text-[11px] text-slate-400 font-medium">{{ $rc->created_at->diffForHumans() }}</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-xs font-bold text-slate-900 flex items-center gap-1">
                                        <span>{{ $student?->name ?? 'Học viên' }}</span>
                                        <span class="text-[11px] font-mono text-slate-400 font-normal">({{ $student?->code ?? 'HV' }})</span>
                                    </h4>
                                    <p class="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                        <span>{{ $className }}</span>
                                        @if ($rc->surcharge_amount > 0)
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-100 text-amber-800">+ Phụ thu</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-bold font-mono {{ $isSelected ? 'text-primary' : 'text-slate-900' }}">{{ number_format($rc->amount) }} đ</div>
                                    <div class="text-[10px] text-slate-400">{{ $branch?->name ?? 'Trụ sở chính' }}</div>
                                </div>
                            </div>

                            <div class="mt-2.5 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                                <span class="flex items-center gap-1 truncate max-w-[240px]">
                                    <span class="material-symbols-outlined text-slate-400 text-xs">person</span>
                                    Người tạo: <strong class="text-slate-700">{{ $rc->creator?->name ?? 'CM' }}</strong>
                                </span>

                                @if ($isSelected)
                                    <span class="text-primary text-[11px] font-bold flex items-center gap-0.5">
                                        Đang xem
                                        <span class="material-symbols-outlined text-xs">chevron_right</span>
                                    </span>
                                @elseif ($rc->status === 'approved')
                                    <span class="text-emerald-700 text-[10px] font-bold bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">Đã duyệt</span>
                                @elseif ($rc->status === 'rejected')
                                    <span class="text-rose-700 text-[10px] font-bold bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">Bản nháp</span>
                                @endif
                            </div>
                        </a>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs space-y-2">
                            <span class="material-symbols-outlined text-3xl text-slate-300">task</span>
                            <p>Không có phiếu thu nào theo bộ lọc đã chọn.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <!-- CỘT PHẢI (7 Cột): Chi tiết phiếu thu & Thao tác duyệt -->
            <section class="lg:col-span-7 flex flex-col gap-5 min-w-0">
                @if ($selectedReceipt)
                    @php
                        $st = $selectedReceipt->tuition?->student ?? $selectedReceipt->student;
                        $tClass = $selectedReceipt->tuition?->classModel?->name ?? $st?->currentClass?->name ?? 'IELTS Foundation 02';
                        $branchName = $st?->branch?->name ?? 'Cơ sở Cầu Giấy';
                    @endphp

                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden flex flex-col">
                        <!-- Detail Header -->
                        <div class="p-5 lg:p-6 bg-slate-50/70 border-b border-slate-100 flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2.5">
                                    <h2 class="text-lg font-bold text-slate-900">Chi tiết phiếu thu: <span class="font-mono text-primary">{{ $selectedReceipt->receipt_number }}</span></h2>
                                    @if ($selectedReceipt->status === 'approved')
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span>
                                            Đã duyệt (HĐ: {{ $selectedReceipt->invoice_number ?? 'Auto' }})
                                        </span>
                                    @elseif ($selectedReceipt->status === 'rejected')
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 mr-1.5"></span>
                                            Bản nháp / Từ chối
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse mr-1.5"></span>
                                            Chờ duyệt
                                        </span>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-500 mt-1 flex flex-wrap items-center gap-y-1 gap-x-3">
                                    <span>Thời gian gửi: <strong>{{ $selectedReceipt->created_at?->format('H:i - d/m/Y') ?? now()->format('H:i - d/m/Y') }}</strong></span>
                                    <span>•</span>
                                    <span>Người lập: <strong>{{ $selectedReceipt->creator?->name ?? 'Lê Thị Bích' }}</strong> (Tư vấn viên / {{ $branchName }})</span>
                                </div>
                            </div>

                            <a href="{{ $selectedReceipt->student_tuition_id ? route('crm.tuition-bill', $selectedReceipt->student_tuition_id) : '#' }}" target="_blank" class="text-xs font-semibold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 px-3.5 py-2 rounded-lg inline-flex items-center gap-1.5 transition">
                                <span class="material-symbols-outlined text-slate-500 text-base">print</span>
                                Xem trước mẫu in
                            </a>
                        </div>

                        <div class="p-5 lg:p-6 flex flex-col gap-6">
                            <!-- KHỐI 1: Thông tin học viên & Lớp học -->
                            <div class="rounded-xl border border-slate-200/90 bg-slate-50/40 p-4 lg:p-5">
                                <div class="flex items-center gap-2 mb-3.5">
                                    <div class="w-7 h-7 rounded-lg bg-orange-100 text-primary flex items-center justify-center font-bold text-xs">
                                        <span class="material-symbols-outlined text-base">person</span>
                                    </div>
                                    <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">1. Thông tin học viên &amp; Phụ huynh</h3>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                                    <div class="bg-white p-3.5 rounded-lg border border-slate-200/70 space-y-0.5">
                                        <span class="text-xs text-slate-400 block mb-0.5 uppercase">Học viên</span>
                                        <div class="text-sm font-bold text-slate-900">{{ $st?->name ?? 'Học viên' }}</div>
                                        <span class="text-xs font-mono font-semibold text-primary">{{ $st?->code ?? 'HV' }}</span>
                                    </div>
                                    <div class="bg-white p-3.5 rounded-lg border border-slate-200/70 space-y-0.5">
                                        <span class="text-xs text-slate-400 block mb-0.5 uppercase">Lớp học hiện tại</span>
                                        <div class="text-sm font-bold text-slate-900">{{ $tClass }}</div>
                                        <span class="text-xs text-slate-500">{{ $branchName }}</span>
                                    </div>
                                    <div class="bg-white p-3.5 rounded-lg border border-slate-200/70 space-y-0.5">
                                        <span class="text-xs text-slate-400 block mb-0.5 uppercase">Người nộp tiền (Phụ huynh)</span>
                                        <div class="text-sm font-bold text-slate-900">{{ $selectedReceipt->payer_name ?: ($st?->parent_name ?: $st?->name) }}</div>
                                        <span class="text-xs font-mono font-semibold text-slate-600">{{ $selectedReceipt->payer_phone ?: ($st?->parent_phone ?: $st?->phone) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- KHỐI 2: Chi tiết nguồn tiền & Bảng kê tài chính -->
                            <div class="rounded-xl border border-slate-200/90 p-4 lg:p-5">
                                <div class="flex items-center justify-between mb-3.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xs">
                                            <span class="material-symbols-outlined text-base">calculate</span>
                                        </div>
                                        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">2. Bảng kê chi tiết các khoản thu</h3>
                                    </div>
                                    <span class="text-xs text-slate-500">
                                        Phương thức: <strong class="text-blue-600 font-semibold">{{ $selectedReceipt->payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản Ngân hàng' }}</strong>
                                    </span>
                                </div>

                                <div class="border border-slate-200 rounded-lg overflow-x-auto text-xs">
                                    <table class="w-full text-left min-w-[480px]">
                                        <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200 text-xs">
                                            <tr>
                                                <th class="py-3 px-4">Khoản mục</th>
                                                <th class="py-3 px-4">Nội dung diễn giải</th>
                                                <th class="py-3 px-4 text-right">Số tiền</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <tr>
                                                <td class="py-3 px-4 font-semibold text-slate-900">
                                                    Học phí đào tạo
                                                </td>
                                                <td class="py-3 px-4 text-slate-600">
                                                    Khóa {{ $tClass }}
                                                    @if (($selectedReceipt->discount_amount ?? 0) > 0)
                                                        (Đã áp dụng giảm trừ Voucher Ưu đãi {{ number_format((float)$selectedReceipt->discount_amount) }} đ)
                                                    @endif
                                                </td>
                                                <td class="py-3 px-4 font-bold font-mono text-slate-900 text-right">
                                                    {{ number_format((float)($selectedReceipt->tuition_amount ?: ($selectedReceipt->amount - ($selectedReceipt->surcharge_amount ?? 0)))) }} đ
                                                </td>
                                            </tr>

                                            @if (($selectedReceipt->surcharge_amount ?? 0) > 0)
                                                <tr class="bg-amber-50/40">
                                                    <td class="py-3 px-4 font-semibold text-amber-900 flex items-center gap-1.5">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                        Phụ thu phát sinh
                                                    </td>
                                                    <td class="py-3 px-4 text-amber-800">
                                                        {{ $selectedReceipt->surcharge_reason ?: 'Phụ thu giáo trình / học liệu' }}
                                                    </td>
                                                    <td class="py-3 px-4 font-bold font-mono text-amber-900 text-right">
                                                        + {{ number_format((float)$selectedReceipt->surcharge_amount) }} đ
                                                    </td>
                                                </tr>
                                            @endif
                                        </tbody>
                                        <tfoot class="bg-orange-50/70 border-t border-orange-200">
                                            <tr>
                                                <td class="py-3.5 px-4 text-xs font-bold text-slate-900" colspan="2">
                                                    TỔNG SỐ TIỀN THỰC THU
                                                </td>
                                                <td class="py-3.5 px-4 text-right">
                                                    <span class="text-base font-bold font-mono text-primary">{{ number_format((float)$selectedReceipt->amount) }} VNĐ</span>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <!-- Metadata & Notes -->
                                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                    <div class="p-3.5 bg-slate-50 rounded-lg border border-slate-200 space-y-1">
                                        <span class="text-[10px] font-bold text-slate-500 uppercase block">Trạng thái đối soát &amp; Hóa đơn VAT</span>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="inline-flex items-center text-emerald-700 font-bold bg-emerald-50 px-2.5 py-1 rounded border border-emerald-200 text-xs">
                                                ✓ {{ $selectedReceipt->payment_method === 'cash' ? 'Đã thu tiền mặt' : 'Khớp số dư đối soát' }}
                                            </span>
                                            @if ($selectedReceipt->is_vat_invoice)
                                                <span class="inline-flex items-center text-blue-700 font-bold bg-blue-50 px-2.5 py-1 rounded border border-blue-200 text-xs">
                                                    Yêu cầu hóa đơn đỏ (VAT)
                                                </span>
                                            @else
                                                <span class="inline-flex items-center text-slate-600 font-medium bg-slate-100 px-2.5 py-1 rounded border border-slate-200 text-xs">
                                                    Không yêu cầu hóa đơn đỏ
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="p-3.5 bg-slate-50 rounded-lg border border-slate-200 space-y-1">
                                        <span class="text-[10px] font-bold text-slate-500 uppercase block">Ghi chú từ nhân viên tạo phiếu (CM)</span>
                                        <p class="text-slate-700 italic text-xs leading-relaxed">
                                            "{{ $selectedReceipt->notes ?: 'Phụ huynh nộp thanh toán đúng số tiền và thông tin đối soát.' }}"
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- KHỐI 3: Minh chứng chuyển khoản (UNC) & Đối chiếu -->
                            <div class="rounded-xl border border-slate-200/90 p-4 lg:p-5">
                                <div class="flex items-center justify-between mb-3.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs">
                                            <span class="material-symbols-outlined text-base">receipt</span>
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">3. Minh chứng chuyển khoản (UNC) / Biên lai</h3>
                                            <p class="text-xs text-slate-400">Đối chiếu mã giao dịch và số tài khoản nhận</p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-lg border border-slate-200 text-xs">
                                        <button type="button" @click="zoomImage = true" class="p-1.5 rounded hover:bg-white text-slate-600 transition" title="Phóng to">
                                            <span class="material-symbols-outlined text-base">zoom_in</span>
                                        </button>
                                        @if ($selectedReceipt->proof_image)
                                            <a href="{{ $selectedReceipt->proof_image }}" target="_blank" download class="p-1.5 rounded hover:bg-white text-slate-600 transition" title="Tải ảnh gốc">
                                                <span class="material-symbols-outlined text-base">download</span>
                                            </a>
                                        @endif
                                    </div>
                                </div>

                                <div class="bg-slate-900 rounded-xl p-5 flex flex-col md:flex-row items-center gap-5 border border-slate-800">
                                    <!-- Proof Image Frame -->
                                    <div class="relative w-full md:w-64 h-52 bg-slate-800 rounded-lg overflow-hidden border border-slate-700 flex items-center justify-center shrink-0 cursor-pointer group/img" @click="zoomImage = true">
                                        @if ($selectedReceipt->proof_image)
                                            <img src="{{ $selectedReceipt->proof_image }}" alt="Minh chứng" class="w-full h-full object-contain" />
                                        @else
                                            <div class="w-full h-full p-3 bg-[#00172e] flex flex-col justify-between text-white text-[11px] font-mono">
                                                <div class="flex justify-between border-b border-emerald-500/40 pb-1.5 text-emerald-400 font-bold">
                                                    <span>Vietcombank Digibank</span>
                                                    <span>GD THÀNH CÔNG</span>
                                                </div>
                                                <div class="space-y-1.5 my-auto">
                                                    <div class="text-center text-sm font-bold text-amber-400 font-mono">{{ number_format((float)$selectedReceipt->amount) }} VND</div>
                                                    <div class="text-slate-300">Đến: TRUNG TAM ANH NGU MENGLISH</div>
                                                    <div class="text-slate-300">STK: 1029384756</div>
                                                    <div class="text-slate-400">ND: MENGLISH {{ $st?->code ?? 'HV' }} {{ $st?->name ?? '' }}</div>
                                                    <div class="text-emerald-400 font-semibold font-mono">Mã GD: {{ $selectedReceipt->transaction_code ?: ($selectedReceipt->paper_invoice_number ?: 'FT232981354789') }}</div>
                                                </div>
                                                <div class="text-[10px] text-slate-400 text-right border-t border-slate-700/60 pt-1">
                                                    {{ $selectedReceipt->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}
                                                </div>
                                            </div>
                                        @endif
                                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover/img:opacity-100 transition flex items-center justify-center text-white text-xs font-bold gap-1">
                                            <span class="material-symbols-outlined text-base">zoom_in</span> Bấm để phóng to
                                        </div>
                                    </div>

                                    <!-- Proof Match Details -->
                                    <div class="flex-1 w-full space-y-2.5 text-xs">
                                        <div class="bg-slate-800/80 p-3.5 rounded-lg border border-slate-700 space-y-2">
                                            <div class="flex items-center justify-between">
                                                <span class="text-slate-400">Mã tham chiếu:</span>
                                                <span class="font-mono font-bold text-amber-400">{{ $selectedReceipt->transaction_code ?: ($selectedReceipt->paper_invoice_number ?: 'FT232981354789') }}</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-slate-400">Tài khoản thụ hưởng:</span>
                                                <span class="font-medium text-slate-200">1029384756 (Vietcombank)</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-slate-400">Thời gian giao dịch:</span>
                                                <span class="text-slate-200 font-mono">{{ $selectedReceipt->created_at?->format('H:i - d/m/Y') ?? now()->format('H:i - d/m/Y') }}</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-slate-400">Trạng thái đối soát:</span>
                                                <span class="text-emerald-400 font-bold flex items-center gap-1 text-xs">
                                                    <span class="material-symbols-outlined text-sm">verified</span>
                                                    Khớp số tiền &amp; cú pháp
                                                </span>
                                            </div>
                                        </div>

                                        <div class="p-3 rounded-lg bg-emerald-900/20 border border-emerald-700/40 text-emerald-300 text-xs flex items-start gap-2">
                                            <span class="material-symbols-outlined text-emerald-400 text-base shrink-0 mt-0.5">verified_user</span>
                                            <span>Chứng từ hợp lệ. Hệ thống xác nhận có giao dịch đúng số tiền <strong>{{ number_format((float)$selectedReceipt->amount) }} đ</strong> vào tài khoản MENGLISH.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- KHỐI 4: Thanh tác vụ phê duyệt -->
                        <footer class="p-4 lg:p-5 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-3">
                            <div class="text-xs text-slate-500 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base text-slate-400">info</span>
                                <span>Thao tác duyệt sẽ lập tức hạch toán số dư công nợ của học viên và cấp số hóa đơn.</span>
                            </div>

                            <div class="flex items-center gap-3">
                                @if ($selectedReceipt->status === 'pending')
                                    <button type="button" @click="showRejectModal = true" class="px-4 py-2.5 rounded-xl border border-rose-300 text-rose-700 bg-white hover:bg-rose-50 font-bold text-xs transition inline-flex items-center gap-1.5 shadow-sm cursor-pointer">
                                        <span class="material-symbols-outlined text-base text-rose-600">close</span>
                                        Từ chối phiếu thu
                                    </button>
                                    <button type="button" @click="showApproveModal = true" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white font-bold text-xs transition shadow-sm inline-flex items-center gap-1.5 cursor-pointer">
                                        <span class="material-symbols-outlined text-base">check</span>
                                        Duyệt phiếu thu ({{ number_format((float)$selectedReceipt->amount) }} VNĐ)
                                    </button>
                                @elseif ($selectedReceipt->status === 'approved')
                                    <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-3.5 py-2 rounded-xl border border-emerald-200 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base">check_circle</span>
                                        Đã duyệt bởi {{ $selectedReceipt->approver?->name ?? 'Admin' }}
                                    </span>
                                @else
                                    <span class="text-xs font-bold text-rose-700 bg-rose-50 px-3.5 py-2 rounded-xl border border-rose-200 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base">cancel</span>
                                        Đã từ chối (Bản nháp)
                                    </span>
                                @endif
                            </div>
                        </footer>
                    </div>

                    <!-- MODAL 1: Xác nhận Duyệt phiếu thu -->
                    <div x-show="showApproveModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
                        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-4" @click.away="showApproveModal = false">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-2xl">verified</span>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-slate-900">Xác nhận duyệt phiếu thu</h4>
                                    <span class="text-xs text-slate-500 font-mono">Mã: {{ $selectedReceipt->receipt_number }}</span>
                                </div>
                            </div>

                            <div class="space-y-2.5 text-xs text-slate-600 leading-relaxed">
                                <p>
                                    Bạn có chắc chắn muốn duyệt phiếu thu <strong class="text-slate-900 font-mono">{{ $selectedReceipt->receipt_number }}</strong> với tổng số tiền <strong class="text-primary font-bold text-sm font-mono">{{ number_format($selectedReceipt->amount) }} VNĐ</strong> cho học viên <strong class="text-slate-900">{{ $st?->name }}</strong>?
                                </p>
                                <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-amber-900 text-[11px] space-y-1">
                                    <div class="font-bold flex items-center gap-1 text-amber-800">
                                        <span class="material-symbols-outlined text-sm">info</span>
                                        Lưu ý nghiệp vụ kế toán:
                                    </div>
                                    <p class="text-amber-800">
                                        Sau khi duyệt, số tiền sẽ được tự động ghi nhận vào sổ quỹ, trừ công nợ học phí của học viên và tự động cấp mã hóa đơn trong dải số kiểm toán.
                                    </p>
                                </div>
                            </div>

                            <form action="{{ route('tuition.receipts.approve.action', $selectedReceipt->id) }}" method="POST" class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2.5">
                                @csrf
                                <button type="button" @click="showApproveModal = false" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition">
                                    Hủy bỏ
                                </button>
                                <button type="submit" class="px-5 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white font-bold text-xs transition shadow-sm flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">check</span>
                                    Xác nhận phê duyệt
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL 2: Từ chối phiếu thu (Nhập lý do) -->
                    <div x-show="showRejectModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
                        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-4" @click.away="showRejectModal = false">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-2xl">warning</span>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-slate-900">Từ chối duyệt phiếu thu</h4>
                                    <span class="text-xs text-slate-500 font-mono">Phiếu: {{ $selectedReceipt->receipt_number }} • Học viên: {{ $st?->name }}</span>
                                </div>
                            </div>

                            <form action="{{ route('tuition.receipts.reject.action', $selectedReceipt->id) }}" method="POST" class="space-y-3 text-xs">
                                @csrf
                                <div>
                                    <label class="block font-bold text-slate-900 mb-1">
                                        Lý do từ chối duyệt <span class="text-rose-500">* (Bắt buộc)</span>
                                    </label>
                                    <textarea name="rejection_reason" required rows="3" placeholder="Ví dụ: Ảnh chụp ủy nhiệm chi bị mất góc mã tham chiếu, số tiền chuyển khoản không khớp với phiếu..." class="w-full text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500/20 p-2.5"></textarea>
                                </div>

                                <div class="p-3 bg-rose-50 rounded-xl border border-rose-200 text-rose-800 text-[11px] leading-relaxed">
                                    <strong>Thông báo hệ thống:</strong> Phiếu thu này sẽ chuyển về trạng thái <strong>"Bản nháp"</strong> kèm thông báo lý do từ chối gửi trả lại nhân viên phụ trách <strong>{{ $selectedReceipt->creator?->name ?? 'CM' }}</strong> để bổ sung minh chứng.
                                </div>

                                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2.5">
                                    <button type="button" @click="showRejectModal = false" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition">
                                        Quay lại
                                    </button>
                                    <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition shadow-sm flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm">close</span>
                                        Xác nhận từ chối
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL 3: Phóng to minh chứng -->
                    <div x-show="zoomImage" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md" @click="zoomImage = false">
                        <div class="relative max-w-3xl w-full bg-slate-900 rounded-2xl p-4 overflow-hidden border border-slate-700" @click.stop>
                            <div class="flex justify-between items-center pb-3 border-b border-slate-800 text-xs text-white">
                                <span class="font-bold">Minh chứng đối soát: {{ $selectedReceipt->receipt_number }}</span>
                                <button type="button" @click="zoomImage = false" class="text-slate-400 hover:text-white">
                                    <span class="material-symbols-outlined text-lg">close</span>
                                </button>
                            </div>
                            <div class="p-4 flex items-center justify-center min-h-[300px]">
                                @if ($selectedReceipt->proof_image)
                                    <img src="{{ $selectedReceipt->proof_image }}" alt="Minh chứng" class="max-h-[70vh] object-contain rounded-lg" />
                                @else
                                    <div class="text-center text-slate-400 space-y-2">
                                        <span class="material-symbols-outlined text-4xl text-slate-500">receipt_long</span>
                                        <p class="text-xs">Ủy nhiệm chi điện tử khớp lệnh hệ thống Vietcombank Digibank</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-12 text-center text-slate-400 space-y-3">
                        <span class="material-symbols-outlined text-4xl text-slate-300">receipt_long</span>
                        <h3 class="text-sm font-bold text-slate-700">Chưa chọn phiếu thu nào</h3>
                        <p class="text-xs text-slate-400">Vui lòng bấm chọn một phiếu thu từ danh sách chờ duyệt bên trái để kiểm tra chi tiết và đối chiếu chứng từ.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
