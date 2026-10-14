<script setup>
/**
 * Báo cáo tháng Học thuật (mockup 02/19 "Báo cáo tháng — Học thuật"): trái là tường thuật công việc (4 mục); phải là bảng
 * báo cáo tuần trong tháng (Tuần / Khoảng ngày / PIC, bấm để xem) và các buổi họp giáo viên đã ghi. Mỗi tháng 1 báo cáo, lưu lại là cập nhật.
 */
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo tháng Học thuật' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    weeklyReports: { type: Array, default: () => [] },
    meetings: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const viewing = ref(null);
const placeholders = {
    test_syllabus_review: 'Nhập nội dung rà soát bài kiểm tra và chương trình học...',
    materials_transfers: 'Nhập nội dung chuẩn bị tài liệu và kế hoạch chuyển lớp...',
    overall: 'Nhập nhận xét tổng quan về hoạt động học thuật trong tháng...',
    teacher_notes: 'Nhập nhận xét cụ thể cho các giáo viên cần lưu ý...',
};
const pickMonth = (value) => value && value !== props.month && router.get(route('reports.periodic.academic-monthly'), { month: value });
</script>

<template>
    <UiPageHeader title="Báo cáo tháng — Học thuật" icon="summarize">
        <template #actions>
            <div class="flex items-center gap-sm">
                <span class="whitespace-nowrap font-body-small text-body-small text-on-surface-variant">Chọn tháng:</span>
                <UiSelect :model-value="month" :options="months" :searchable="false" aria-label="Chọn tháng" @update:model-value="pickMonth" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_400px]">
        <UiForm id="academic-monthly-form" :action="route('reports.periodic.academic-monthly.store')" method="post" class="h-fit space-y-md rounded-2xl border border-primary-container/30 bg-surface-container-lowest p-lg shadow-sm">
            <input type="hidden" name="month" :value="month" />
            <h2 class="border-b border-primary-container/30 pb-sm font-h2 text-h2 text-on-surface">Tường thuật công việc</h2>
            <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo tháng này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>
            <UiTextarea v-for="(label, key) in fields" :key="key" :name="`narrative[${key}]`" :label="label" :rows="4" :placeholder="placeholders[key]" :value="values[key]" />
            <div class="flex justify-end">
                <UiButton type="submit" icon="save">Lưu phần tường thuật</UiButton>
            </div>
        </UiForm>

        <div class="space-y-lg">
            <section class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-weekly-in-month>
                <h2 class="flex items-center gap-xs px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">table_rows</span>Báo cáo tuần trong tháng
                </h2>
                <UiDataTable>
                    <table>
                        <thead>
                            <tr>
                                <th>Tuần</th>
                                <th>Khoảng ngày</th>
                                <th>PIC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in weeklyReports" :key="w.week" :class="w.content ? 'cursor-pointer hover:bg-surface-container-low' : ''" @click="w.content && (viewing = w)">
                                <td class="font-semibold">{{ w.label }}</td>
                                <td class="font-code text-on-surface-variant">{{ w.range }}</td>
                                <td>
                                    <span v-if="w.pic">{{ w.pic }}</span>
                                    <Link v-else :href="w.href" class="font-body-small text-body-small italic text-on-surface-variant hover:text-primary" @click.stop>Chưa nộp</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </section>

            <section class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-meetings-in-month>
                <h2 class="flex items-center gap-xs px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">groups</span>Họp giáo viên
                    <UiButton variant="ghost" size="sm" class="ml-auto" :href="route('class-quality.teacher-meetings')">Mở danh sách</UiButton>
                </h2>
                <UiDataTable>
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Giáo viên</th>
                                <th>Tình trạng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in meetings" :key="m.id">
                                <td class="font-code">{{ formatDate(m.week_start, 'd/m/Y') }}</td>
                                <td>{{ m.teacher }}</td>
                                <td><UiBadge :color="m.status_color">{{ m.status_label }}</UiBadge></td>
                            </tr>
                            <tr v-if="!meetings.length">
                                <td colspan="3" class="font-body-small text-body-small italic text-on-surface-variant">Chưa ghi buổi họp giáo viên nào trong tháng này.</td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </section>

            <ReportHistory :items="history" :current="month" title="Các tháng đã nộp" />
        </div>
    </div>

    <UiModal :show="!!viewing" :title="viewing ? `${viewing.label} (${viewing.range}) · ${viewing.title}` : ''" max-width="2xl" @close="viewing = null">
        <p v-if="viewing" class="whitespace-pre-line text-on-surface">{{ viewing.content }}</p>
        <template #footer>
            <UiButton v-if="viewing" variant="secondary" :href="viewing.href">Mở báo cáo tuần</UiButton>
            <UiButton variant="secondary" @click="viewing = null">Đóng</UiButton>
        </template>
    </UiModal>
</template>
