<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">school</span>
                    Danh sách Hồ sơ Học viên (Database)
                </h1>
                <p class="text-xs text-gray-500">Quản lý toàn bộ thông tin học tập, chuyên cần, bài tập về nhà và tiến độ điểm số</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('students.enrollments') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-orange-50 text-primary border border-orange-200 text-xs font-semibold hover:bg-orange-100 transition">
                    <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                    <span>Tiếp nhận &amp; Xếp lớp</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <!-- Search & Filter -->
        <form method="GET" action="{{ route('students.index') }}" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-200 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 flex-1">
                <div class="relative min-w-[240px]">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm theo tên học viên, mã HV, SĐT..." class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary" />
                </div>
                <select name="branch_id" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                    <option value="">Tất cả cơ sở</option>
                    @foreach ($branches as $br)
                        <option value="{{ $br->id }}" {{ request('branch_id') == $br->id ? 'selected' : '' }}>{{ $br->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                    <option value="">Tất cả trạng thái</option>
                    <option value="studying" {{ request('status') === 'studying' ? 'selected' : '' }}>Đang học</option>
                    <option value="graduated" {{ request('status') === 'graduated' ? 'selected' : '' }}>Đã tốt nghiệp</option>
                    <option value="deferred" {{ request('status') === 'deferred' ? 'selected' : '' }}>Bảo lưu</option>
                    <option value="dropped" {{ request('status') === 'dropped' ? 'selected' : '' }}>Rút hồ sơ</option>
                </select>
                <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition">Lọc</button>
            </div>
            <div class="text-xs text-gray-500">
                Tổng cộng <strong class="text-gray-900">{{ $students->total() }}</strong> học viên
            </div>
        </form>

        <!-- Students Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1020px]">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 min-w-[180px] whitespace-nowrap">Mã &amp; Học viên</th>
                            <th class="py-3 px-4 min-w-[120px] whitespace-nowrap">Số điện thoại</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Cơ sở</th>
                            <th class="py-3 px-4 min-w-[150px] whitespace-nowrap">Lớp đang học</th>
                            <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Mục tiêu &amp; Điểm số</th>
                            <th class="py-3 px-4 min-w-[130px] whitespace-nowrap">Chuyên cần</th>
                            <th class="py-3 px-4 min-w-[120px] whitespace-nowrap">Trạng thái</th>
                            <th class="py-3 px-4 text-right min-w-[100px] whitespace-nowrap">Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($students as $st)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3.5 px-4 font-medium whitespace-nowrap">
                                    <a href="{{ route('students.show', $st->id) }}" class="font-bold text-gray-900 text-sm hover:text-primary transition flex items-center gap-2 whitespace-nowrap">
                                        <span class="w-7 h-7 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                            {{ Str::substr($st->name, 0, 1) }}
                                        </span>
                                        <span class="whitespace-nowrap">{{ $st->name }}</span>
                                    </a>
                                    <div class="text-[10px] text-gray-400 font-mono pl-9 whitespace-nowrap">{{ $st->code }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-800 whitespace-nowrap">{{ $st->phone }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">{{ $st->branch?->name ?? '—' }}</td>
                                <td class="py-3.5 px-4 font-semibold text-primary whitespace-nowrap">
                                    {{ $st->currentClass?->name ?? 'Chưa xếp lớp' }}
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900 whitespace-nowrap">{{ $st->target }}</div>
                                    <div class="text-[10px] text-emerald-600 font-semibold whitespace-nowrap">Đầu vào: {{ $st->entrance_score ?? 'Chưa test' }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-medium whitespace-nowrap">
                                    <div class="whitespace-nowrap">{{ $st->attendance_rate }}</div>
                                    <div class="text-[10px] text-gray-400 whitespace-nowrap">BTVN: {{ $st->homework_rate }}%</div>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $st->status_badge }} whitespace-nowrap inline-block">
                                        {{ $st->status_label }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <a href="{{ route('students.show', $st->id) }}" class="text-primary hover:underline font-semibold whitespace-nowrap">
                                        Xem hồ sơ
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-8 text-gray-400 text-xs">Chưa có học viên nào trong cơ sở dữ liệu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$students" />
        </div>
    </div>
</x-app-layout>
