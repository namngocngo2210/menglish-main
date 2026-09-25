<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-600">payments</span>
                    Danh sách Học viên &amp; Thu học phí (Database)
                </h1>
                <p class="text-xs text-gray-500">Quản lý theo dõi công nợ, hạn nộp học phí và lập phiếu thu trực tiếp</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('tuition.import') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">upload_file</span>
                    <span>Nhập Excel</span>
                </a>
                <a href="{{ route('tuition.receipts.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_card</span>
                    <span>Lập Phiếu thu mới</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <!-- Stats widget from real DB -->
        @php
            $allTuitions = \App\Models\StudentTuition::all();
            $totalFinal = $allTuitions->sum('final_amount');
            $totalPaid = $allTuitions->sum('paid_amount');
            $totalDebt = $allTuitions->sum('debt_amount');
            $overdueCount = $allTuitions->where('status', 'overdue')->count();
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Tổng học phí niêm yết</span>
                <div class="text-2xl font-extrabold text-gray-900 mt-1">{{ number_format($totalFinal) }}đ</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Đã thực thu (Tiền về TK)</span>
                <div class="text-2xl font-extrabold text-emerald-600 mt-1">{{ number_format($totalPaid) }}đ</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Công nợ còn lại</span>
                <div class="text-2xl font-extrabold text-amber-600 mt-1">{{ number_format($totalDebt) }}đ</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Học viên quá hạn</span>
                <div class="text-2xl font-extrabold text-rose-600 mt-1">{{ $overdueCount }} học viên</div>
            </div>
        </div>

        <!-- Student Tuition Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <form method="GET" action="{{ route('tuition.students') }}" class="p-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2 flex-1 min-w-[260px]">
                    <div class="relative w-full max-w-sm">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm theo tên học viên, mã HV, SĐT..." class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary" />
                    </div>
                    <select name="branch_id" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                        <option value="">Tất cả cơ sở</option>
                        @foreach ($branches as $br)
                            <option value="{{ $br->id }}" {{ request('branch_id') == $br->id ? 'selected' : '' }}>{{ $br->name }}</option>
                        @endforeach
                    </select>
                    <select name="class_id" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                        <option value="">Tất cả lớp học</option>
                        @foreach ($classes as $cl)
                            <option value="{{ $cl->id }}" {{ request('class_id') == $cl->id ? 'selected' : '' }}>{{ $cl->name }} ({{ $cl->code }})</option>
                        @endforeach
                    </select>
                    <select name="status" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                        <option value="">Tất cả trạng thái</option>
                        <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Đã hoàn thành</option>
                        <option value="partial" {{ request('status') === 'partial' ? 'selected' : '' }}>Đang nợ (Đã cọc)</option>
                        <option value="overdue" {{ request('status') === 'overdue' ? 'selected' : '' }}>Quá hạn</option>
                        <option value="unpaid" {{ request('status') === 'unpaid' ? 'selected' : '' }}>Chưa nộp</option>
                    </select>
                    <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition">Lọc</button>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1020px]">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 min-w-[180px] whitespace-nowrap">Học viên</th>
                            <th class="py-3 px-4 min-w-[150px] whitespace-nowrap">Lớp học</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Cơ sở</th>
                            <th class="py-3 px-4 text-right min-w-[110px] whitespace-nowrap">Tổng học phí</th>
                            <th class="py-3 px-4 text-right min-w-[110px] whitespace-nowrap">Đã nộp</th>
                            <th class="py-3 px-4 text-right min-w-[110px] whitespace-nowrap">Còn nợ</th>
                            <th class="py-3 px-4 min-w-[110px] whitespace-nowrap">Hạn nộp</th>
                            <th class="py-3 px-4 min-w-[120px] whitespace-nowrap">Trạng thái</th>
                            <th class="py-3 px-4 text-right min-w-[100px] whitespace-nowrap">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($tuitions as $t)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900 whitespace-nowrap">{{ $t->student?->name }}</div>
                                    <div class="text-[10px] text-gray-400 font-mono whitespace-nowrap">{{ $t->student?->code }} · {{ $t->student?->phone }}</div>
                                </td>
                                <td class="py-3 px-4 font-semibold text-primary whitespace-nowrap">{{ $t->classModel?->name ?? 'Chưa gán lớp' }}</td>
                                <td class="py-3 px-4 whitespace-nowrap">{{ $t->branch?->name ?? '—' }}</td>
                                <td class="py-3 px-4 text-right font-mono font-semibold whitespace-nowrap">{{ number_format($t->final_amount) }}đ</td>
                                <td class="py-3 px-4 text-right font-mono font-bold text-emerald-600 whitespace-nowrap">{{ number_format($t->paid_amount) }}đ</td>
                                <td class="py-3 px-4 text-right font-mono font-bold whitespace-nowrap {{ $t->debt_amount > 0 ? 'text-rose-600' : 'text-gray-400' }}">
                                    {{ number_format($t->debt_amount) }}đ
                                </td>
                                <td class="py-3 px-4 font-mono text-[11px] whitespace-nowrap">{{ $t->due_date ? $t->due_date->format('d/m/Y') : '—' }}</td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $t->status_badge }} whitespace-nowrap inline-block">
                                        {{ $t->status_label }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    @if ($t->debt_amount > 0)
                                        <a href="{{ route('tuition.receipts.create') }}" class="px-2.5 py-1 bg-primary text-white rounded-lg text-[11px] font-bold hover:bg-primary-hover shadow-sm transition">
                                            Thu tiền
                                        </a>
                                    @else
                                        <span class="text-[11px] text-emerald-600 font-bold flex items-center justify-end gap-1">
                                            <span class="material-symbols-outlined text-sm">verified</span>
                                            Đã tất toán
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-8 text-gray-400 text-xs">Không tìm thấy bản ghi học phí nào.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$tuitions" />
        </div>
    </div>
</x-app-layout>
