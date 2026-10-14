<script setup>
/**
 * Nhật ký Học vụ (mockup 02/05): ô "Thêm nhật ký mới" (gắn mã KPI Học vụ + nội dung sự việc) và dòng thời gian các sự việc.
 * Mỗi sự việc: thời điểm ghi, người ghi, mã KPI; Admin bật "Lỗi nghiêm trọng" (thẻ đỏ, "Xác nhận bởi"), người khác thấy
 * nhãn "Chưa xác nhận" / "Lỗi nghiêm trọng". Phần "Theo dõi xử lý" (trạng thái, follow-up) mở ở cuối từng thẻ.
 * Người xem tổng (staff_report.view_all) thấy sự việc của mọi người; người khác chỉ thấy của mình.
 */
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhật ký Học vụ' } });

defineProps({
    journals: { type: Object, required: true },
    isPriv: { type: Boolean, default: false },
    classes: { type: Array, default: () => [] },
    kpiOptions: { type: Array, default: () => [] },
    canConfirmSerious: { type: Boolean, default: false },
    today: { type: String, default: null },
});

const open = reactive({});
const statusLabel = { open: 'Mới', resolved: 'Đã xử lý', following: 'Đang theo dõi' };
const statusOptions = Object.entries(statusLabel).map(([value, label]) => ({ value, label }));
const submitOnChange = (event) => event.target.form?.requestSubmit();
const toggleSerious = (j) => router.post(route('reports.journal.serious', j.id), { serious: j.serious ? 0 : 1 }, { preserveScroll: true });
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-xl">
        <UiPageHeader title="Nhật ký Học vụ" description="Ghi nhận và theo dõi các sự việc liên quan đến học vụ." />

        <!-- Thêm nhật ký mới -->
        <section class="rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm">
            <h2 class="mb-md font-h3 text-h3 text-on-surface">Thêm nhật ký mới</h2>
            <UiForm id="new-journal-form" :action="route('reports.journal.store')" method="post" class="space-y-md" reset-on-success>
                <div class="flex flex-col gap-md md:flex-row">
                    <div class="space-y-md md:w-1/3">
                        <UiSelect id="journal_kpi_code" name="kpi_code" label="GẮN MÃ KPI (TÙY CHỌN)" placeholder="-- Không chọn --" :value="''" :options="kpiOptions" />
                        <!-- Gắn lớp để sự việc hiện ở tab "Sự vụ" của Trang lớp -->
                        <UiSelect v-if="classes.length" id="journal_class_id" name="class_id" label="LỚP LIÊN QUAN (TÙY CHỌN)" placeholder="-- Không gắn lớp --" :value="''" :options="classes" :searchable="classes.length > 8" />
                    </div>
                    <div class="md:w-2/3">
                        <UiTextarea name="content" label="NỘI DUNG SỰ VIỆC" required :rows="classes.length ? 6 : 3" placeholder="Nhập chi tiết sự việc..." class="resize-y" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <UiButton type="submit" icon="add_circle">Thêm nhật ký</UiButton>
                </div>
            </UiForm>
        </section>

        <!-- Dòng thời gian: đường dọc ở giữa (máy tính) / bên trái (điện thoại), thẻ xen kẽ hai bên -->
        <ol
            v-if="journals.data.length"
            class="relative space-y-lg before:absolute before:inset-0 before:ml-5 before:h-full before:w-0.5 before:-translate-x-px before:bg-gradient-to-b before:from-transparent before:via-surface-variant before:to-transparent md:before:mx-auto md:before:translate-x-0"
            data-journal-timeline
        >
            <li v-for="j in journals.data" :key="j.id" class="group relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse" :data-journal-entry="j.id">
                <div
                    :class="[
                        'z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full border shadow md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2',
                        j.serious ? 'border-error-container bg-error-container' : 'border-surface-container-highest bg-surface-container-lowest',
                    ]"
                    aria-hidden="true"
                >
                    <span :class="['material-symbols-outlined text-[20px]', j.serious ? 'text-error' : 'text-on-surface-variant']">{{ j.serious ? 'warning' : 'edit_note' }}</span>
                </div>

                <article :class="['w-[calc(100%-4rem)] rounded-xl border p-md shadow-sm transition-shadow hover:shadow-md md:w-[calc(50%-2.5rem)]', j.serious ? 'border-error-container bg-error-container/25' : 'border-surface-variant bg-surface-container-lowest']">
                    <div class="mb-sm flex items-start justify-between gap-sm">
                        <div class="min-w-0">
                            <div class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>
                                {{ formatDate(j.created_at ?? j.report_date, j.created_at ? 'd/m/Y H:i' : 'd/m/Y') }}
                            </div>
                            <div v-if="j.user" class="mt-xs font-label text-label uppercase text-primary">Người ghi: {{ j.user }}<template v-if="j.user_role"> ({{ j.user_role }})</template></div>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-xs">
                            <!-- Admin: bật / tắt lỗi nghiêm trọng -->
                            <label v-if="canConfirmSerious" class="flex cursor-pointer items-center gap-xs" title="Chỉ Admin mới có quyền đánh dấu lỗi nghiêm trọng">
                                <span :class="['font-label text-label', j.serious ? 'text-error' : 'text-on-surface-variant']">Lỗi nghiêm trọng</span>
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="j.serious"
                                    :aria-label="`Lỗi nghiêm trọng: sự việc ${formatDate(j.report_date)}`"
                                    :class="['relative h-4 w-8 rounded-full transition-colors', j.serious ? 'bg-error' : 'bg-surface-dim']"
                                    @click="toggleSerious(j)"
                                >
                                    <span :class="['absolute top-0 h-4 w-4 rounded-full border-2 bg-white transition-all', j.serious ? 'left-4 border-error' : 'left-0 border-outline']" />
                                </button>
                            </label>
                            <template v-else>
                                <span v-if="j.serious" class="flex items-center gap-xs rounded bg-error px-xs py-[2px] font-label text-label text-on-error">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span> Lỗi nghiêm trọng
                                </span>
                                <span v-else class="rounded bg-surface-variant px-xs py-[2px] font-label text-label text-on-surface-variant">Chưa xác nhận</span>
                            </template>
                            <span v-if="j.serious && j.serious_by" class="flex items-center gap-xs rounded border border-error/20 bg-error-container px-xs py-[2px] font-label text-label text-on-error-container">
                                <span class="material-symbols-outlined text-[12px]" aria-hidden="true">verified_user</span>
                                Xác nhận bởi: {{ j.serious_by }}
                            </span>
                        </div>
                    </div>

                    <div v-if="j.kpi_code || j.class" class="mb-xs flex flex-wrap gap-xs">
                        <span v-if="j.kpi_code" class="rounded-md border border-outline-variant bg-surface-variant px-2 py-[2px] font-label text-[10px] text-on-surface-variant">KPI: {{ j.kpi_code }}</span>
                        <Link v-if="j.class" :href="route('classes.show', { id: j.class.id, tab: 'incidents' })" class="rounded-md border border-outline-variant px-2 py-[2px] font-label text-[10px] text-primary hover:underline">Lớp {{ j.class.code }}</Link>
                    </div>
                    <p :class="['whitespace-pre-line font-body-base text-body-base text-on-surface', j.serious ? 'font-medium' : '']">{{ j.content || j.title }}</p>

                    <!-- Theo dõi xử lý: trạng thái + follow-up -->
                    <div class="mt-sm border-t border-surface-variant pt-sm">
                        <button type="button" class="flex w-full items-center justify-between gap-sm text-left font-body-small text-body-small text-on-surface-variant hover:text-on-surface" :aria-expanded="!!open[j.id]" @click="open[j.id] = !open[j.id]">
                            <span>Theo dõi xử lý · <span class="font-semibold">{{ statusLabel[j.status] ?? 'Mới' }}</span><template v-if="j.followups.length"> · {{ j.followups.length }} follow-up</template></span>
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ open[j.id] ? 'expand_less' : 'expand_more' }}</span>
                        </button>
                        <div v-if="open[j.id]" class="mt-sm space-y-sm">
                            <UiForm :action="route('reports.journal.status', j.id)" method="post">
                                <UiSelect :id="`journal-status-${j.id}`" name="status" inline-label="Trạng thái" :value="j.status" :options="statusOptions" @change="submitOnChange" />
                            </UiForm>
                            <div v-if="j.followups.length" class="space-y-1.5 border-l-2 border-surface-container-highest pl-3">
                                <div v-for="f in j.followups" :key="f.id" class="text-xs text-on-surface-variant">
                                    <span class="font-semibold text-on-surface">{{ f.user ?? 'N/A' }}:</span> {{ f.content }}
                                    <span class="text-on-surface-subtle">· {{ formatDate(f.created_at, 'd/m H:i') }}</span>
                                </div>
                            </div>
                            <UiForm :action="route('reports.journal.followup', j.id)" method="post" class="flex items-center gap-2" reset-on-success>
                                <UiInput name="content" required placeholder="Nhập nội dung follow-up..." aria-label="Nội dung follow-up" class="text-xs" />
                                <UiButton type="submit" variant="secondary" size="sm" icon="add_task">Thêm</UiButton>
                            </UiForm>
                        </div>
                    </div>
                </article>
            </li>
        </ol>
        <div v-else class="rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm">
            <UiEmptyState icon="event_note" title="Chưa có sự việc nào được ghi nhận." />
        </div>

        <UiPagination v-if="journals.total > journals.per_page" :paginator="journals" :options="[]" />
    </div>
</template>
