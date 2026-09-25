<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-rose-600">alarm_on</span>
                    Theo dõi Thu Phí Quá Hạn &amp; Nhắc Hạn Học Phí Đến Hạn
                </h1>
                <p class="text-xs text-gray-500">Đôn đốc công nợ theo Chi nhánh, Lớp học và Học viên — Tự động gửi thông báo nhắc phí T-3, T0 và quá hạn</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('system-config.debt-reminders') }}" class="px-3.5 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">settings</span>
                    <span>Cấu hình Mẫu nhắc nợ</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" x-data="{ activeTab: 'all' }">
        @if (session('status'))
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3 text-xs font-semibold">
                <span class="material-symbols-outlined text-emerald-600">check_circle</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <!-- Summary Metric Cards -->
        @php
            $totalDebt = $overdueTuitions->sum('debt_amount');
            $overdueCount = $overdueTuitions->filter(fn($t) => $t->status === 'overdue' || ($t->due_date && $t->due_date < now()))->count();
            $upcomingCount = $overdueTuitions->filter(fn($t) => $t->due_date && $t->due_date >= now() && $t->due_date <= now()->addDays(7))->count();
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl p-4 border border-rose-200 bg-rose-50/20 shadow-sm">
                <span class="text-xs font-bold text-rose-700 uppercase tracking-wider">Đã quá hạn nộp</span>
                <div class="text-2xl font-black text-rose-600 mt-1 font-mono">{{ $overdueCount }} học viên</div>
                <div class="text-[11px] text-gray-500 mt-1">Cần đôn đốc gọi điện nhắc nhở</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-amber-200 bg-amber-50/20 shadow-sm">
                <span class="text-xs font-bold text-amber-700 uppercase tracking-wider">Sắp đến hạn (T-3)</span>
                <div class="text-2xl font-black text-amber-600 mt-1 font-mono">{{ $upcomingCount }} học viên</div>
                <div class="text-[11px] text-gray-500 mt-1">Cần gửi tin nhắn ZNS nhắc lịch</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-blue-200 bg-blue-50/20 shadow-sm">
                <span class="text-xs font-bold text-blue-700 uppercase tracking-wider">Tổng tiền đọng phí</span>
                <div class="text-2xl font-black text-blue-700 mt-1 font-mono">{{ number_format($totalDebt) }}đ</div>
                <div class="text-[11px] text-gray-500 mt-1">Bao gồm quá hạn &amp; đợt sắp tới</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-emerald-200 bg-emerald-50/20 shadow-sm">
                <span class="text-xs font-bold text-emerald-700 uppercase tracking-wider">Kênh thông báo tự động</span>
                <div class="text-2xl font-black text-emerald-600 mt-1">Zalo ZNS / SMS</div>
                <div class="text-[11px] text-gray-500 mt-1">Kèm mã QR và cú pháp CRM</div>
            </div>
        </div>

        <!-- Tab selector for 3 Statistics & Aggregations -->
        <div class="flex items-center gap-2 border-b border-gray-200 pb-1 text-xs">
            <button type="button" @click="activeTab = 'all'" :class="activeTab === 'all' ? 'border-primary-container text-primary font-bold' : 'border-transparent text-gray-500 hover:text-gray-900'" class="py-2 px-3 border-b-2 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">list_alt</span>
                <span>Danh sách học viên ({{ $overdueTuitions->count() }})</span>
            </button>
            <button type="button" @click="activeTab = 'branch'" :class="activeTab === 'branch' ? 'border-primary-container text-primary font-bold' : 'border-transparent text-gray-500 hover:text-gray-900'" class="py-2 px-3 border-b-2 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">domain</span>
                <span>Thống kê theo Chi nhánh ({{ $statsByBranch->count() }})</span>
            </button>
            <button type="button" @click="activeTab = 'class'" :class="activeTab === 'class' ? 'border-primary-container text-primary font-bold' : 'border-transparent text-gray-500 hover:text-gray-900'" class="py-2 px-3 border-b-2 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">school</span>
                <span>Thống kê theo Lớp học ({{ $statsByClass->count() }})</span>
            </button>
            <button type="button" @click="activeTab = 'top_students'" :class="activeTab === 'top_students' ? 'border-primary-container text-primary font-bold' : 'border-transparent text-gray-500 hover:text-gray-900'" class="py-2 px-3 border-b-2 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">person_alert</span>
                <span>Top nợ theo Học viên</span>
            </button>
        </div>

        <!-- Filter Bar -->
        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <form method="GET" action="{{ route('tuition.overdue') }}" class="flex flex-col sm:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm theo tên học viên, SĐT hoặc mã học viên..."
                           class="w-full pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-xs focus:ring-primary-container focus:border-primary-container">
                </div>
                <div class="w-full sm:w-48">
                    <select name="branch_id" class="w-full bg-white border border-gray-200 rounded-xl text-xs focus:ring-primary-container focus:border-primary-container py-2">
                        <option value="">Tất cả cơ sở</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-48">
                    <select name="class_id" class="w-full bg-white border border-gray-200 rounded-xl text-xs focus:ring-primary-container focus:border-primary-container py-2">
                        <option value="">Tất cả lớp</option>
                        @foreach ($classes as $cl)
                            <option value="{{ $cl->id }}" @selected(request('class_id') == $cl->id)>{{ $cl->name }} ({{ $cl->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-44">
                    <select name="type" class="w-full bg-white border border-gray-200 rounded-xl text-xs focus:ring-primary-container focus:border-primary-container py-2 font-semibold">
                        <option value="all" @selected(request('type', 'all') === 'all')>Tất cả (Quá hạn &amp; Sắp đến)</option>
                        <option value="overdue" @selected(request('type') === 'overdue')>🔴 Chỉ Quá hạn</option>
                        <option value="upcoming" @selected(request('type') === 'upcoming')>🟡 Chỉ Sắp đến hạn (T-3)</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-black text-white rounded-xl text-xs font-bold transition shadow-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">filter_list</span>
                        Lọc
                    </button>
                    @if(request()->hasAny(['search', 'branch_id', 'class_id', 'type']))
                        <a href="{{ route('tuition.overdue') }}" class="px-3 py-2 border border-gray-200 text-gray-500 hover:text-gray-900 rounded-xl text-xs font-semibold hover:bg-gray-50 transition" title="Xóa bộ lọc">
                            <span class="material-symbols-outlined text-[16px]">clear</span>
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- TAB 1: Danh sách học viên -->
        <div x-show="activeTab === 'all'" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Học viên</th>
                            <th class="py-3 px-4">Lớp học</th>
                            <th class="py-3 px-4 text-right">Số tiền nợ</th>
                            <th class="py-3 px-4">Hạn chót thanh toán</th>
                            <th class="py-3 px-4">Cơ sở</th>
                            <th class="py-3 px-4">Tình trạng hạn</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($overdueTuitions as $ot)
                            @php
                                $isOverdue = $ot->status === 'overdue' || ($ot->due_date && $ot->due_date < now());
                            @endphp
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3.5 px-4 font-medium">
                                    <div class="font-bold text-gray-900">{{ $ot->student?->name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ $ot->student?->phone }} · {{ $ot->student?->code }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-primary">{{ $ot->classModel?->name ?? '—' }}</td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold {{ $isOverdue ? 'text-rose-600' : 'text-amber-600' }}">{{ number_format($ot->debt_amount) }}đ</td>
                                <td class="py-3.5 px-4 font-mono text-gray-600">{{ $ot->due_date ? $ot->due_date->format('d/m/Y') : '—' }}</td>
                                <td class="py-3.5 px-4">{{ $ot->branch?->name ?? 'Cơ sở chính' }}</td>
                                <td class="py-3.5 px-4">
                                    @if ($isOverdue)
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span> Quá hạn
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Sắp đến hạn (T-3)
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <a href="{{ route('tuition.receipts.create') }}?tuition_id={{ $ot->id }}" class="px-2.5 py-1 rounded-lg bg-primary-container hover:bg-primary-hover text-white font-bold text-xs shadow-sm transition">
                                            Thu phí
                                        </a>
                                        @if ($isOverdue)
                                            <form action="{{ route('tuition.overdue.remind', $ot->id) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="p-1 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 transition" title="Gửi tin nhắn đôn đốc nợ quá hạn">
                                                    <span class="material-symbols-outlined text-[16px]">priority_high</span>
                                                </button>
                                            </form>
                                        @else
                                            <form action="{{ route('tuition.overdue.upcoming-remind', $ot->id) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="p-1 rounded-lg border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-700 transition" title="Gửi thông báo nhắc hạn học phí sắp tới (T-3)">
                                                    <span class="material-symbols-outlined text-[16px]">notifications_active</span>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-8 text-gray-400 text-xs">Không có hồ sơ nào phù hợp với bộ lọc.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 2: Thống kê theo Chi nhánh -->
        <div x-show="activeTab === 'branch'" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-gray-50/50">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-700">Tổng hợp công nợ theo từng Cơ sở / Chi nhánh</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[11px] border-b border-gray-200">
                            <th class="py-3 px-4">Tên chi nhánh / Cơ sở</th>
                            <th class="py-3 px-4 text-center">Tổng lượt nợ</th>
                            <th class="py-3 px-4 text-center">Số ca quá hạn</th>
                            <th class="py-3 px-4 text-center">Số ca sắp đến hạn</th>
                            <th class="py-3 px-4 text-right">Tổng số tiền đọng</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($statsByBranch as $sb)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="py-3.5 px-4 font-bold text-gray-900">{{ $sb['branch_name'] }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-semibold">{{ $sb['count'] }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-rose-600">{{ $sb['overdue_count'] }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-amber-600">{{ $sb['upcoming_count'] }}</td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-blue-700">{{ number_format($sb['total_debt']) }}đ</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-6 text-gray-400 text-xs">Chưa có dữ liệu cơ sở.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 3: Thống kê theo Lớp học -->
        <div x-show="activeTab === 'class'" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-gray-50/50">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-700">Tổng hợp công nợ phân bổ theo từng Lớp học</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[11px] border-b border-gray-200">
                            <th class="py-3 px-4">Tên lớp học</th>
                            <th class="py-3 px-4">Mã lớp</th>
                            <th class="py-3 px-4 text-center">Số học viên nợ</th>
                            <th class="py-3 px-4 text-center">Quá hạn</th>
                            <th class="py-3 px-4 text-center">Sắp đến hạn</th>
                            <th class="py-3 px-4 text-right">Tổng tiền nợ lớp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($statsByClass as $sc)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="py-3.5 px-4 font-bold text-gray-900">{{ $sc['class_name'] }}</td>
                                <td class="py-3.5 px-4 font-mono text-gray-500">{{ $sc['class_code'] }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-semibold">{{ $sc['count'] }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-rose-600">{{ $sc['overdue_count'] }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-amber-600">{{ $sc['upcoming_count'] }}</td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-blue-700">{{ number_format($sc['total_debt']) }}đ</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-6 text-gray-400 text-xs">Chưa có dữ liệu lớp học.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 4: Top nợ theo Học viên -->
        <div x-show="activeTab === 'top_students'" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-gray-50/50">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-700">Top 10 Học viên có công nợ học phí cao nhất</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[11px] border-b border-gray-200">
                            <th class="py-3 px-4">Thứ hạng</th>
                            <th class="py-3 px-4">Họ và tên học viên</th>
                            <th class="py-3 px-4">Lớp đang theo học</th>
                            <th class="py-3 px-4 text-right">Tổng học phí</th>
                            <th class="py-3 px-4 text-right">Đã thanh toán</th>
                            <th class="py-3 px-4 text-right">Còn nợ lại</th>
                            <th class="py-3 px-4 text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($statsByStudent as $idx => $st)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="py-3.5 px-4 font-mono font-bold text-gray-400">#{{ $idx + 1 }}</td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-gray-900">{{ $st->student?->name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ $st->student?->phone }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-primary">{{ $st->classModel?->name ?? '—' }}</td>
                                <td class="py-3.5 px-4 text-right font-mono text-gray-700">{{ number_format($st->final_amount) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono text-emerald-600">{{ number_format($st->paid_amount) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono font-black text-rose-600">{{ number_format($st->debt_amount) }}đ</td>
                                <td class="py-3.5 px-4 text-right">
                                    <a href="{{ route('tuition.receipts.create') }}?tuition_id={{ $st->id }}" class="px-3 py-1 rounded-lg bg-primary-container hover:bg-primary-hover text-white font-bold text-xs shadow-sm transition">
                                        Lập phiếu thu
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-6 text-gray-400 text-xs">Không có hồ sơ công nợ nào.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
