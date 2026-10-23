<script setup>
/**
 * Cổng GV — ORDER theo mốc syllabus (docs/kho-syllabus.md §9.3, Sheet 4 câu 5): mốc Order in ấn / mini test / Big Test của chặng
 * lớp đang học; đến hạn khi lớp tới buổi có mốc. In ấn gửi vận hành cơ sở, đề gửi Học thuật (xử lý trước buổi test ≥ 3 buổi).
 */
import { ref } from 'vue';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import GetForm from '@/Components/Syllabus/GetForm.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'ORDER theo syllabus' } });

defineProps({
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    stage: { type: String, default: null },
    milestones: { type: Array, default: () => [] },
    storeUrl: { type: String, default: null },
});

const ordering = ref(null);
</script>

<template>
    <UiPageHeader title="ORDER theo syllabus" icon="local_shipping">
        <template v-if="stage" #meta>{{ stage }}</template>
        <template #actions>
            <GetForm v-if="classes.length > 1" :action="route('teacher.orders')">
                <UiSelect name="class" inline-label="Lớp" :value="classId" :options="classes" />
            </GetForm>
        </template>
    </UiPageHeader>

    <div class="space-y-md pb-24 md:pb-0">
        <UiEmptyState v-if="!classes.length" icon="local_shipping" title="Bạn không có lớp nào đang học chặng" />
        <UiDataTable v-else min-width="720px">
            <template #header>
                <h2 class="font-h3 text-h3 text-on-surface">Mốc order của chặng</h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Buổi</th>
                        <th>Mốc</th>
                        <th>Gửi tới</th>
                        <th>Ngày dùng / ngày thi</th>
                        <th>Trạng thái</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in milestones" :key="m.lesson_id">
                        <td class="font-semibold">{{ m.lesson }}</td>
                        <td><UiBadge color="info">{{ m.label }}</UiBadge></td>
                        <td>{{ m.target }}</td>
                        <td class="whitespace-nowrap text-xs">
                            <p v-if="m.kind === 'order_print'">Dùng: {{ m.use_date ? formatDate(m.use_date) : '—' }}</p>
                            <template v-else>
                                <p>Thi: {{ m.exam_date ? formatDate(m.exam_date) : '—' }}</p>
                                <p class="text-on-surface-variant">Học thuật xử lý trước {{ m.due_date ? formatDate(m.due_date) : '—' }}</p>
                            </template>
                        </td>
                        <td class="whitespace-nowrap">
                            <UiBadge v-if="m.ordered" color="success">Đã order {{ m.ordered }}</UiBadge>
                            <UiBadge v-else-if="m.due" color="error">Đến hạn</UiBadge>
                            <span v-else class="text-xs text-on-surface-subtle">Chưa đến hạn</span>
                        </td>
                        <td class="text-right">
                            <UiButton v-if="m.due && !m.ordered" size="sm" icon="send" @click="ordering = m">Order</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!milestones.length">
                        <td colspan="6"><UiEmptyState icon="event_note" title="Syllabus chặng chưa đánh dấu mốc order" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <UiModal :show="!!ordering" :title="ordering ? `${ordering.label} — ${ordering.lesson}` : ''" max-width="lg" @close="ordering = null">
        <UiForm v-if="ordering" id="order-form" :key="ordering.lesson_id" :action="storeUrl" method="post" class="space-y-md" @success="ordering = null">
            <input type="hidden" name="lesson_id" :value="ordering.lesson_id" />
            <UiAlert type="info" class="text-xs">Gửi tới: <strong>{{ ordering.target }}</strong>.</UiAlert>
            <UiInput v-if="ordering.kind === 'order_print'" type="number" name="quantity" label="Số lượng" min="1" />
            <UiTextarea name="note" label="Ghi chú" :rows="3" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="ordering = null">Hủy</UiButton>
            <UiButton type="submit" form="order-form" icon="send">Gửi order</UiButton>
        </template>
    </UiModal>

    <TeacherBottomNav />
</template>
