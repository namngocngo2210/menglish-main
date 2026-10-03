<script setup>
/**
 * Dashboard Báo cáo đào tạo: báo cáo ngày của Học vụ, tuần của Học thuật, tháng của Giáo viên (tab theo ?tab=).
 */
import SectionTabs from '@/Components/Academic/SectionTabs.vue';
import RecordList from '@/Components/Academic/RecordList.vue';

defineOptions({ layout: { title: 'Báo cáo & sự vụ' } });

defineProps({
    tab: { type: String, default: 'daily' },
    today: { type: String, default: '' },
    classReports: { type: Array, default: () => [] },
    weeklyReports: { type: Array, default: () => [] },
    monthlyReports: { type: Array, default: () => [] },
    totalClasses: { type: Number, default: 0 },
    activeClasses: { type: Number, default: 0 },
    totalDailyReportsToday: { type: Number, default: 0 },
    attendanceRateToday: { type: Number, default: null },
    weeklyStats: { type: Object, required: true },
});
</script>

<template>
    <UiPageHeader title="Báo cáo & sự vụ" icon="monitoring" description="Báo cáo đào tạo ngày / tuần / tháng và sự vụ của các lớp, các cơ sở." />
    <SectionTabs />

    <div class="space-y-6">
        <!-- 4 thẻ số liệu -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <UiStatCard label="Lớp học đang chạy" :value="`${activeClasses} / ${totalClasses}`" tone="primary" icon="school" />
            <UiStatCard label="Tỷ lệ chuyên cần ngày" :value="attendanceRateToday === null ? 'Chưa có dữ liệu' : `${attendanceRateToday}%`" :tone="attendanceRateToday === null ? 'default' : 'success'" icon="how_to_reg" />
            <UiStatCard label="Báo cáo ngày hôm nay" :value="totalDailyReportsToday" tone="secondary" icon="assignment_turned_in" />
            <UiStatCard label="Đề xuất điều chỉnh tiến độ chờ duyệt" :value="weeklyStats.adjustments_pending" tone="secondary" icon="pending_actions" />
        </div>

        <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-xs">
            <div class="flex flex-col justify-between gap-4 px-4 pt-2 sm:flex-row sm:items-center sm:px-5">
                <UiTabs class="flex-1">
                    <UiTab :href="route('academic.dashboards.reports', { tab: 'daily' })" :active="tab === 'daily'">1. Báo cáo ngày Học vụ</UiTab>
                    <UiTab :href="route('academic.dashboards.reports', { tab: 'weekly' })" :active="tab === 'weekly'">2. Báo cáo tuần Học thuật</UiTab>
                    <UiTab :href="route('academic.dashboards.reports', { tab: 'monthly' })" :active="tab === 'monthly'">3. Báo cáo tháng Giáo viên</UiTab>
                </UiTabs>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-on-surface-variant">Hôm nay: {{ today }}</span>
                </div>
            </div>

            <!-- Tab 1: Báo cáo ngày -->
            <div v-if="tab === 'daily'" class="space-y-6 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-on-surface">Báo cáo Vận hành Ngày của Khối Học vụ</h3>
                        <p class="text-xs text-on-surface-variant">Ghi nhận sĩ số, tỷ lệ đi học, học sinh vắng và các task phát sinh trong ca học</p>
                    </div>
                </div>

                <UiDataTable>
                    <table>
                        <thead>
                            <tr>
                                <th>Cơ sở / Lớp</th>
                                <th>Người báo cáo</th>
                                <th>Sĩ số / Có mặt</th>
                                <th>Nội dung bài học &amp; Nhật ký</th>
                                <th>Trạng thái / Bổ trợ</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cr in classReports" :key="cr.id">
                                <td>
                                    <span class="font-bold">{{ cr.class_name ?? 'Chưa gắn lớp' }}</span>
                                    <span class="block font-code text-xs text-on-surface-variant">{{ cr.session_name }}</span>
                                </td>
                                <td>
                                    <span class="font-semibold">{{ cr.reporter ?? 'Chưa cập nhật' }}</span>
                                </td>
                                <td>
                                    <template v-if="cr.attendance">
                                        <UiBadge color="success" pill>{{ cr.attendance.present }} / {{ cr.attendance.total }} HV</UiBadge>
                                        <span v-if="cr.attendance.absent + cr.attendance.excused > 0" class="mt-0.5 block text-xs text-error">{{ cr.attendance.absent + cr.attendance.excused }} vắng{{ cr.attendance.excused ? ` (${cr.attendance.excused} có phép)` : '' }}</span>
                                    </template>
                                    <span v-else class="text-xs text-on-surface-subtle">Chưa có điểm danh</span>
                                </td>
                                <td class="max-w-xs">
                                    <p class="truncate font-medium">{{ cr.topics_learned }}</p>
                                    <p v-if="cr.teaching_log" class="line-clamp-1 text-xs text-on-surface-variant">{{ cr.teaching_log }}</p>
                                </td>
                                <td>
                                    <UiBadge color="info" :dot="false">{{ cr.status_label }}</UiBadge>
                                    <span v-if="cr.student_supports_count > 0" class="mt-0.5 block text-xs text-on-surface-variant">{{ cr.student_supports_count }} HV cần bổ trợ</span>
                                </td>
                                <td class="font-code text-xs text-on-surface-variant">{{ cr.created_at }}</td>
                            </tr>
                            <tr v-if="!classReports.length">
                                <td colspan="6"><UiEmptyState title="Chưa có báo cáo trực lớp nào." /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </div>

            <!-- Tab 2: Báo cáo tuần -->
            <div v-if="tab === 'weekly'" class="space-y-6 p-6">
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
                            <span class="font-bold text-on-surface">{{ weeklyStats.adjustments_week }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span>Đang chờ duyệt:</span>
                            <span class="font-bold text-warning">{{ weeklyStats.adjustments_pending }}</span>
                        </div>
                    </div>

                    <div class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
                        <span class="text-xs font-bold uppercase text-on-surface-variant">Khảo thí &amp; Big Test</span>
                        <div class="flex items-center justify-between text-xs">
                            <span>Big Test trong 7 ngày tới:</span>
                            <span class="font-bold text-on-surface">{{ weeklyStats.big_tests_upcoming }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span>Đề đã phân phối trong tuần:</span>
                            <span class="font-bold text-on-surface">{{ weeklyStats.big_tests_distributed }}</span>
                        </div>
                    </div>

                    <div class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
                        <span class="text-xs font-bold uppercase text-on-surface-variant">Lớp đang chạy</span>
                        <div class="flex items-center justify-between text-xs">
                            <span>Đang học / Tổng số lớp:</span>
                            <span class="font-bold text-on-surface">{{ activeClasses }} / {{ totalClasses }}</span>
                        </div>
                    </div>
                </div>

                <UiDataTable>
                    <RecordList :records="weeklyReports" empty="Chưa có báo cáo tuần nào." />
                </UiDataTable>
            </div>

            <!-- Tab 3: Báo cáo tháng -->
            <div v-if="tab === 'monthly'" class="space-y-6 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-on-surface">Báo cáo tháng / quý</h3>
                        <p class="text-xs text-on-surface-variant">Báo cáo tháng của giáo viên, báo cáo tháng và quý của Học thuật</p>
                    </div>
                </div>

                <UiDataTable>
                    <RecordList :records="monthlyReports" empty="Chưa có báo cáo tháng nào." />
                </UiDataTable>
            </div>
        </div>
    </div>
</template>
