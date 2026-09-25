<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('classes.academic-overview') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">table_view</span>
                        Danh sách lớp chi tiết Học thuật
                    </h1>
                    <p class="text-xs text-gray-500">Bảng theo dõi tình trạng lớp học, tiến độ syllabus, Big Test và lịch dự giờ chuyên môn.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('classes.academic-detail') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-dark transition">
                    <span class="material-symbols-outlined text-[18px]">class</span>
                    <span>Chi tiết lớp học</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('classes.partials.flow-header', ['activeStep' => 5])

    <div class="space-y-6">
        <!-- Header & Toolbar (Exact Match BA) -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
            <div>
                <h2 class="text-base font-bold text-gray-900">Danh sách lớp học</h2>
                <p class="text-xs text-gray-500">Quản lý và theo dõi trạng thái các lớp học hiện tại.</p>
            </div>

            <!-- Toolbar Filters -->
            <form method="GET" action="{{ route('classes.academic-list') }}" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full md:w-auto">
                @if ($levelFilter)
                    <input type="hidden" name="level" value="{{ $levelFilter }}">
                @endif
                <div class="relative w-full sm:w-64">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">search</span>
                    <input type="text"
                           name="search"
                           value="{{ $search }}"
                           class="w-full pl-9 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-xs font-medium text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition"
                           placeholder="Tìm kiếm lớp..."/>
                </div>

                <div class="flex gap-2 w-full sm:w-auto">
                    <select name="branch_id"
                            onchange="this.form.submit()"
                            class="w-full sm:w-auto px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container cursor-pointer">
                        <option value="">Tất cả Chi nhánh</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ $branchFilter == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>

                    <select name="program"
                            onchange="this.form.submit()"
                            class="w-full sm:w-auto px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container cursor-pointer">
                        <option value="">Tất cả Chương trình</option>
                        @foreach($programs as $program)
                            <option value="{{ $program }}" @selected($programFilter === $program)>{{ $program }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>

        <!-- Data Table Card -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider sticky top-0 z-10 shadow-2xs">
                            <th class="py-3.5 px-4">Tên lớp</th>
                            <th class="py-3.5 px-4">Lịch học</th>
                            <th class="py-3.5 px-4">CM quản lý</th>
                            <th class="py-3.5 px-4">Giáo viên</th>
                            <th class="py-3.5 px-4">Ngày khai giảng</th>
                            <th class="py-3.5 px-4">Dự kiến kết thúc</th>
                            <th class="py-3.5 px-4 text-center">SL HV<br/><span class="text-[9px] font-normal lowercase">(BĐ/HT)</span></th>
                            <th class="py-3.5 px-4">Tình trạng lớp</th>
                            <th class="py-3.5 px-4">Tiến độ</th>
                            <th class="py-3.5 px-4 text-center">Tình trạng sĩ số</th>
                            <th class="py-3.5 px-4 text-center">Big Test</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($classes as $c)
                            @php
                                $studentCount = $c->roster_count;
                                $capacity = $c->max_capacity;
                                $totalSessions = (int) $c->total_sessions_count;
                                $doneSessions = (int) $c->done_sessions_count;
                                $progress = $totalSessions > 0 ? (int) round($doneSessions * 100 / $totalSessions) : null;
                                $classBigTests = $bigTests->get($c->id, collect());
                                $statusMap = [
                                    'active' => ['Đang hoạt động', 'bg-emerald-100 text-emerald-800'],
                                    'upcoming' => ['Sắp khai giảng', 'bg-blue-100 text-blue-800'],
                                    'pending_schedule' => ['Chờ lịch', 'bg-amber-100 text-amber-800'],
                                    'completed' => ['Đã kết thúc', 'bg-gray-100 text-gray-700'],
                                ];
                                [$statusLabel, $statusClass] = $statusMap[$c->status] ?? [\App\Support\StatusLabel::for($c->status), 'bg-gray-100 text-gray-700'];
                            @endphp
                            <tr class="hover:bg-gray-50/80 transition-colors">
                                <td class="py-3.5 px-4">
                                    <a href="{{ route('classes.academic-detail', ['id' => $c->id]) }}" class="font-bold text-secondary hover:text-primary transition font-mono">
                                        {{ $c->code }}
                                    </a>
                                    <div class="text-[10px] text-gray-500 font-normal truncate max-w-[140px]">{{ $c->name }}</div>
                                </td>
                                <td class="py-3.5 px-4 text-gray-600 font-medium">
                                    {{ $c->schedule_text ?: 'Chưa cập nhật' }}
                                </td>
                                <td class="py-3.5 px-4 text-gray-800">
                                    {{ $c->assistant?->name ?? 'Chưa phân công' }}
                                </td>
                                <td class="py-3.5 px-4 text-gray-800 font-medium">
                                    {{ $c->teacher?->name ?? 'Chưa phân công' }}
                                </td>
                                <td class="py-3.5 px-4 text-gray-600 font-mono">
                                    {{ $c->start_date?->format('d/m/Y') ?? 'Chưa cập nhật' }}
                                </td>
                                <td class="py-3.5 px-4 text-gray-600 font-mono">
                                    {{ $c->end_date?->format('d/m/Y') ?? 'Chưa cập nhật' }}
                                </td>
                                <td class="py-3.5 px-4 text-center font-bold font-mono">
                                    {{ $studentCount }}/{{ $capacity ?? '—' }}
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold {{ $statusClass }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="py-3.5 px-4">
                                    @if ($progress === null)
                                        <span class="text-[10px] text-gray-400">Chưa có lịch học</span>
                                    @else
                                        <div class="w-24 h-1.5 bg-gray-100 rounded-full overflow-hidden mb-1">
                                            <div class="h-full bg-secondary rounded-full" style="width: {{ $progress }}%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-500 font-mono block">{{ $progress }}% ({{ $doneSessions }}/{{ $totalSessions }} buổi)</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    @if ($capacity === null)
                                        <span class="text-[10px] text-gray-400">—</span>
                                    @elseif ($studentCount >= $capacity)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-700">Đủ</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-700">Còn {{ $capacity - $studentCount }} chỗ</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    @if ($classBigTests->isEmpty())
                                        <span class="text-[10px] text-gray-400">Chưa có</span>
                                    @else
                                        <div class="flex items-center justify-center gap-1">
                                            @foreach ($classBigTests as $bt)
                                                @php $btDone = $bt->scheduled_at && $bt->scheduled_at->isPast(); @endphp
                                                <span class="w-5 h-5 rounded flex items-center justify-center text-[10px] font-bold {{ $btDone ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500' }}"
                                                      title="{{ $bt->title }}{{ $bt->scheduled_at ? ' — ' . $bt->scheduled_at->format('d/m/Y') : '' }}">{{ $loop->iteration }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="p-8 text-center text-gray-400 text-xs">
                                    Không tìm thấy lớp học nào thỏa mãn điều kiện tìm kiếm.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($classes->hasPages())
                <div class="border-t border-gray-100 px-4 py-3">{{ $classes->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
