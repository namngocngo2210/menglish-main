<script setup>
/**
 * Feedback phụ huynh theo lớp / chặng (TK-2026-0058) cho Học vụ / Admin: chọn lớp → chặng → bảng học viên
 * (Nguồn / Cập nhật lúc / Trạng thái) và công tắc mở / đóng thu thập feedback của chặng (cổng phụ huynh chỉ nhận khi chặng đang mở).
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Feedback phụ huynh' } });

const props = defineProps({
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    stages: { type: Array, default: () => [] },
    stageId: { type: Number, default: null },
    rows: { type: Array, default: () => [] },
    canToggle: { type: Boolean, default: false },
});

const classOptions = computed(() => props.classes.map((c) => ({ value: c.id, label: c.code ? `${c.name} (${c.code})` : c.name })));
const stageOptions = computed(() => props.stages.map((s) => ({ value: s.id, label: s.current ? `${s.name} (đang học)` : s.name })));
const stage = computed(() => props.stages.find((s) => s.id === props.stageId) ?? null);
const submittedCount = computed(() => props.rows.filter((r) => r.status === 'submitted').length);

function toggle() {
    if (!stage.value) return;
    router.put(route('class-quality.parent-feedback.toggle', { assignment: stage.value.id }), { open: !stage.value.open }, { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Feedback phụ huynh" icon="rate_review" description="Chọn lớp và chặng để xem phụ huynh nào đã gửi feedback, và mở / đóng đợt thu thập feedback của chặng." />

    <div class="space-y-md">
        <UiFilterBar :search="false">
            <UiSelect name="class_id" label="Lớp" :options="classOptions" :value="classId" />
            <UiSelect name="stage_id" label="Chặng" :options="stageOptions" :value="stageId" />
        </UiFilterBar>

        <div v-if="stage" class="flex flex-wrap items-center gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm" data-stage-collection>
            <div class="flex-1">
                <p class="text-sm font-semibold text-on-surface">{{ stage.name }}</p>
                <p class="text-xs text-on-surface-variant">Đã gửi {{ submittedCount }} / {{ rows.length }} học viên</p>
            </div>
            <UiBadge :color="stage.open ? 'success' : 'neutral'" pill>{{ stage.open ? 'Đang mở thu thập' : 'Đã đóng thu thập' }}</UiBadge>
            <UiButton v-if="canToggle" :variant="stage.open ? 'secondary' : 'primary'" :icon="stage.open ? 'lock' : 'lock_open'" data-toggle-collection @click="toggle">
                {{ stage.open ? 'Đóng thu thập feedback' : 'Mở thu thập feedback' }}
            </UiButton>
        </div>

        <div v-if="stage" class="overflow-x-auto rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-surface-container-highest text-xs uppercase text-on-surface-variant">
                    <tr>
                        <th class="px-4 py-3">Học viên</th>
                        <th class="px-4 py-3">Nguồn</th>
                        <th class="px-4 py-3">Cập nhật lúc</th>
                        <th class="px-4 py-3">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-highest">
                    <tr v-for="r in rows" :key="r.id" :data-feedback-student="r.id">
                        <td class="px-4 py-3 font-semibold text-on-surface">{{ r.name }}</td>
                        <td class="px-4 py-3 text-on-surface-variant">{{ r.source ?? '—' }}</td>
                        <td class="px-4 py-3 text-on-surface-variant">{{ r.updated_at ? formatDate(r.updated_at, 'd/m/Y H:i') : '—' }}</td>
                        <td class="px-4 py-3"><UiBadge :color="r.status === 'submitted' ? 'success' : 'neutral'">{{ r.status === 'submitted' ? 'Đã gửi' : 'Chưa gửi' }}</UiBadge></td>
                    </tr>
                </tbody>
            </table>
            <UiEmptyState v-if="!rows.length" icon="group" title="Lớp chưa có học viên." />
        </div>
        <UiEmptyState v-else icon="reviews" title="Lớp chưa có chặng nào để thu thập feedback." />
    </div>
</template>
