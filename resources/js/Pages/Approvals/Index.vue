<script setup>
/**
 * Approval inbox: group filters, responsive source lists and a desktop preview.
 * Row details and single/bulk confirmations reuse the existing modal flows.
 */
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';
import { shortenCodesIn } from '@/lib/format';
import { approvalAgeTone, approvalIcon, splitApprovalItems } from '@/lib/approvalsView';

defineOptions({ layout: { title: 'Việc cần duyệt', hideErrors: true } });

const props = defineProps({
    groups: { type: Array, default: () => [] },
    group: { type: String, default: null },
    total: { type: Number, default: 0 },
    sections: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
});
const page = usePage();

const selected = ref([]);
const confirming = ref(null); // 'approve' | 'reject'
const activeRef = ref(null);
const previewTarget = ref(null);
const expanded = ref({});
const referenceTime = Date.now();
const ageTone = (item) => approvalAgeTone(item.created_at, referenceTime);
const targetRefs = computed(() => previewTarget.value ? [previewTarget.value] : selected.value);

const visibleSections = computed(() => props.sections.filter((s) => s.items.length).map((s) => ({ ...s, split: splitApprovalItems(s.items) })));
const active = computed(() => {
    for (const section of props.sections) {
        const item = section.items.find((item) => item.ref === activeRef.value);
        if (item) return { section, item };
    }
    return null;
});
// Map each item reference to its source's supported actions.
const support = computed(() => {
    const map = {};
    for (const s of props.sections) for (const item of s.items) map[item.ref] = { approve: s.approve, reject: s.reject };
    return map;
});
const can = (action) => selected.value.length > 0 && selected.value.every((r) => support.value[r]?.[action]);
const refsOf = (section) => section.items.map((item) => item.ref);
const allSelected = (section) => refsOf(section).every((r) => selected.value.includes(r));
function toggleAll(section, on) {
    const refs = refsOf(section);
    selected.value = on ? [...new Set([...selected.value, ...refs])] : selected.value.filter((r) => !refs.includes(r));
}
// Discard references that disappear after processing or changing groups.
watch(support, (map) => {
    selected.value = selected.value.filter((r) => r in map);
    if (activeRef.value && !(activeRef.value in map)) activeRef.value = null;
    if (previewTarget.value && !(previewTarget.value in map)) closeConfirmation();
});

const error = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const failed = computed(() => props.results.filter((r) => !r.ok));
const resultTitle = computed(() => `Đã xử lý ${props.results.length - failed.value.length}/${props.results.length} mục` + (failed.value.length ? ` — ${failed.value.length} mục không xử lý được` : ''));

const chip = (on) => ['inline-flex min-h-11 shrink-0 items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-small transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40', on ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface hover:border-primary-container hover:text-primary'];

function confirmAction(action, item = null) {
    previewTarget.value = item?.ref ?? null;
    confirming.value = action;
}
function closeConfirmation() {
    confirming.value = null;
    previewTarget.value = null;
}

function openDetail(event, item) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
    event.preventDefault();
    openRemoteModal(route('approvals.show', [item.source, item.id]), { size: 'xl' });
}
function done() {
    closeConfirmation();
    selected.value = [];
}
</script>

<template>
    <UiPageHeader title="Việc cần duyệt" :description="`${total} yêu cầu đang chờ · Học phí, Đào tạo, Công việc và Nhân sự`" />

    <!-- Bulk processing results -->
    <div id="approval-results" aria-live="polite">
        <UiAlert v-if="error" :key="error" type="error" class="mb-lg" dismissible>{{ error }}</UiAlert>
        <UiAlert v-else-if="results.length" :key="resultTitle" :type="failed.length ? 'warning' : 'success'" class="mb-lg" dismissible :title="resultTitle">
            <ul v-if="failed.length" class="mt-xs list-disc space-y-0.5 pl-md" data-approval-failures>
                <li v-for="row in failed" :key="row.ref"><span class="font-semibold">{{ row.title }}</span>: {{ row.message }}</li>
            </ul>
        </UiAlert>
    </div>

    <div id="approval-list" class="min-w-0">
        <!-- Group filters -->
        <nav class="no-scrollbar mb-lg flex items-center gap-sm overflow-x-auto" aria-label="Lọc theo nhóm">
            <Link :href="route('approvals.index')" :class="chip(group === null)" :aria-current="group === null ? 'page' : null" data-approval-group="all">
                Tất cả <span class="font-code">{{ total }}</span>
            </Link>
            <Link v-for="g in groups" :key="g.slug" :href="route('approvals.index', { group: g.slug })" :class="chip(group === g.slug)" :aria-current="group === g.slug ? 'page' : null" :data-approval-group="g.slug">
                {{ g.label }} <span class="font-code">{{ g.count }}</span>
            </Link>
        </nav>

        <div v-if="!visibleSections.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
            <UiEmptyState icon="task_alt" title="Không còn việc chờ duyệt" description="Bạn đã xử lý hết các yêu cầu đang chờ." />
        </div>

        <div class="grid min-w-0 items-start gap-lg lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="min-w-0 space-y-lg">
                <section v-for="section in visibleSections" :key="section.key" class="min-w-0 overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm" :data-approval-source="section.key">
                    <header class="flex flex-wrap items-center gap-sm border-b border-surface-container bg-surface-container-low px-md py-sm">
                        <label v-if="section.approve || section.reject" class="flex min-h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center">
                            <input type="checkbox" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container/30" :aria-label="`Chọn tất cả ${section.label}`" :checked="allSelected(section)" @change="toggleAll(section, $event.target.checked)" />
                        </label>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-xs">
                                <h2 class="break-words font-h3 text-h3 text-on-surface">{{ section.label }}</h2>
                                <UiBadge color="warning" :dot="false" pill>{{ section.count }}</UiBadge>
                            </div>
                            <p class="font-caption text-caption text-on-surface-variant">
                                {{ section.group }}
                                <span v-if="!(section.approve || section.reject)">· duyệt tại màn gốc</span>
                            </p>
                        </div>
                        <Link :href="section.indexUrl" class="ml-auto inline-flex min-h-11 shrink-0 items-center gap-xs rounded-lg font-body-medium text-body-small text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40">
                            Xem tất cả ({{ section.count }}) <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_forward</span>
                        </Link>
                    </header>

                    <ul :id="`approval-items-${section.key}`" class="divide-y divide-surface-container">
                        <li
                            v-for="(item, index) in section.items"
                            :key="item.ref"
                            class="min-h-14 min-w-0 items-center gap-xs px-xs py-xs hover:bg-surface-container-low md:gap-sm md:px-md md:py-sm"
                            :class="[index >= section.split.first.length && !expanded[section.key] ? 'hidden md:flex' : 'flex', activeRef === item.ref ? 'bg-surface-container-low ring-1 ring-inset ring-primary-container/30' : '']"
                            :data-approval-item="item.ref"
                            @mouseenter="activeRef = item.ref"
                            @focusin="activeRef = item.ref"
                            @click="activeRef = item.ref"
                        >
                            <label v-if="section.approve || section.reject" class="flex min-h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center">
                                <input
                                    v-model="selected"
                                    type="checkbox"
                                    :value="item.ref"
                                    :data-ref="item.ref"
                                    :data-approve="section.approve ? '1' : '0'"
                                    :data-reject="section.reject ? '1' : '0'"
                                    class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container/30"
                                    :aria-label="`Chọn ${item.title}`"
                                />
                            </label>
                            <a :href="route('approvals.show', [item.source, item.id])" class="flex min-h-11 min-w-0 flex-1 items-center gap-xs rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 md:gap-sm" @click="openDetail($event, item)">
                                <span class="material-symbols-outlined shrink-0 text-[20px] text-primary" aria-hidden="true">{{ approvalIcon(section.key) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex min-w-0 items-center gap-xs md:gap-sm">
                                        <span class="min-w-0 flex-1 truncate font-body-medium text-body-medium text-on-surface" :title="item.title">{{ shortenCodesIn(item.title) }}</span>
                                        <UiMoney v-if="item.amount !== null" :value="item.amount" class="shrink-0 text-right font-code !text-caption tabular-nums md:!text-code" />
                                    </span>
                                    <span class="mt-xs flex min-w-0 items-center gap-xs md:gap-sm">
                                        <UiBadge v-if="item.created_ago" :color="ageTone(item)" :dot="false" pill :title="formatDate(item.created_at, 'H:i d/m/Y')">
                                            <time :datetime="item.created_at">{{ item.created_ago }}</time>
                                        </UiBadge>
                                        <span v-if="item.subtitle" class="min-w-0 flex-1 truncate font-body-small text-body-small text-on-surface-variant" :title="item.subtitle">{{ item.subtitle }}</span>
                                    </span>
                                    <span v-if="item.flag" class="mt-xs block min-w-0">
                                        <UiBadge color="error" class="max-w-full !shrink !whitespace-normal break-words">{{ item.flag }}</UiBadge>
                                    </span>
                                </span>
                            </a>
                            <UiButton variant="ghost" size="sm" icon="visibility" class="hidden min-h-11 min-w-11 shrink-0 lg:inline-flex" :aria-label="`Xem trước ${item.title}`" :aria-pressed="activeRef === item.ref" @click="activeRef = item.ref" />
                        </li>
                    </ul>

                    <div v-if="section.split.rest.length" class="border-t border-surface-container px-md py-xs text-center md:hidden">
                        <UiButton variant="ghost" class="min-h-11" :aria-expanded="!!expanded[section.key]" :aria-controls="`approval-items-${section.key}`" @click="expanded[section.key] = !expanded[section.key]">
                            {{ expanded[section.key] ? 'Thu gọn' : `Xem thêm ${section.split.rest.length} mục` }}
                        </UiButton>
                    </div>
                    <div v-if="section.count > section.items.length" class="border-t border-surface-container px-md py-sm text-center">
                        <Link :href="section.indexUrl" class="inline-flex min-h-11 items-center rounded-lg font-body-medium text-body-small text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40">Xem tất cả {{ section.count }} mục ở màn gốc</Link>
                    </div>
                </section>
            </div>

            <aside v-if="visibleSections.length" data-approval-preview aria-label="Xem trước" class="sticky top-24 hidden max-h-[calc(100dvh-7rem)] min-w-0 overflow-y-auto rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm lg:block">
                <template v-if="active">
                    <header class="space-y-sm border-b border-surface-container bg-surface-container-low p-md">
                        <p class="font-caption text-caption text-on-surface-variant">{{ active.section.label }}</p>
                        <h2 class="break-words font-h3 text-h3 text-on-surface">{{ shortenCodesIn(active.item.title) }}</h2>
                        <UiBadge v-if="active.item.flag" color="error" class="max-w-full !shrink !whitespace-normal break-words">{{ active.item.flag }}</UiBadge>
                    </header>
                    <div class="space-y-md p-md">
                        <p v-if="active.item.subtitle" class="break-words font-body-small text-body-small text-on-surface-variant">{{ active.item.subtitle }}</p>
                        <UiMoney v-if="active.item.amount !== null" :value="active.item.amount" class="text-right font-code tabular-nums" />
                        <div class="flex flex-wrap items-center gap-sm">
                            <UiBadge v-if="active.item.created_ago" :color="ageTone(active.item)" :dot="false" pill :title="formatDate(active.item.created_at, 'H:i d/m/Y')">{{ active.item.created_ago }}</UiBadge>
                            <time v-if="active.item.created_at" :datetime="active.item.created_at" class="font-caption text-caption text-on-surface-variant">{{ formatDate(active.item.created_at, 'H:i d/m/Y') }}</time>
                        </div>
                        <UiButton :href="route('approvals.show', [active.item.source, active.item.id])" native variant="secondary" class="min-h-11 w-full" @click="openDetail($event, active.item)">Xem chi tiết</UiButton>
                        <div class="grid grid-cols-2 gap-sm">
                            <UiButton variant="danger-text" icon="block" class="min-h-11" :disabled="!active.section.reject" @click="confirmAction('reject', active.item)">Từ chối</UiButton>
                            <UiButton icon="done" class="min-h-11" :disabled="!active.section.approve" @click="confirmAction('approve', active.item)">Duyệt</UiButton>
                        </div>
                    </div>
                </template>
                <p v-else class="p-lg text-center font-body-small text-body-small text-on-surface-variant">Chọn một mục để xem trước</p>
            </aside>
        </div>
    </div>

    <!-- Actions for the current bulk selection -->
    <Transition enter-active-class="transition-opacity" enter-from-class="opacity-0" leave-active-class="transition-opacity" leave-to-class="opacity-0">
        <div v-show="selected.length" class="sticky bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-20 mt-lg flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm pb-[env(safe-area-inset-bottom)] shadow-level-3 md:bottom-md">
            <span class="font-body-medium text-body-medium text-on-surface">Đã chọn <strong>{{ selected.length }}</strong></span>
            <UiButton variant="ghost" size="sm" class="min-h-11" @click="selected = []">Bỏ chọn</UiButton>
            <div class="ml-auto flex flex-wrap gap-sm">
                <UiButton variant="danger-text" icon="block" class="min-h-11" :disabled="!can('reject')" :title="can('reject') ? '' : 'Có mục phải xử lý ở màn gốc'" @click="confirmAction('reject')">Từ chối</UiButton>
                <UiButton icon="done_all" class="min-h-11" aria-label="Duyệt hàng loạt" :disabled="!can('approve')" :title="can('approve') ? '' : 'Có mục phải duyệt ở màn gốc'" @click="confirmAction('approve')">{{ selected.length ? `Duyệt ${selected.length} mục` : 'Duyệt hàng loạt' }}</UiButton>
            </div>
        </div>
    </Transition>

    <!-- Shared single-item and bulk approval confirmation -->
    <UiModal :show="confirming === 'approve'" title="Duyệt các mục đã chọn?" max-width="md" @close="closeConfirmation">
        <p>Duyệt <strong>{{ targetRefs.length }}</strong> mục. Mỗi mục được xử lý riêng theo đúng quy tắc của màn gốc; mục không hợp lệ sẽ được báo lại, không ảnh hưởng mục khác.</p>
        <UiForm id="approval-bulk-approve-form" :action="route('approvals.bulk')" method="post" back @success="done">
            <input type="hidden" name="action" value="approve" />
            <input v-for="r in targetRefs" :key="r" type="hidden" name="items[]" :value="r" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="closeConfirmation">Hủy</UiButton>
            <UiButton type="submit" form="approval-bulk-approve-form" icon="done_all">Duyệt</UiButton>
        </template>
    </UiModal>

    <!-- Shared rejection confirmation with a required reason -->
    <UiModal :show="confirming === 'reject'" title="Từ chối các mục đã chọn?" max-width="md" @close="closeConfirmation">
        <UiForm id="approval-bulk-reject-form" :action="route('approvals.bulk')" method="post" class="space-y-md" back reset-on-success @success="done">
            <input type="hidden" name="action" value="reject" />
            <input v-for="r in targetRefs" :key="r" type="hidden" name="items[]" :value="r" />
            <p>Từ chối <strong>{{ targetRefs.length }}</strong> mục. Lý do được gửi kèm cho người đề nghị.</p>
            <UiTextarea id="approval-bulk-reason" name="reason" label="Lý do từ chối" required :rows="3" maxlength="1000" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="closeConfirmation">Hủy</UiButton>
            <UiButton variant="danger" type="submit" form="approval-bulk-reject-form" icon="block">Từ chối</UiButton>
        </template>
    </UiModal>
</template>
