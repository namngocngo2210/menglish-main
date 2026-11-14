<script setup>
/**
 * Feedback phụ huynh theo lớp / chặng (TK-2026-0058) cho Học vụ / Admin: chọn lớp → chặng → bảng học viên
 * (Nguồn / Cập nhật lúc / Trạng thái / Xử lý) và công tắc mở / đóng thu thập feedback của chặng (cổng phụ huynh chỉ nhận khi chặng đang mở).
 */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Feedback phụ huynh' } });

const props = defineProps({
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    stages: { type: Array, default: () => [] },
    stageId: { type: Number, default: null },
    rows: { type: Array, default: () => [] },
    canToggle: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const editingRow = ref(null);
const manualRating = ref(0);
const manualCategories = ref([]);
const manualContent = ref('');

const CATEGORY_OPTIONS = ['Học thuật', 'Giáo viên', 'Khác'];
const ratingSelectOptions = [
    { value: 0, label: '0 - Chưa chọn' },
    { value: 1, label: '1 sao - Rất không hài lòng' },
    { value: 2, label: '2 sao - Không hài lòng' },
    { value: 3, label: '3 sao - Bình thường' },
    { value: 4, label: '4 sao - Hài lòng' },
    { value: 5, label: '5 sao - Rất hài lòng' },
];

const classOptions = computed(() => props.classes.map((c) => ({ value: c.id, label: c.code ? `${c.name} (${c.code})` : c.name })));
const stageOptions = computed(() => props.stages.map((s) => ({ value: s.id, label: s.current ? `${s.name} (đang học)` : s.name })));
const stage = computed(() => props.stages.find((s) => s.id === props.stageId) ?? null);
const submittedCount = computed(() => props.rows.filter((r) => r.status === 'submitted').length);

function toggle() {
    if (!stage.value) return;
    router.put(route('class-quality.parent-feedback.toggle', { assignment: stage.value.id }), { open: !stage.value.open }, { preserveScroll: true });
}

function toggleHandled(r) {
    if (!r.record_id) return;
    router.put(route('class-quality.parent-feedback.handled', { record: r.record_id }), { handled: !r.handled }, { preserveScroll: true });
}

function openManualModal(row) {
    if (page.props.errors?.validation) delete page.props.errors.validation;
    editingRow.value = row;
    manualRating.value = row.rating !== null && row.rating !== undefined ? Number(row.rating) : 0;
    manualCategories.value = Array.isArray(row.categories) ? [...row.categories] : [];
    manualContent.value = row.content ?? '';
}

function closeManualModal() {
    if (page.props.errors?.validation) delete page.props.errors.validation;
    editingRow.value = null;
    manualRating.value = 0;
    manualCategories.value = [];
    manualContent.value = '';
}

function submitManual() {
    if (!editingRow.value || !props.stageId) return;
    router.post(
        route('class-quality.parent-feedback.manual'),
        {
            student_id: editingRow.value.id,
            stage_id: props.stageId,
            muc_do_hai_long: Number(manualRating.value),
            categories: manualCategories.value,
            noi_dung_feedback: manualContent.value,
        },
        {
            preserveScroll: true,
            onSuccess: closeManualModal,
        }
    );
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
                        <th class="px-4 py-3">Xử lý</th>
                        <th v-if="canManage" class="px-4 py-3 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-highest">
                    <tr v-for="r in rows" :key="r.id" :data-feedback-student="r.id">
                        <td class="px-4 py-3">
                            <span class="font-semibold text-on-surface">{{ r.name }}</span>
                            <div v-if="r.rating || r.content" class="mt-1 space-y-0.5">
                                <div v-if="r.rating" class="flex items-center gap-1 text-xs font-semibold text-warning" :aria-label="`Đánh giá: ${r.rating}/5 sao`">
                                    <span class="material-symbols-outlined text-[14px]" style="font-variation-settings: 'FILL' 1" aria-hidden="true">star</span>
                                    <span>{{ r.rating }}/5</span>
                                </div>
                                <p v-if="r.content" class="line-clamp-2 max-w-xs text-xs text-on-surface-variant" :title="r.content">
                                    {{ r.content }}
                                </p>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-on-surface-variant">{{ r.source ?? '—' }}</td>
                        <td class="px-4 py-3 text-on-surface-variant">{{ r.updated_at ? formatDate(r.updated_at, 'd/m/Y H:i') : '—' }}</td>
                        <td class="px-4 py-3"><UiBadge :color="r.status === 'submitted' ? 'success' : 'neutral'">{{ r.status === 'submitted' ? 'Đã gửi' : 'Chưa gửi' }}</UiBadge></td>
                        <td class="px-4 py-3">
                            <div v-if="r.record_id" class="flex flex-wrap items-center gap-1.5">
                                <UiBadge :color="r.handled ? 'success' : 'warning'">
                                    {{ r.handled ? 'Đã xử lý' : 'Chưa xử lý' }}
                                </UiBadge>
                                <UiButton
                                    v-if="canManage"
                                    size="sm"
                                    variant="ghost"
                                    :icon="r.handled ? 'undo' : 'check'"
                                    :aria-label="r.handled ? `Đánh dấu chưa xử lý cho ${r.name}` : `Đánh dấu đã xử lý cho ${r.name}`"
                                    :title="r.handled ? 'Đánh dấu chưa xử lý' : 'Đánh dấu đã xử lý'"
                                    @click="toggleHandled(r)"
                                >
                                    {{ r.handled ? 'Đánh dấu chưa xử lý' : 'Đánh dấu đã xử lý' }}
                                </UiButton>
                            </div>
                            <span v-else class="text-on-surface-variant">—</span>
                        </td>
                        <td v-if="canManage" class="px-4 py-3 text-right">
                            <UiButton
                                size="sm"
                                variant="secondary"
                                icon="rate_review"
                                :aria-label="`Nhập feedback cho ${r.name}`"
                                @click="openManualModal(r)"
                            >
                                Nhập feedback
                            </UiButton>
                        </td>
                    </tr>
                </tbody>
            </table>
            <UiEmptyState v-if="!rows.length" icon="group" title="Lớp chưa có học viên." />
        </div>
        <UiEmptyState v-else icon="reviews" title="Lớp chưa có chặng nào để thu thập feedback." />
    </div>

    <!-- Modal nhập feedback thủ công (CM) -->
    <UiModal :show="!!editingRow" title="Nhập feedback phụ huynh" max-width="md" @close="closeManualModal">
        <form v-if="editingRow" id="manual-feedback-form" class="space-y-4" @submit.prevent="submitManual">
            <div class="rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
                <p class="text-xs text-on-surface-variant">
                    Học viên: <span class="font-bold text-on-surface">{{ editingRow.name }}</span>
                </p>
                <p v-if="stage" class="mt-0.5 text-xs text-on-surface-variant">
                    Chặng: <span class="font-semibold text-on-surface">{{ stage.name }}</span>
                </p>
            </div>

            <UiAlert v-if="page.props.errors?.validation" type="warning">
                {{ page.props.errors.validation }}
            </UiAlert>

            <UiSelect
                v-model="manualRating"
                name="muc_do_hai_long"
                label="Mức độ hài lòng"
                :options="ratingSelectOptions"
            />

            <div>
                <label class="mb-1.5 block font-body-small text-body-small font-medium text-on-surface">Lĩnh vực góp ý</label>
                <div class="flex flex-wrap gap-4">
                    <UiCheckbox
                        v-for="cat in CATEGORY_OPTIONS"
                        :key="cat"
                        v-model="manualCategories"
                        :value="cat"
                        :label="cat"
                    />
                </div>
            </div>

            <UiTextarea
                v-model="manualContent"
                name="noi_dung_feedback"
                label="Nội dung feedback"
                placeholder="Nhập nội dung feedback của phụ huynh..."
                :rows="4"
            />
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="closeManualModal">Hủy</UiButton>
            <UiButton type="submit" form="manual-feedback-form" icon="save">Lưu feedback</UiButton>
        </template>
    </UiModal>
</template>
