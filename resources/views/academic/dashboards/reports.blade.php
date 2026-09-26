<x-app-layout>
    <x-ui.page-header title="Dashboard Báo cáo Đào tạo & Học vụ">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="warning" :href="route('academic.dashboards.incidents')">Nhật ký Sự vụ cơ sở</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    <div class="space-y-6">
        {{-- 4 Metric Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Lớp học đang chạy" :value="$activeClasses.' / '.$totalClasses" tone="primary" icon="school" />
            <x-ui.stat-card label="Tỷ lệ chuyên cần ngày" :value="$attendanceRateToday === null ? 'Chưa có dữ liệu' : $attendanceRateToday.'%'"
                            :tone="$attendanceRateToday === null ? 'default' : 'success'" icon="how_to_reg" />
            <x-ui.stat-card label="Báo cáo ngày hôm nay" :value="$totalDailyReportsToday" tone="secondary" icon="assignment_turned_in" />
            <x-ui.stat-card label="Đề xuất điều chỉnh tiến độ chờ duyệt" :value="$weeklyStats['adjustments_pending']" tone="secondary" icon="pending_actions" />
        </div>

        {{-- Filter & Tabs --}}
        <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-xs">
            <div class="flex flex-col justify-between gap-4 px-4 pt-2 sm:flex-row sm:items-center sm:px-5">
                {{-- Tabs --}}
                <x-ui.tabs class="flex-1">
                    <x-ui.tab :href="route('academic.dashboards.reports', ['tab' => 'daily'])" :active="$tab === 'daily'">1. Báo cáo ngày Học vụ</x-ui.tab>
                    <x-ui.tab :href="route('academic.dashboards.reports', ['tab' => 'weekly'])" :active="$tab === 'weekly'">2. Báo cáo tuần Học thuật</x-ui.tab>
                    <x-ui.tab :href="route('academic.dashboards.reports', ['tab' => 'monthly'])" :active="$tab === 'monthly'">3. Báo cáo tháng Giáo viên</x-ui.tab>
                </x-ui.tabs>

                {{-- Date & Branch Filter --}}
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-on-surface-variant">Hôm nay: {{ now()->format('d/m/Y') }}</span>
                </div>
            </div>

            {{-- Tab 1 Content: Daily Reports --}}
            @if($tab === 'daily')
                <div class="space-y-6 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Báo cáo Vận hành Ngày của Khối Học vụ</h3>
                            <p class="text-xs text-on-surface-variant">Ghi nhận sĩ số, tỷ lệ đi học, học sinh vắng và các task phát sinh trong ca học</p>
                        </div>
                    </div>

                    <x-ui.data-table>
                        <table>
                            <thead>
                                <tr>
                                    <th>Cơ sở / Lớp</th>
                                    <th>Người báo cáo</th>
                                    <th>Sĩ số / Có mặt</th>
                                    <th>Nội dung bài học & Nhật ký</th>
                                    <th>Trạng thái / Bổ trợ</th>
                                    <th>Thời gian</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($classReports as $cr)
                                    <tr>
                                        <td>
                                            <span class="font-bold">{{ $cr->classModel?->name ?? 'Chưa gắn lớp' }}</span>
                                            <span class="block font-code text-[11px] text-on-surface-variant">{{ $cr->session_name }}</span>
                                        </td>
                                        <td>
                                            <span class="font-semibold">{{ $cr->reporter?->name ?? 'Chưa cập nhật' }}</span>
                                        </td>
                                        <td>
                                            @php $att = $reportAttendance[$cr->id] ?? null; @endphp
                                            @if ($att)
                                                <x-ui.badge color="success" pill>{{ $att['present'] }} / {{ $att['total'] }} HV</x-ui.badge>
                                                @if ($att['absent'] + $att['excused'] > 0)
                                                    <span class="mt-0.5 block text-[10px] text-error">{{ $att['absent'] + $att['excused'] }} vắng{{ $att['excused'] ? ' (' . $att['excused'] . ' có phép)' : '' }}</span>
                                                @endif
                                            @else
                                                <span class="text-[11px] text-on-surface-variant/70">Chưa có điểm danh</span>
                                            @endif
                                        </td>
                                        <td class="max-w-xs">
                                            <p class="truncate font-medium">{{ $cr->topics_learned }}</p>
                                            @if ($cr->teaching_log)
                                                <p class="line-clamp-1 text-[11px] text-on-surface-variant">{{ $cr->teaching_log }}</p>
                                            @endif
                                        </td>
                                        <td>
                                            <x-ui.badge color="info" :dot="false">{{ $cr->status_label }}</x-ui.badge>
                                            @if ($cr->student_supports_count > 0)
                                                <span class="mt-0.5 block text-[10px] text-on-surface-variant">{{ $cr->student_supports_count }} HV cần bổ trợ</span>
                                            @endif
                                        </td>
                                        <td class="font-code text-[11px] text-on-surface-variant">
                                            {{ $cr->created_at->format('H:i d/m') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6"><x-ui.empty-state title="Chưa có báo cáo trực lớp nào." /></td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.data-table>
                </div>
            @endif

            {{-- Tab 2 Content: Weekly Reports --}}
            @if($tab === 'weekly')
                <div class="space-y-6 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Báo cáo Tiến độ Tuần của Khối Học thuật</h3>
                            <p class="text-xs text-on-surface-variant">Kiểm soát tiến độ syllabus, kết quả dự giờ, kỳ thi Big Test định kỳ</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
                            <span class="text-xs font-bold uppercase text-on-surface-variant">Điều chỉnh tiến độ Syllabus</span>
                            <div class="flex items-center justify-between text-xs">
                                <span>Yêu cầu mới trong tuần:</span>
                                <span class="font-bold text-on-surface">{{ $weeklyStats['adjustments_week'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span>Đang chờ duyệt:</span>
                                <span class="font-bold text-warning">{{ $weeklyStats['adjustments_pending'] }}</span>
                            </div>
                        </div>

                        <div class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
                            <span class="text-xs font-bold uppercase text-on-surface-variant">Khảo thí & Big Test</span>
                            <div class="flex items-center justify-between text-xs">
                                <span>Big Test trong 7 ngày tới:</span>
                                <span class="font-bold text-on-surface">{{ $weeklyStats['big_tests_upcoming'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span>Đề đã phân phối trong tuần:</span>
                                <span class="font-bold text-on-surface">{{ $weeklyStats['big_tests_distributed'] }}</span>
                            </div>
                        </div>

                        <div class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
                            <span class="text-xs font-bold uppercase text-on-surface-variant">Lớp đang chạy</span>
                            <div class="flex items-center justify-between text-xs">
                                <span>Đang học / Tổng số lớp:</span>
                                <span class="font-bold text-on-surface">{{ $activeClasses }} / {{ $totalClasses }}</span>
                            </div>
                        </div>
                    </div>

                    <x-ui.data-table>
                        @include('academic.dashboards.partials.record-list', ['records' => $weeklyReports, 'empty' => 'Chưa có báo cáo tuần nào.'])
                    </x-ui.data-table>
                </div>
            @endif

            {{-- Tab 3 Content: Monthly Reports --}}
            @if($tab === 'monthly')
                <div class="space-y-6 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Báo cáo Tổng kết Tháng của Giáo viên</h3>
                            <p class="text-xs text-on-surface-variant">Đánh giá chất lượng lớp học, hoàn thành chặng học, phản hồi học sinh</p>
                        </div>
                    </div>

                    <x-ui.data-table>
                        @include('academic.dashboards.partials.record-list', ['records' => $monthlyReports, 'empty' => 'Chưa có báo cáo tháng nào.'])
                    </x-ui.data-table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
