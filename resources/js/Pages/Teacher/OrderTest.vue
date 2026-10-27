<script setup>
/**
 * Chặng đang dạy + lịch sử order đề của lớp (mockup 03_Cong_Giao_Vien/07). Order đề gửi ở nút ORDER theo mốc syllabus
 * (teacher.orders), không còn form riêng ở đây.
 */
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';

defineOptions({ layout: { title: 'Order đề test' } });

defineProps({
    classroom: { type: Object, required: true },
    openAssignment: { type: Object, default: null },
    closedAssignments: { type: Array, default: () => [] },
    orderUrl: { type: String, required: true },
    requests: { type: Array, default: () => [] },
});
</script>

<template>
    <UiPageHeader :title="'Order đề test — ' + classroom.name" :back="route('syllabus.teaching-stages')">
        <template #meta>{{ classroom.program }} · Gửi yêu cầu cấp đề Mini/Big Test cho chặng đang dạy tới Ban Học thuật</template>
    </UiPageHeader>

    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Chặng đang dạy -->
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-2 font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">flag</span>
                    Chặng đang dạy
                </h2>
                <div v-if="openAssignment" class="space-y-xs rounded-lg border border-primary-container/40 bg-primary-fixed/20 p-md">
                    <p class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">flag</span>{{ openAssignment.label }}</p>
                    <p class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">calendar_today</span>Bắt đầu: {{ openAssignment.started_at }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">{{ openAssignment.assigned_chapters }}</p>
                </div>
                <div v-else class="flex flex-col items-center gap-xs py-lg text-center">
                    <span class="material-symbols-outlined text-[36px] text-on-surface-variant">inventory_2</span>
                    <p class="font-body-medium text-body-medium text-on-surface">Chưa được giao chặng nào</p>
                    <p class="font-body-small text-body-small text-on-surface-variant">Hiện tại lớp chưa có chặng học nào đang mở. Vui lòng liên hệ Quản lý chuyên môn nếu có sai sót.</p>
                </div>
                <div v-if="closedAssignments.length">
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Chặng đã học</p>
                    <ul class="space-y-1 font-body-small text-body-small text-on-surface-variant">
                        <li v-for="asg in closedAssignments" :key="asg.id" class="flex items-center gap-xs"><span class="material-symbols-outlined text-[16px] text-tertiary">check_circle</span>{{ asg.label }} · đóng {{ asg.closed_at }}</li>
                    </ul>
                </div>
            </section>

            <!-- Order đề: chỉ ở nút ORDER theo mốc syllabus (bỏ form riêng để không order trùng, chủ dự án 27/10/2026). -->
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-2 font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">assignment_add</span>
                    Order đề test
                </h2>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Đề Mini / Big Test order theo mốc của syllabus: đến mốc, nút ORDER báo đỏ và hệ thống tự tính ngày thi, hạn xử lý của Học thuật.
                </p>
                <UiButton icon="send" class="w-full" :href="orderUrl">Mở ORDER theo syllabus</UiButton>
            </section>
        </div>

        <!-- Lịch sử yêu cầu -->
        <UiDataTable min-width="760px">
            <template #header>
                <h2 class="flex items-center gap-2 font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">history</span>
                    Yêu cầu đã gửi cho lớp này
                </h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Loại đề</th>
                        <th>Chặng</th>
                        <th>Ngày thi / Hạn xử lý</th>
                        <th>Ghi chú</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="req in requests" :key="req.id">
                        <td class="font-mono text-on-surface-variant">{{ req.created_at }}</td>
                        <td class="font-semibold">{{ req.type_label }}</td>
                        <td class="font-semibold text-on-surface">{{ req.stage_label }}</td>
                        <td class="text-on-surface-variant">
                            <div>Thi: {{ req.exam_date ?? '—' }}</div>
                            <div class="font-caption text-caption">Hạn: {{ req.due_date ?? '—' }}</div>
                        </td>
                        <td class="text-on-surface-variant">{{ req.note || '—' }}</td>
                        <td class="space-y-1">
                            <UiBadge :color="req.status_color">{{ req.status_label }}</UiBadge>
                            <template v-if="req.status === 'approved'">
                                <a v-if="req.test_link" :href="req.test_link" target="_blank" rel="noopener" class="block font-caption text-caption font-semibold text-primary hover:underline">Mở link đề</a>
                                <a v-if="req.speaking_link" :href="req.speaking_link" target="_blank" rel="noopener" class="block font-caption text-caption font-semibold text-primary hover:underline">Mở phần Speaking</a>
                                <p v-else class="font-caption text-caption text-on-surface-variant">Chưa có link phần Speaking</p>
                            </template>
                            <p v-else-if="req.rejection_reason" class="font-caption text-caption text-error">Lý do: {{ req.rejection_reason }}</p>
                        </td>
                    </tr>
                    <tr v-if="!requests.length">
                        <td colspan="6"><UiEmptyState icon="assignment" title="Chưa gửi yêu cầu đề test nào cho lớp này" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <div class="h-20 md:hidden" aria-hidden="true"></div>
    <TeacherBottomNav />
</template>
