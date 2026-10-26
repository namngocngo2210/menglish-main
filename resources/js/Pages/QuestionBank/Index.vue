<script setup>
/**
 * Ngân hàng câu hỏi luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §5.2): lọc, xem câu kèm đáp án / gạch chân / ảnh / ngữ liệu,
 * duyệt – ẩn các câu đã chọn hoặc tất cả câu đang lọc, sửa câu / ngữ liệu (modal), import file ZIP.
 */
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { confirmDialog } from '@/lib/confirm';
import { compactQuery, urlWith } from '@/lib/url';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';

defineOptions({ layout: { title: 'Ngân hàng câu hỏi' } });

const props = defineProps({
    questions: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    statusCounts: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    importErrors: { type: Array, default: () => [] },
});

const STATUS_COLORS = { draft: 'warning', approved: 'success', hidden: 'neutral' };
const BULK = {
    approve: { label: 'Duyệt', verb: 'Duyệt', icon: 'check_circle' },
    hide: { label: 'Ẩn', verb: 'Ẩn', icon: 'visibility_off' },
    draft: { label: 'Về chưa duyệt', verb: 'Chuyển về Chưa duyệt', icon: 'undo' },
};

const statusLabels = computed(() => Object.fromEntries(props.filterOptions.statuses.map((s) => [s.value, s.label])));

const selected = ref([]);
const pageIds = computed(() => props.questions.data.map((q) => q.id));
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)));
watch(pageIds, (ids) => (selected.value = selected.value.filter((id) => ids.includes(id))));

function toggleAll(event) {
    selected.value = event.target.checked ? [...pageIds.value] : [];
}

const busy = ref(false);
async function bulk(action, allMatching = false) {
    const count = allMatching ? props.questions.total : selected.value.length;
    if (!count) return;
    const scope = allMatching ? `tất cả ${count} câu đang lọc` : `${count} câu đã chọn`;
    const ok = await confirmDialog({
        title: `${BULK[action].verb} câu hỏi?`,
        message: `${BULK[action].verb} ${scope}?`,
        confirmLabel: BULK[action].label,
        danger: action === 'hide',
    });
    if (!ok) return;
    const data = allMatching ? { action, all_matching: true, ...compactQuery(props.filters) } : { action, ids: selected.value };
    router.post(route('question-bank.bulk'), data, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
        onSuccess: () => (selected.value = []),
    });
}

const importOpen = ref(false);
const fileName = ref('');
function onImported() {
    importOpen.value = false;
    fileName.value = '';
}
</script>

<template>
    <UiPageHeader title="Ngân hàng câu hỏi" description="Câu trắc nghiệm A–D khối 6–9 cho Luyện tập. Chỉ câu đã duyệt được bốc vào đề.">
        <template v-if="canManage" #actions>
            <UiButton icon="upload_file" @click="importOpen = true">Import file ZIP</UiButton>
        </template>
    </UiPageHeader>

    <UiTabs class="mb-lg">
        <UiTab :href="route('question-bank.index')" active>Câu hỏi</UiTab>
        <UiTab :href="route('question-bank.blueprints.index')">Khung đề</UiTab>
        <UiTab :href="route('question-bank.exams.index')">Đề cố định</UiTab>
        <UiTab :href="route('question-bank.analysis')">Phân tích câu hỏi</UiTab>
    </UiTabs>

    <UiAlert v-if="importErrors.length" type="warning" title="Một số câu / file chưa import được" class="mb-lg">
        <ul class="list-disc space-y-0.5 pl-md" data-import-errors>
            <li v-for="(line, i) in importErrors" :key="i">{{ line }}</li>
        </ul>
    </UiAlert>

    <UiFilterBar search="q" placeholder="Tìm nội dung câu hỏi, phương án, ngữ liệu, mã câu…">
        <template #quick>
            <nav class="flex flex-wrap items-center gap-xs" aria-label="Trạng thái">
                <Link
                    v-for="(count, status) in statusCounts"
                    :key="status"
                    :href="urlWith({ status: filters.status === status ? null : status, page: null })"
                    preserve-scroll
                    :class="[
                        'inline-flex items-center gap-xs rounded-full border px-sm py-1 font-body-small text-body-small font-semibold',
                        filters.status === status ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low',
                    ]"
                    :aria-current="filters.status === status ? 'page' : null"
                >
                    {{ statusLabels[status] }} <span class="font-code">{{ formatNumber(count) }}</span>
                </Link>
            </nav>
        </template>
        <UiSelect name="grade" label="Khối" :options="filterOptions.grades" placeholder="Tất cả khối" />
        <UiSelect name="term" label="Kỳ" :options="filterOptions.terms" placeholder="Tất cả kỳ" />
        <UiSelect name="skill" label="Kỹ năng" :options="filterOptions.skills" placeholder="Tất cả kỹ năng" />
        <UiSelect name="type" label="Dạng bài" :options="filterOptions.types" placeholder="Tất cả dạng bài" />
        <UiSelect name="status" label="Trạng thái" :options="filterOptions.statuses" placeholder="Tất cả trạng thái" />
    </UiFilterBar>

    <UiDataTable min-width="880px">
        <template v-if="canManage" #header>
            <div class="flex flex-wrap items-center gap-sm" data-bulk-actions>
                <span class="font-body-small text-body-small text-on-surface-variant">Đã chọn {{ selected.length }} câu</span>
                <UiButton v-for="(cfg, action) in BULK" :key="action" size="sm" variant="secondary" :icon="cfg.icon" :disabled="!selected.length || busy" @click="bulk(action)">{{ cfg.label }}</UiButton>
            </div>
            <div class="flex flex-wrap items-center gap-sm">
                <span class="font-body-small text-body-small text-on-surface-variant">Tất cả {{ formatNumber(questions.total) }} câu đang lọc:</span>
                <UiButton size="sm" variant="secondary" icon="done_all" :disabled="!questions.total || busy" @click="bulk('approve', true)">Duyệt tất cả</UiButton>
                <UiButton size="sm" variant="danger-text" icon="visibility_off" :disabled="!questions.total || busy" @click="bulk('hide', true)">Ẩn tất cả</UiButton>
            </div>
        </template>
        <table>
            <thead>
                <tr>
                    <th v-if="canManage" class="w-10">
                        <input type="checkbox" class="rounded" :checked="allSelected" aria-label="Chọn tất cả câu trong trang" @change="toggleAll" />
                    </th>
                    <th>Câu hỏi</th>
                    <th class="w-56">Phân loại</th>
                    <th class="w-32">Trạng thái</th>
                    <th v-if="canManage" class="w-20 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="q in questions.data" :key="q.id" class="align-top">
                    <td v-if="canManage">
                        <input v-model="selected" type="checkbox" :value="q.id" class="rounded" :aria-label="`Chọn câu ${q.source_key}`" />
                    </td>
                    <td class="space-y-sm">
                        <p v-if="q.instruction" class="font-caption text-caption italic text-on-surface-variant">{{ q.instruction }}</p>

                        <details v-if="q.passage" class="rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                            <summary class="cursor-pointer font-body-small text-body-small font-semibold text-on-surface">Ngữ liệu #{{ q.passage.id }}</summary>
                            <p v-if="q.passage.instruction" class="mt-xs font-caption text-caption italic text-on-surface-variant">{{ q.passage.instruction }}</p>
                            <UnderlinedText :text="q.passage.content" class="mt-xs block whitespace-pre-line font-body-small text-body-small text-on-surface" />
                            <img v-if="q.passage.image_url" :src="q.passage.image_url" alt="Ảnh ngữ liệu" loading="lazy" class="mt-xs max-h-48 rounded border border-outline-variant" />
                            <UiButton v-if="canManage" size="sm" variant="ghost" icon="edit" :href="route('question-bank.passages.edit', q.passage.id)" modal="2xl" class="mt-xs">Sửa ngữ liệu</UiButton>
                        </details>

                        <UnderlinedText :text="q.stem" class="block whitespace-pre-line font-body-medium text-body-medium text-on-surface" />
                        <img v-if="q.image_url" :src="q.image_url" :alt="`Ảnh câu ${q.source_key}`" loading="lazy" class="max-h-48 rounded border border-outline-variant" />

                        <ol class="grid grid-cols-1 gap-xs sm:grid-cols-2">
                            <li
                                v-for="(text, key) in q.options"
                                :key="key"
                                :class="['flex items-start gap-xs rounded-md px-xs py-0.5 font-body-small text-body-small', key === q.answer ? 'bg-tertiary/10 font-semibold text-tertiary' : 'text-on-surface']"
                            >
                                <span class="font-code">{{ key }}.</span>
                                <UnderlinedText :text="text" />
                                <span v-if="key === q.answer" class="material-symbols-outlined ml-auto text-[16px]" aria-label="Đáp án đúng">check</span>
                            </li>
                        </ol>

                        <p class="font-caption text-caption text-on-surface-variant">
                            <span class="font-code">{{ q.source_key }}</span>
                            <template v-if="q.knowledge"> · Kiến thức: {{ q.knowledge }}</template>
                            <template v-if="q.source"> · {{ q.source }}</template>
                        </p>
                    </td>
                    <td class="font-body-small text-body-small">
                        <div class="font-semibold text-on-surface">Khối {{ q.grade }} · {{ q.term_label }}</div>
                        <div class="text-on-surface-variant">{{ q.skill }}</div>
                        <div class="text-on-surface-variant">{{ q.type }}</div>
                    </td>
                    <td>
                        <div class="flex flex-col items-start gap-xs">
                            <UiBadge :color="STATUS_COLORS[q.status] ?? 'neutral'">{{ q.status_label }}</UiBadge>
                            <UiBadge v-if="q.edited" color="info" :dot="false">Đã sửa</UiBadge>
                        </div>
                    </td>
                    <td v-if="canManage" class="text-right">
                        <UiButton variant="ghost" icon="edit" :href="route('question-bank.questions.edit', q.id)" modal="2xl" title="Sửa câu hỏi" :aria-label="`Sửa câu ${q.source_key}`" />
                    </td>
                </tr>
                <tr v-if="!questions.data.length">
                    <td :colspan="canManage ? 5 : 3">
                        <UiEmptyState icon="quiz" title="Không có câu hỏi" :description="canManage ? 'Đổi bộ lọc hoặc import file ZIP ngân hàng câu hỏi.' : 'Đổi bộ lọc để xem câu hỏi khác.'" />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="questions" unit="câu hỏi" /></template>
    </UiDataTable>

    <UiModal v-if="canManage" :show="importOpen" title="Import ngân hàng câu hỏi" max-width="lg" @close="importOpen = false">
        <UiForm :action="route('question-bank.import')" method="post" class="space-y-md" reset-on-success @success="onImported">
            <template #default="{ errors, processing }">
                <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-lg text-center hover:border-primary-container">
                    <span class="material-symbols-outlined text-[32px] text-primary" aria-hidden="true">folder_zip</span>
                    <span class="font-body-medium text-body-medium text-on-surface">Chọn file .zip (tối đa 100MB)</span>
                    <input type="file" name="file" accept=".zip" class="sr-only" required @change="fileName = $event.target.files[0]?.name || ''" />
                    <span v-if="fileName" class="font-body-small text-body-small text-on-surface-variant">Đã chọn: {{ fileName }}</span>
                </label>
                <p v-if="errors.file" class="font-body-small text-body-small text-error" role="alert">{{ errors.file }}</p>
                <UiAlert type="info" title="Lưu ý">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li>File gồm <code>data/*.json</code> (câu đã tách) và ảnh trong <code>hinh/</code>.</li>
                        <li>Câu mới vào trạng thái <b>Chưa duyệt</b>. Câu đã duyệt, đã ẩn hoặc đã sửa tay giữ nguyên khi import lại.</li>
                    </ul>
                </UiAlert>
                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" :disabled="processing" @click="importOpen = false">Hủy</UiButton>
                    <UiButton type="submit" icon="upload_file" :disabled="processing">{{ processing ? 'Đang import…' : 'Import' }}</UiButton>
                </div>
            </template>
        </UiForm>
    </UiModal>
</template>
