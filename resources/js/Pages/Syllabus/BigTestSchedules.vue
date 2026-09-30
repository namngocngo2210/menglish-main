<script setup>
/**
 * Mockup 01_Web_Admin/08: thẻ tổng quan + bảng chặng (mã chặng, ngày thi dự kiến, trạng thái đề, số ngày còn lại);
 * đợt thi đã tạo: nhắc lịch học viên, xem điểm.
 */
defineOptions({ layout: { title: 'Nhắc lịch Big Test' } });

defineProps({
    upcoming: { type: Array, default: () => [] },
    urgent: { type: Number, default: 0 },
    bigTests: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});
</script>

<template>
    <UiPageHeader title="Nhắc lịch Big Test" description="Danh sách các chặng học sắp đến hạn thi Big Test (trong vòng 7 ngày) chưa được duyệt đề thi." :back="route('syllabus.documents')">
        <template #actions>
            <UiButton variant="secondary" icon="event_note" :href="route('syllabus.teaching-stages')">Lịch dự kiến theo lớp</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div class="grid max-w-3xl grid-cols-1 gap-md sm:grid-cols-2">
            <UiStatCard label="Tổng số chặng" :value="upcoming.length" icon="assignment" tone="primary" />
            <UiStatCard label="Khẩn cấp (1-2 ngày)" :value="urgent" icon="warning" tone="error" />
        </div>

        <UiDataTable min-width="720px">
            <template #header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-[20px] text-primary">notification_important</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Chặng sắp thi, chưa duyệt đề</h2>
                </div>
                <p class="font-caption text-caption text-on-surface-variant">Giáo viên lớp được tự động nhắc trước 7 ngày (đợt thi đã tạo); ngày thi lấy theo đợt Big Test của chặng, hoặc ngày dự kiến GV đặt / ngày thi trong order.</p>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Mã chặng</th>
                        <th>Lớp / Chặng</th>
                        <th>Ngày thi dự kiến</th>
                        <th>Trạng thái đề</th>
                        <th class="text-right">Số ngày còn lại</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in upcoming" :key="row.id">
                        <td>
                            <span class="inline-flex items-center gap-xs font-mono font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">local_library</span>{{ row.code }}</span>
                        </td>
                        <td>
                            <p class="font-body-medium text-body-small text-on-surface">{{ row.class_name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ row.stage_label }}</p>
                        </td>
                        <td class="font-mono">{{ row.date }}</td>
                        <td>
                            <UiBadge color="error">{{ row.exam === 'pending' ? 'Chưa duyệt đề' : 'Chưa order đề' }}</UiBadge>
                        </td>
                        <td class="text-right">
                            <span :class="['font-h3 text-h3', row.days_left <= 2 ? 'text-error' : row.days_left <= 3 ? 'text-warning' : 'text-on-surface']">{{ row.days_left }}</span>
                            <span class="font-caption text-caption text-on-surface-variant"> ngày</span>
                        </td>
                    </tr>
                    <tr v-if="!upcoming.length">
                        <td colspan="5">
                            <div class="flex flex-col items-center gap-sm py-xl text-center">
                                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-tertiary/10 text-tertiary"><span class="material-symbols-outlined text-[28px]">task_alt</span></span>
                                <h3 class="font-h3 text-h3 text-on-surface">Tất cả đều ổn!</h3>
                                <p class="font-body-small text-body-small text-on-surface-variant">Không có chặng học nào sắp tới hạn chưa duyệt đề.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <!-- Đợt thi đã tạo: nhắc lịch học viên, xem điểm -->
        <UiDataTable min-width="980px">
            <template #header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-[20px] text-primary">event_note</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Đợt thi Big Test đã tạo</h2>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Mã đợt thi</th>
                        <th>Lớp thi</th>
                        <th>Tên bài thi</th>
                        <th>Ngày &amp; Giờ thi</th>
                        <th>Phòng thi &amp; Cơ sở</th>
                        <th>Giám thị coi thi</th>
                        <th>Mật mã thi</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bt in bigTests.data" :key="bt.id">
                        <td class="font-mono font-semibold">{{ bt.code }}</td>
                        <td class="font-semibold text-on-surface">{{ bt.class_name }}</td>
                        <td class="text-primary">{{ bt.title }}</td>
                        <td class="font-mono text-on-surface-variant">{{ bt.scheduled_at ?? '—' }}</td>
                        <td>{{ bt.place }}</td>
                        <td>{{ bt.proctor ?? '—' }}</td>
                        <td class="font-mono font-bold text-tertiary">{{ bt.passcode }}</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <UiForm v-if="canManage" :action="route('syllabus.big-tests.remind', bt.id)" method="post" class="inline" :confirm="`Gửi nhắc lịch ${bt.title} tới toàn bộ học viên của lớp ${bt.class_name ?? ''}?`">
                                    <UiButton type="submit" size="sm" variant="secondary" icon="notifications_active" title="Gửi thông báo nhắc lịch vào Cổng PH/HS">Nhắc lịch</UiButton>
                                </UiForm>
                                <UiButton variant="ghost" size="sm" :href="route('syllabus.big-tests.results', bt.id)">Xem điểm</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!bigTests.data.length">
                        <td colspan="8"><UiEmptyState icon="event_busy" title="Chưa có lịch thi Big Test nào" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="bigTests" unit="đợt thi" /></template>
        </UiDataTable>
    </div>
</template>
