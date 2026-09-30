<script setup>
/**
 * "Việc cần duyệt" (IX-5): gom mục chờ duyệt từ các module (ApprovalInboxService). Chip lọc theo nhóm, mỗi nguồn tối đa
 * ApprovalController::PER_SOURCE mục (còn lại: "Xem tất cả" sang màn gốc). Bấm dòng → modal chi tiết (Show.vue).
 * Chọn nhiều → Duyệt hàng loạt / Từ chối (lý do). Xử lý xong server quay lại trang này kèm kết quả từng mục.
 */
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';
import { shortenCodesIn } from '@/lib/format';

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

const visibleSections = computed(() => props.sections.filter((s) => s.items.length));
// ref → { approve, reject } của nguồn chứa mục đó.
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
// Danh sách đổi (vừa duyệt / từ chối) → bỏ chọn mục không còn chờ.
watch(support, (map) => {
    selected.value = selected.value.filter((r) => r in map);
});

const error = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const failed = computed(() => props.results.filter((r) => !r.ok));
const resultTitle = computed(() => `Đã xử lý ${props.results.length - failed.value.length}/${props.results.length} mục` + (failed.value.length ? ` — ${failed.value.length} mục không xử lý được` : ''));

const chip = (on) => ['inline-flex shrink-0 items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-small transition-colors', on ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface hover:border-primary-container hover:text-primary'];

function openDetail(event, item) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
    event.preventDefault();
    openRemoteModal(route('approvals.show', [item.source, item.id]), { size: 'xl' });
}
function done() {
    confirming.value = null;
    selected.value = [];
}
</script>

<template>
    <UiPageHeader title="Việc cần duyệt" description="Mọi yêu cầu đang chờ bạn duyệt, gom từ Học phí, Đào tạo và Công việc." />

    <!-- Kết quả xử lý hàng loạt -->
    <div id="approval-results" aria-live="polite">
        <UiAlert v-if="error" :key="error" type="error" class="mb-lg" dismissible>{{ error }}</UiAlert>
        <UiAlert v-else-if="results.length" :key="resultTitle" :type="failed.length ? 'warning' : 'success'" class="mb-lg" dismissible :title="resultTitle">
            <ul v-if="failed.length" class="mt-xs list-disc space-y-0.5 pl-md" data-approval-failures>
                <li v-for="row in failed" :key="row.ref"><span class="font-semibold">{{ row.title }}</span>: {{ row.message }}</li>
            </ul>
        </UiAlert>
    </div>

    <div id="approval-list">
        <!-- Chip lọc theo nhóm -->
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

        <div class="space-y-lg">
            <section v-for="section in visibleSections" :key="section.key" class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm" :data-approval-source="section.key">
                <header class="flex flex-wrap items-center gap-sm border-b border-surface-container px-md py-sm">
                    <input v-if="section.approve || section.reject" type="checkbox" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container/30" :aria-label="`Chọn tất cả ${section.label}`" :checked="allSelected(section)" @change="toggleAll(section, $event.target.checked)" />
                    <h2 class="font-h3 text-h3 text-on-surface">{{ section.label }}</h2>
                    <UiBadge color="warning" :dot="false" pill>{{ section.count }}</UiBadge>
                    <span class="font-caption text-caption text-on-surface-variant">{{ section.group }}</span>
                    <span v-if="!(section.approve || section.reject)" class="font-caption text-caption text-on-surface-variant">· duyệt tại màn gốc</span>
                    <Link :href="section.indexUrl" class="ml-auto inline-flex items-center gap-xs font-body-medium text-body-small text-primary hover:underline">
                        Xem tất cả <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_forward</span>
                    </Link>
                </header>

                <ul class="divide-y divide-surface-container">
                    <li v-for="item in section.items" :key="item.ref" class="flex items-center gap-sm px-md py-sm hover:bg-surface-container-low" :data-approval-item="item.ref">
                        <input
                            v-if="section.approve || section.reject"
                            v-model="selected"
                            type="checkbox"
                            :value="item.ref"
                            :data-ref="item.ref"
                            :data-approve="section.approve ? '1' : '0'"
                            :data-reject="section.reject ? '1' : '0'"
                            class="h-4 w-4 shrink-0 rounded border-outline-variant text-primary-container focus:ring-primary-container/30"
                            :aria-label="`Chọn ${item.title}`"
                        />
                        <a :href="route('approvals.show', [item.source, item.id])" class="flex min-w-0 flex-1 items-center gap-md rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40" @click="openDetail($event, item)">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-body-medium text-body-medium text-on-surface" :title="item.title">{{ shortenCodesIn(item.title) }}</span>
                                <span v-if="item.subtitle" class="block truncate font-body-small text-body-small text-on-surface-variant">{{ item.subtitle }}</span>
                            </span>
                            <UiBadge v-if="item.flag" color="error" class="hidden sm:inline-flex">{{ item.flag }}</UiBadge>
                            <span v-if="item.amount !== null" class="hidden shrink-0 sm:block"><UiMoney :value="item.amount" /></span>
                            <time v-if="item.created_at" :datetime="item.created_at" :title="formatDate(item.created_at, 'H:i d/m/Y')" class="hidden w-24 shrink-0 text-right font-caption text-caption text-on-surface-variant md:block">{{ item.created_ago }}</time>
                            <span class="material-symbols-outlined shrink-0 text-[20px] text-on-surface-variant" aria-hidden="true">chevron_right</span>
                        </a>
                    </li>
                </ul>

                <div v-if="section.count > section.items.length" class="border-t border-surface-container px-md py-sm text-center">
                    <Link :href="section.indexUrl" class="font-body-medium text-body-small text-primary hover:underline">Xem tất cả {{ section.count }} mục ở màn gốc</Link>
                </div>
            </section>
        </div>
    </div>

    <!-- Thanh hành động khi đã chọn -->
    <Transition enter-active-class="transition-opacity" enter-from-class="opacity-0" leave-active-class="transition-opacity" leave-to-class="opacity-0">
        <div v-show="selected.length" class="sticky bottom-md z-20 mt-lg flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm shadow-level-3">
            <span class="font-body-medium text-body-medium text-on-surface">Đã chọn <strong>{{ selected.length }}</strong></span>
            <UiButton variant="ghost" size="sm" @click="selected = []">Bỏ chọn</UiButton>
            <div class="ml-auto flex flex-wrap gap-sm">
                <UiButton variant="danger-text" icon="block" :disabled="!can('reject')" :title="can('reject') ? '' : 'Có mục phải xử lý ở màn gốc'" @click="confirming = 'reject'">Từ chối</UiButton>
                <UiButton icon="done_all" :disabled="!can('approve')" :title="can('approve') ? '' : 'Có mục phải duyệt ở màn gốc'" @click="confirming = 'approve'">Duyệt hàng loạt</UiButton>
            </div>
        </div>
    </Transition>

    <!-- Xác nhận duyệt hàng loạt -->
    <UiModal :show="confirming === 'approve'" title="Duyệt các mục đã chọn?" max-width="md" @close="confirming = null">
        <p>Duyệt <strong>{{ selected.length }}</strong> mục. Mỗi mục được xử lý riêng theo đúng quy tắc của màn gốc; mục không hợp lệ sẽ được báo lại, không ảnh hưởng mục khác.</p>
        <UiForm id="approval-bulk-approve-form" :action="route('approvals.bulk')" method="post" back @success="done">
            <input type="hidden" name="action" value="approve" />
            <input v-for="r in selected" :key="r" type="hidden" name="items[]" :value="r" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="confirming = null">Hủy</UiButton>
            <UiButton type="submit" form="approval-bulk-approve-form" icon="done_all">Duyệt</UiButton>
        </template>
    </UiModal>

    <!-- Từ chối hàng loạt (bắt buộc lý do) -->
    <UiModal :show="confirming === 'reject'" title="Từ chối các mục đã chọn?" max-width="md" @close="confirming = null">
        <UiForm id="approval-bulk-reject-form" :action="route('approvals.bulk')" method="post" class="space-y-md" back reset-on-success @success="done">
            <input type="hidden" name="action" value="reject" />
            <input v-for="r in selected" :key="r" type="hidden" name="items[]" :value="r" />
            <p>Từ chối <strong>{{ selected.length }}</strong> mục. Lý do được gửi kèm cho người đề nghị.</p>
            <UiTextarea id="approval-bulk-reason" name="reason" label="Lý do từ chối" required :rows="3" maxlength="1000" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="confirming = null">Hủy</UiButton>
            <UiButton variant="danger" type="submit" form="approval-bulk-reject-form" icon="block">Từ chối</UiButton>
        </template>
    </UiModal>
</template>
