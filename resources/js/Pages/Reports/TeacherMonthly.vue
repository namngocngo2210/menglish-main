<script setup>
/**
 * Báo cáo tháng của giáo viên (chủ dự án 04/10/2026): phần chung (tiến độ, khó khăn, đề xuất) + tình hình từng lớp đang dạy
 * (học sinh cần chú ý, giải pháp, có cần hỗ trợ không). Bên cạnh mỗi lớp là số liệu tháng (chuyên cần, bài về nhà, điểm TB)
 * để giáo viên tham chiếu; cuối form là order học thuật đã gửi trong tháng. Mỗi tháng 1 báo cáo, lưu lại là cập nhật.
 */
import { formatDate } from '@/lib/format';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo tháng theo lớp' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    generalFields: { type: Object, default: () => ({}) },
    classFields: { type: Object, default: () => ({}) },
    general: { type: Object, default: () => ({}) },
    classValues: { type: Object, default: () => ({}) },
    classes: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
});

const monthLabel = props.months.find((m) => m.value === props.month)?.label ?? props.month;
const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`);
const score = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 }));
const value = (classId, field) => props.classValues?.[classId]?.[field] ?? null;
</script>

<template>
    <UiPageHeader title="Báo cáo tháng theo lớp" icon="summarize">
        <template #meta>Tiến độ, khó khăn, đề xuất và tình hình từng lớp của {{ monthLabel.toLowerCase() }}</template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiFilterBar :search="false">
        <UiSelect name="month" label="Tháng báo cáo" :options="months" :value="month" />
    </UiFilterBar>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_280px]">
        <UiForm id="teacher-monthly-form" :action="route('reports.periodic.teacher-monthly.store')" method="post" class="space-y-lg">
            <input type="hidden" name="month" :value="month" />
            <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo tháng này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>

            <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">edit_note</span>Báo cáo chung
                </h2>
                <UiTextarea v-for="(label, key) in generalFields" :key="key" :name="`general[${key}]`" :label="label" :rows="3" :value="general[key]" />
            </section>

            <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">co_present</span>Tình hình từng lớp
                </h2>
                <div v-for="c in classes" :key="c.id" class="space-y-sm rounded-xl border border-surface-variant p-md" :data-teacher-class="c.id">
                    <div class="flex flex-wrap items-baseline justify-between gap-sm">
                        <h3 class="font-h3 text-h3 text-on-surface">{{ c.name }}</h3>
                        <p class="font-body-small text-body-small text-on-surface-variant">
                            {{ c.students }} HS · Chuyên cần {{ pct(c.attendance) }} · BTVN {{ pct(c.homework) }} · Điểm TB {{ score(c.score) }}
                        </p>
                    </div>
                    <div class="grid grid-cols-1 gap-sm md:grid-cols-3">
                        <UiTextarea v-for="(label, key) in classFields" :key="key" :name="`classes[${c.id}][${key}]`" :label="label" :rows="3" :value="value(c.id, key)" />
                    </div>
                    <input type="hidden" :name="`classes[${c.id}][need_support]`" value="0" />
                    <UiCheckbox :name="`classes[${c.id}][need_support]`" value="1" :checked="!!value(c.id, 'need_support')" label="Lớp cần hỗ trợ" />
                    <UiTextarea :name="`classes[${c.id}][support_note]`" label="Cần hỗ trợ gì (nếu có)" :rows="2" :value="value(c.id, 'support_note')" />
                </div>
                <p v-if="!classes.length" class="font-body-small text-body-small italic text-on-surface-variant">Không có lớp nào bạn dạy trong tháng này.</p>
            </section>

            <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="mb-md flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">inventory</span>Order học thuật trong tháng
                    <UiButton variant="ghost" size="sm" class="ml-auto" :href="route('material-orders.index')">Order học liệu</UiButton>
                </h2>
                <UiDataTable v-if="orders.length">
                    <table>
                        <thead>
                            <tr>
                                <th>Nội dung</th>
                                <th>Lớp</th>
                                <th>Ngày dùng</th>
                                <th>Tình trạng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in orders" :key="o.id">
                                <td>{{ o.title }}</td>
                                <td>{{ o.class ?? '—' }}</td>
                                <td class="font-code">{{ formatDate(o.use_date, 'd/m/Y') || '—' }}</td>
                                <td>{{ o.status_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Chưa có order học thuật nào trong tháng này.</p>
            </section>

            <div class="flex justify-end">
                <UiButton type="submit" icon="save">Lưu báo cáo tháng</UiButton>
            </div>
        </UiForm>

        <ReportHistory :items="history" :current="month" title="Các tháng đã nộp" />
    </div>
</template>
