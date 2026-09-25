<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Dashboard Báo cáo Đào tạo & Học vụ</h1>
                <p class="text-xs text-gray-500 mt-1">Tổng hợp báo cáo ngày của Học vụ, báo cáo tuần Học thuật và báo cáo tháng của Giáo viên</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('academic.dashboards.incidents') }}" class="px-3.5 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 text-xs font-semibold rounded-xl shadow-xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px] text-amber-500">warning</span>
                    <span>Nhật ký Sự vụ cơ sở</span>
                </a>

            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- 4 Metric Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-orange-50 text-primary flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">school</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Lớp học đang chạy</span>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $activeClasses }} <span class="text-xs text-gray-400 font-normal">/ {{ $totalClasses }}</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">how_to_reg</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Tỷ lệ chuyên cần ngày</span>
                    @if ($attendanceRateToday === null)
                        <p class="text-sm font-semibold text-gray-400">Chưa có dữ liệu</p>
                    @else
                        <p class="text-2xl font-extrabold text-emerald-600">{{ $attendanceRateToday }}%</p>
                    @endif
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">assignment_turned_in</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Báo cáo ngày hôm nay</span>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $totalDailyReportsToday }}</p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">pending_actions</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Đề xuất điều chỉnh tiến độ chờ duyệt</span>
                    <p class="text-2xl font-extrabold text-purple-600">{{ $weeklyStats['adjustments_pending'] }}</p>
                </div>
            </div>
        </div>

        <!-- Filter & Tabs -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
            <div class="border-b border-gray-200 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Tabs -->
                <div class="flex items-center gap-2 overflow-x-auto pb-1 sm:pb-0">
                    <a href="{{ route('academic.dashboards.reports', ['tab' => 'daily']) }}"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition whitespace-nowrap {{ $tab === 'daily' ? 'bg-primary-container text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        1. Báo cáo ngày Học vụ
                    </a>
                    <a href="{{ route('academic.dashboards.reports', ['tab' => 'weekly']) }}"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition whitespace-nowrap {{ $tab === 'weekly' ? 'bg-primary-container text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        2. Báo cáo tuần Học thuật
                    </a>
                    <a href="{{ route('academic.dashboards.reports', ['tab' => 'monthly']) }}"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition whitespace-nowrap {{ $tab === 'monthly' ? 'bg-primary-container text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        3. Báo cáo tháng Giáo viên
                    </a>
                </div>

                <!-- Date & Branch Filter -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 font-medium">Hôm nay: {{ now()->format('d/m/Y') }}</span>
                </div>
            </div>

            <!-- Tab 1 Content: Daily Reports -->
            @if($tab === 'daily')
                <div class="p-6 space-y-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-gray-900 text-sm">Báo cáo Vận hành Ngày của Khối Học vụ</h3>
                            <p class="text-xs text-gray-500">Ghi nhận sĩ số, tỷ lệ đi học, học sinh vắng và các task phát sinh trong ca học</p>
                        </div>

                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200">
                                <tr>
                                    <th class="py-3 px-4">Cơ sở / Lớp</th>
                                    <th class="py-3 px-4">Người báo cáo</th>
                                    <th class="py-3 px-4">Sĩ số / Có mặt</th>
                                    <th class="py-3 px-4">Nội dung bài học & Nhật ký</th>
                                    <th class="py-3 px-4">Trạng thái / Bổ trợ</th>
                                    <th class="py-3 px-4">Thời gian</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($classReports as $cr)
                                    <tr class="hover:bg-gray-50/80 transition">
                                        <td class="py-3 px-4">
                                            <span class="font-bold text-gray-900">{{ $cr->classModel?->name ?? 'Chưa gắn lớp' }}</span>
                                            <span class="block text-[11px] text-gray-500 font-mono">{{ $cr->session_name }}</span>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="font-semibold text-gray-800">{{ $cr->reporter?->name ?? 'Chưa cập nhật' }}</span>
                                        </td>
                                        <td class="py-3 px-4">
                                            @php $att = $reportAttendance[$cr->id] ?? null; @endphp
                                            @if ($att)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> {{ $att['present'] }} / {{ $att['total'] }} HV
                                                </span>
                                                @if ($att['absent'] + $att['excused'] > 0)
                                                    <span class="block text-[10px] text-rose-500 mt-0.5">{{ $att['absent'] + $att['excused'] }} vắng{{ $att['excused'] ? ' (' . $att['excused'] . ' có phép)' : '' }}</span>
                                                @endif
                                            @else
                                                <span class="text-[11px] text-gray-400">Chưa có điểm danh</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 max-w-xs">
                                            <p class="font-medium text-gray-900 truncate">{{ $cr->topics_learned }}</p>
                                            @if ($cr->teaching_log)
                                                <p class="text-[11px] text-gray-500 line-clamp-1">{{ $cr->teaching_log }}</p>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-blue-50 text-blue-700 border border-blue-200">{{ $cr->status_label }}</span>
                                            @if ($cr->student_supports_count > 0)
                                                <span class="block text-[10px] text-gray-500 mt-0.5">{{ $cr->student_supports_count }} HV cần bổ trợ</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 font-mono text-gray-500 text-[11px]">
                                            {{ $cr->created_at->format('H:i d/m') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-8 text-center text-gray-400">Chưa có báo cáo trực lớp nào.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <!-- Tab 2 Content: Weekly Reports -->
            @if($tab === 'weekly')
                <div class="p-6 space-y-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-gray-900 text-sm">Báo cáo Tiến độ Tuần của Khối Học thuật</h3>
                            <p class="text-xs text-gray-500">Kiểm soát tiến độ syllabus, kết quả dự giờ, kỳ thi Big Test định kỳ</p>
                        </div>

                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
                            <span class="text-xs font-bold text-gray-700 uppercase">Điều chỉnh tiến độ Syllabus</span>
                            <div class="flex items-center justify-between text-xs">
                                <span>Yêu cầu mới trong tuần:</span>
                                <span class="font-bold text-gray-900">{{ $weeklyStats['adjustments_week'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span>Đang chờ duyệt:</span>
                                <span class="font-bold text-amber-600">{{ $weeklyStats['adjustments_pending'] }}</span>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
                            <span class="text-xs font-bold text-gray-700 uppercase">Khảo thí & Big Test</span>
                            <div class="flex items-center justify-between text-xs">
                                <span>Big Test trong 7 ngày tới:</span>
                                <span class="font-bold text-gray-900">{{ $weeklyStats['big_tests_upcoming'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span>Đề đã phân phối trong tuần:</span>
                                <span class="font-bold text-gray-900">{{ $weeklyStats['big_tests_distributed'] }}</span>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
                            <span class="text-xs font-bold text-gray-700 uppercase">Lớp đang chạy</span>
                            <div class="flex items-center justify-between text-xs">
                                <span>Đang học / Tổng số lớp:</span>
                                <span class="font-bold text-gray-900">{{ $activeClasses }} / {{ $totalClasses }}</span>
                            </div>
                        </div>
                    </div>

                    @include('academic.dashboards.partials.record-list', ['records' => $weeklyReports, 'empty' => 'Chưa có báo cáo tuần nào.'])
                </div>
            @endif

            <!-- Tab 3 Content: Monthly Reports -->
            @if($tab === 'monthly')
                <div class="p-6 space-y-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-gray-900 text-sm">Báo cáo Tổng kết Tháng của Giáo viên</h3>
                            <p class="text-xs text-gray-500">Đánh giá chất lượng lớp học, hoàn thành chặng học, phản hồi học sinh</p>
                        </div>

                    </div>

                    @include('academic.dashboards.partials.record-list', ['records' => $monthlyReports, 'empty' => 'Chưa có báo cáo tháng nào.'])
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
