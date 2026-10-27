<script setup>
/**
 * Trang lớp · Học thuật (gộp tab Học thuật + Syllabus cũ, docs/kho-syllabus.md §7.3):
 *  - Dải chặng: chặng đang học, tiến độ, GV xác nhận syllabus; nút "Xin giãn / dời buổi" (GV, TA, Học vụ…) và "Giao chặng".
 *  - Trái: syllabus theo buổi (lọc quanh hôm nay / tất cả / đã sửa riêng); Học thuật "Sửa cho lớp" (bắt buộc lý do).
 *  - Phải: Big Test và yêu cầu giãn / dời buổi của lớp — người có quyền duyệt duyệt / từ chối ngay trên thẻ.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';

const props = defineProps({
    klass: { type: Object, required: true },
    syllabus: { type: Object, required: true },
    bigTests: { type: Array, default: () => [] },
    adjustments: { type: Array, default: () => [] },
    slaDays: { type: Number, default: 3 },
    maxExtra: { type: Number, default: 10 },
    canRequest: { type: Boolean, default: false },
    canApprove: { type: Boolean, default: false },
});

const card = 'rounded-xl border border-surface-container-highest bg-surface-container-lowest';
const lessons = computed(() => props.syllabus.lessons ?? []);
const planned = computed(() => props.syllabus.planned_sessions || lessons.value.length);
const progress = computed(() => (planned.value ? Math.round((props.syllabus.taught * 100) / planned.value) : 0));

// Lọc buổi: quanh hôm nay = 3 buổi đã dạy gần nhất + 4 buổi tiếp theo.
const filter = ref('near');
const nextIndex = computed(() => {
    const i = lessons.value.findIndex((l) => !l.taught_at);
    return i === -1 ? lessons.value.length : i;
});
const overriddenCount = computed(() => lessons.value.filter((l) => l.overridden).length);
const rows = computed(() => {
    if (filter.value === 'all') return lessons.value;
    if (filter.value === 'overridden') return lessons.value.filter((l) => l.overridden);
    return lessons.value.slice(Math.max(0, nextIndex.value - 3), nextIndex.value + 4);
});
const segBtn = (active) =>
    ['inline-flex h-8 items-center rounded-lg px-sm font-body-small text-body-small font-semibold transition-colors', active ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'];
const unmarkedText = computed(() => props.syllabus.unmarked?.map((d) => formatDate(d)).join(', '));

// Sửa buổi syllabus cho riêng lớp (Học thuật).
const editing = ref(null);
const editingKey = computed(() => editing.value?.id ?? 0);
const textFields = [
    ['objectives', 'Mục tiêu', 3],
    ['content', 'Content (nội dung từng tiết)', 4],
    ['procedure', 'Quy trình tiết học', 3],
    ['activity_1', 'Hoạt động gợi ý 1', 2],
    ['activity_2', 'Hoạt động gợi ý 2', 2],
    ['homework_guide', 'Bài tập giấy', 2],
    ['homework_quizizz', 'Bài tập Quizizz', 2],
    ['homework_video', 'Bài tập Video', 2],
    ['output_product', 'Sản phẩm đầu ra', 2],
    ['note', 'Ghi chú', 2],
];
// PPT / PPT GVNN là file tải lên ở Kho syllabus (dùng chung mọi lớp), không sửa riêng cho lớp.
const linkFields = [
    ['quizizz_url', 'Link Quizizz'],
    ['test_url', 'Link Mini test / Kiểm tra từ'],
];

// Xin giãn / dời buổi: hộp thoại giữa màn, lớp + chặng điền sẵn.
const asking = ref(false);
const kind = ref('extend');
const KINDS = [
    { value: 'extend', label: 'Giãn thêm buổi', hint: 'Thêm buổi vào cuối lịch của lớp (theo TKB, bỏ ngày nghỉ).' },
    { value: 'postpone', label: 'Dời tiến độ', hint: 'Không thêm buổi, được dạy chậm lại bằng các buổi sẵn có.' },
];
const pending = computed(() => props.adjustments.filter((a) => a.status === 'pending').length);
const statusColor = { pending: 'warning', approved: 'success', rejected: 'error' };
const rejecting = ref(null);
</script>

<template>
    <div class="space-y-md">
        <!-- Tóm tắt chặng -->
        <div v-if="syllabus.stage_label" :class="card" class="flex flex-wrap items-center gap-x-lg gap-y-sm px-lg py-md">
            <div>
                <div class="text-xs text-on-surface-variant">Chặng đang học</div>
                <div class="font-semibold text-on-surface">
                    {{ syllabus.stage_label }}<span v-if="syllabus.curriculum" class="font-normal text-on-surface-variant"> · {{ syllabus.curriculum }}</span>
                </div>
            </div>
            <div class="hidden h-10 w-px bg-surface-container sm:block"></div>
            <div class="min-w-[220px]">
                <div class="text-xs text-on-surface-variant">
                    Đã dạy <b class="text-on-surface">{{ syllabus.taught }}</b> / {{ planned }} buổi · kết thúc dự kiến {{ syllabus.deadline ? formatDate(syllabus.deadline) : 'TKB chưa đủ buổi' }}
                </div>
                <div class="mt-1 h-1.5 w-[220px] overflow-hidden rounded-full bg-surface-container"><div class="h-full rounded-full bg-secondary" :style="{ width: progress + '%' }"></div></div>
            </div>
            <div class="hidden h-10 w-px bg-surface-container sm:block"></div>
            <div>
                <div class="text-xs text-on-surface-variant">GV xác nhận syllabus</div>
                <div v-if="syllabus.confirmed" class="font-semibold text-tertiary">✓ Đã xác nhận · {{ formatDate(syllabus.confirmed_at) }}</div>
                <div v-if="syllabus.confirmed && syllabus.confirm_evidence" class="text-xs text-on-surface-variant">{{ syllabus.confirm_evidence }}</div>
                <div v-else class="flex items-center gap-xs">
                    <span class="font-semibold text-warning">Chờ GV xác nhận</span>
                    <UiForm v-if="syllabus.remind_url" :action="syllabus.remind_url" method="post" class="inline">
                        <UiButton type="submit" size="sm" variant="ghost" icon="notifications_active">Nhắc lại</UiButton>
                    </UiForm>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap gap-xs">
                <UiButton v-if="canRequest" icon="more_time" @click="asking = true">Xin giãn / dời buổi</UiButton>
                <UiButton v-if="can('syllabus.assign')" variant="secondary" :href="route('syllabus.assignments', { class_id: klass.id })">Giao chặng</UiButton>
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-md">
            <!-- Lớp chưa giao chặng: vẫn hiện Big Test và yêu cầu giãn / dời bên cạnh. -->
            <section v-if="!syllabus.stage_label" :class="card" class="min-w-0 flex-[999_1_640px]">
                <UiEmptyState icon="menu_book" title="Chưa giao chặng cho lớp" description="Học thuật giao chặng cho lớp ở màn Giao chặng.">
                    <UiButton v-if="can('syllabus.assign')" icon="assignment_ind" :href="route('syllabus.assignments', { class_id: klass.id })">Giao chặng</UiButton>
                </UiEmptyState>
            </section>
            <!-- Syllabus theo buổi -->
            <section v-else :class="card" class="min-w-0 flex-[999_1_640px] overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-sm px-lg py-md">
                    <h2 class="font-h3 text-h3 text-on-surface">Syllabus theo buổi</h2>
                    <div role="tablist" aria-label="Lọc buổi" class="inline-flex gap-0.5 rounded-xl bg-surface-container-low p-[3px]">
                        <button type="button" role="tab" :aria-selected="filter === 'near' ? 'true' : 'false'" :class="segBtn(filter === 'near')" @click="filter = 'near'">Quanh hôm nay</button>
                        <button type="button" role="tab" :aria-selected="filter === 'all' ? 'true' : 'false'" :class="segBtn(filter === 'all')" @click="filter = 'all'">Tất cả {{ lessons.length }} buổi</button>
                        <button type="button" role="tab" :aria-selected="filter === 'overridden' ? 'true' : 'false'" :class="segBtn(filter === 'overridden')" @click="filter = 'overridden'">
                            Đã sửa riêng {{ overriddenCount }}
                        </button>
                    </div>
                </div>
                <UiAlert v-if="syllabus.unmarked?.length" type="warning" class="mx-lg mb-sm">Buổi {{ unmarkedText }} chưa nộp báo cáo nên chưa ghi nhận Đã dạy, tiến độ chặng đang đứng.</UiAlert>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] border-collapse text-left">
                        <thead>
                            <tr class="bg-surface-container-low text-xs font-semibold text-on-surface-variant">
                                <th class="px-lg py-sm">Buổi</th>
                                <th class="px-sm py-sm">Nội dung</th>
                                <th class="px-sm py-sm">Tiến độ</th>
                                <th class="px-lg py-sm text-right"><span class="sr-only">Thao tác</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in rows" :key="l.id" class="border-t border-surface-container align-top" :data-lesson="l.id">
                                <td class="whitespace-nowrap px-lg py-sm">
                                    <div class="font-bold">Buổi {{ l.session_no }}</div>
                                    <div class="text-xs text-on-surface-variant">{{ l.unit_number ? `Unit ${l.unit_number}` : '' }}</div>
                                </td>
                                <td class="px-sm py-sm">
                                    <div class="flex flex-wrap items-center gap-xs">
                                        <span class="font-semibold">{{ l.title }}</span>
                                        <UiBadge v-if="l.overridden" color="warning" :title="l.override_reason">Sửa riêng</UiBadge>
                                        <UiBadge v-if="l.type_label !== 'Học'">{{ l.type_label }}</UiBadge>
                                        <UiBadge v-if="l.milestone_label" color="info">{{ l.milestone_label }}</UiBadge>
                                    </div>
                                    <div v-if="l.data?.content" class="line-clamp-1 text-xs text-on-surface-variant">{{ l.data.content }}</div>
                                </td>
                                <td class="whitespace-nowrap px-sm py-sm">
                                    <template v-if="l.taught_at">
                                        <UiBadge color="success">Đã dạy {{ formatDate(l.taught_at, 'd/m') }}</UiBadge>
                                        <UiBadge v-if="l.missing_score" color="error" class="ml-xs">Chưa nhập điểm</UiBadge>
                                    </template>
                                    <UiBadge v-else-if="l.planned_date && syllabus.unmarked?.includes(l.planned_date)" color="warning">Chưa ghi nhận</UiBadge>
                                    <span v-else-if="l.planned_date" class="text-xs text-on-surface-variant">Dự kiến {{ formatDate(l.planned_date, 'd/m') }}</span>
                                    <span v-else class="text-xs text-on-surface-subtle">Chưa có lịch</span>
                                </td>
                                <td class="whitespace-nowrap px-lg py-sm text-right">
                                    <template v-if="syllabus.can_edit && l.data">
                                        <button type="button" class="font-body-small text-body-small font-semibold text-primary hover:underline" @click="editing = l">Sửa cho lớp</button>
                                        <UiForm v-if="l.overridden" :action="route('classes.syllabus.override.destroy', [klass.id, l.id])" method="delete" class="ml-sm inline" confirm="Bỏ chỉnh sửa riêng, buổi này quay về bản gốc?">
                                            <button type="submit" class="font-body-small text-body-small font-semibold text-on-surface-variant hover:underline">Về bản gốc</button>
                                        </UiForm>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="4"><UiEmptyState icon="description" :title="filter === 'overridden' ? 'Chưa có buổi nào sửa riêng cho lớp.' : 'Chặng chưa có buổi học.'" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-md">
                <!-- Big Test -->
                <section :class="card" class="px-lg py-md">
                    <div class="flex items-baseline justify-between gap-sm">
                        <h2 class="font-h3 text-h3 text-on-surface">Big Test</h2>
                        <Link v-if="can('syllabus.manage')" :href="route('syllabus.big-tests.results')" class="font-body-small text-body-small font-semibold text-primary hover:underline">Bảng điểm</Link>
                    </div>
                    <p v-if="!bigTests.length" class="mt-sm border-t border-surface-container pt-sm text-on-surface-variant">Lớp chưa có đợt Big Test nào.</p>
                    <div v-for="bt in bigTests" :key="bt.id" class="mt-sm flex items-center justify-between gap-sm border-t border-surface-container pt-sm">
                        <div>
                            <div class="font-semibold">{{ bt.title }}</div>
                            <div class="text-xs text-on-surface-variant">{{ bt.scheduled_at ?? 'Chưa xếp lịch' }}{{ bt.room ? ` · ${bt.room}` : '' }}</div>
                        </div>
                        <UiBadge v-if="bt.past" color="success">Đã diễn ra</UiBadge>
                        <UiBadge v-else-if="bt.days_left !== null && bt.days_left <= 14" color="info">Còn {{ bt.days_left }} ngày</UiBadge>
                        <UiBadge v-else color="neutral">Sắp tới</UiBadge>
                    </div>
                </section>

                <!-- Yêu cầu giãn / dời buổi -->
                <section :class="card" class="px-lg py-md" data-adjustments>
                    <div class="flex items-baseline justify-between gap-sm">
                        <h2 class="font-h3 text-h3 text-on-surface">Yêu cầu giãn / dời buổi</h2>
                        <UiBadge v-if="canApprove && pending" color="error">{{ pending }} chờ bạn duyệt</UiBadge>
                        <button v-else-if="canRequest" type="button" class="font-body-small text-body-small font-semibold text-primary hover:underline" @click="asking = true">+ Gửi yêu cầu</button>
                    </div>
                    <p v-if="!adjustments.length" class="mt-sm border-t border-surface-container pt-sm text-on-surface-variant">Chưa có yêu cầu nào.</p>
                    <template v-for="a in adjustments" :key="a.id">
                        <!-- Người duyệt: duyệt / từ chối ngay trên thẻ -->
                        <div v-if="canApprove && a.status === 'pending'" class="mt-sm space-y-sm rounded-xl border border-primary-fixed bg-primary-light p-sm" :data-adjustment="a.id">
                            <div class="flex justify-between gap-sm">
                                <div>
                                    <div class="font-bold">{{ a.title }}</div>
                                    <div class="text-xs text-on-surface-variant">{{ a.teacher ? `${a.teacher} · ` : '' }}gửi {{ a.created_at }}</div>
                                </div>
                                <UiBadge :color="a.sla_days_left !== null && a.sla_days_left <= 0 ? 'error' : 'warning'" class="self-start">{{
                                    a.sla_days_left !== null && a.sla_days_left <= 0 ? 'Quá hạn duyệt' : `Còn ${a.sla_days_left} ngày`
                                }}</UiBadge>
                            </div>
                            <p class="whitespace-pre-line text-sm">"{{ a.reason }}"</p>
                            <UiForm v-if="rejecting !== a.id" :action="route('syllabus.adjustment-requests.approve', a.id)" method="post" class="flex flex-wrap items-end gap-sm" :confirm="`Duyệt yêu cầu: ${a.title}?`">
                                <input type="hidden" name="from_class" value="1" />
                                <label class="flex flex-col gap-1">
                                    <span class="text-xs text-on-surface-variant">Số buổi duyệt</span>
                                    <input
                                        type="number"
                                        name="extra_sessions"
                                        min="0"
                                        :max="maxExtra"
                                        :value="a.extra_sessions"
                                        class="h-9 w-24 rounded-lg border border-outline-variant px-sm font-semibold focus:border-primary-container focus:ring-primary-container/50"
                                    />
                                </label>
                                <UiButton type="submit" size="sm">Duyệt</UiButton>
                                <UiButton variant="secondary" size="sm" @click="rejecting = a.id">Từ chối</UiButton>
                            </UiForm>
                            <UiForm v-else :action="route('syllabus.adjustment-requests.reject', a.id)" method="post" class="space-y-sm" @success="rejecting = null">
                                <input type="hidden" name="from_class" value="1" />
                                <UiTextarea name="rejection_reason" label="Lý do từ chối" required :rows="2" />
                                <div class="flex gap-sm">
                                    <UiButton type="submit" size="sm" variant="danger">Từ chối</UiButton>
                                    <UiButton variant="secondary" size="sm" @click="rejecting = null">Thôi</UiButton>
                                </div>
                            </UiForm>
                        </div>
                        <div v-else class="mt-sm flex justify-between gap-sm border-t border-surface-container pt-sm" :data-adjustment="a.id">
                            <div class="min-w-0">
                                <div class="font-semibold">{{ a.title }}</div>
                                <div class="truncate text-xs text-on-surface-variant" :title="a.reason">Gửi {{ a.created_at }}{{ a.teacher ? ` · ${a.teacher}` : '' }} · {{ a.reason }}</div>
                                <div v-if="a.status === 'rejected' && a.rejection_reason" class="text-xs text-error">Lý do từ chối: {{ a.rejection_reason }}</div>
                            </div>
                            <UiBadge :color="statusColor[a.status] ?? 'neutral'" class="self-center whitespace-nowrap">{{ a.status_label }}</UiBadge>
                        </div>
                    </template>
                    <p class="mt-sm text-xs text-on-surface-variant">
                        Học thuật phản hồi trong {{ slaDays }} ngày.<template v-if="syllabus.extra_left > 0"> Còn {{ syllabus.extra_left }} buổi giãn đã duyệt chưa dùng.</template>
                    </p>
                </section>
            </aside>
        </div>
    </div>

    <!-- Xin giãn / dời buổi -->
    <UiModal v-if="canRequest" :show="asking" title="Xin giãn / dời buổi" max-width="xl" @close="asking = false">
        <UiForm id="adjustment-form" :action="route('syllabus.adjustment-requests.store')" method="post" class="space-y-md" reset-on-success @success="asking = false" #default="{ errors }">
            <p class="text-xs text-on-surface-variant">{{ klass.name }} · {{ syllabus.stage_label }} · đã dạy {{ syllabus.taught }}/{{ planned }} buổi</p>
            <input type="hidden" name="class_id" :value="klass.id" />
            <input type="hidden" name="from_class" value="1" />
            <fieldset class="space-y-xs">
                <legend class="mb-xs text-xs font-semibold text-on-surface">Loại điều chỉnh <span class="text-error">*</span></legend>
                <div class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                    <label
                        v-for="k in KINDS"
                        :key="k.value"
                        :class="['flex cursor-pointer flex-col gap-1 rounded-xl border p-sm', kind === k.value ? 'border-primary-container bg-primary-light' : 'border-outline-variant']"
                    >
                        <span class="flex items-center gap-xs font-bold"><input v-model="kind" type="radio" name="kind" :value="k.value" class="text-primary-container focus:ring-primary-container" /> {{ k.label }}</span>
                        <span class="text-xs text-on-surface-variant">{{ k.hint }}</span>
                    </label>
                </div>
            </fieldset>
            <div>
                <label for="adjustment-extra" class="mb-xs block text-xs font-semibold text-on-surface">Số buổi {{ kind === 'postpone' ? 'cần dời' : 'cần thêm' }} <span class="text-error">*</span></label>
                <div class="flex items-center gap-sm">
                    <input
                        id="adjustment-extra"
                        type="number"
                        name="extra_sessions"
                        min="1"
                        :max="maxExtra"
                        value="1"
                        required
                        class="h-11 w-28 rounded-lg border border-outline-variant px-sm text-base font-semibold focus:border-primary-container focus:ring-primary-container/50"
                    />
                    <span class="text-xs text-on-surface-variant">buổi · tối đa {{ maxExtra }}</span>
                </div>
                <p v-if="errors.extra_sessions" class="mt-xs text-xs text-error">{{ errors.extra_sessions }}</p>
            </div>
            <UiTextarea name="reason" label="Lý do" required :rows="3" placeholder="Ví dụ: Học sinh chưa nắm vững Unit 3, cần thêm buổi luyện tập trước Big Test 1." />
            <UiErrors :messages="[errors.class_id]" />
            <p class="rounded-lg bg-warning-container px-sm py-xs text-xs text-on-warning-container">Ban Học thuật xem xét và phản hồi trong {{ slaDays }} ngày. Kết quả hiện ở thẻ "Yêu cầu giãn / dời buổi" của tab này.</p>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="asking = false">Hủy</UiButton>
            <UiButton type="submit" form="adjustment-form" icon="send">Gửi yêu cầu</UiButton>
        </template>
    </UiModal>

    <!-- Sửa buổi syllabus cho riêng lớp -->
    <UiModal v-if="syllabus.can_edit" :show="!!editing" :title="editing ? `Sửa Buổi ${editing.session_no} cho lớp ${klass.name}` : ''" max-width="3xl" @close="editing = null">
        <UiForm v-if="editing" id="class-lesson-form" :key="editingKey" :action="route('classes.syllabus.override', [klass.id, editing.id])" method="put" class="space-y-4" @success="editing = null">
            <UiAlert type="info" class="text-xs">Chỉ sửa cho lớp này; các trường không đổi vẫn theo bản gốc. GV của lớp nhận thông báo.</UiAlert>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><UiInput name="title" label="Tên buổi" :value="editing.data.title" /></div>
                <UiSelect name="lesson_type" label="Loại buổi" :value="editing.data.lesson_type ?? 'lesson'" :options="syllabus.lesson_types" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <UiTextarea v-for="[field, text, rows] in textFields" :key="field" :name="field" :label="text" :rows="rows" :value="editing.data[field]" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <UiInput v-for="[field, text] in linkFields" :key="field" type="url" :name="field" :label="text" :value="editing.data[field]" placeholder="https://..." />
            </div>
            <UiSelect name="notice_milestone" label="Mốc thông báo" placeholder="-- Không có --" :value="editing.data.notice_milestone ?? ''" :options="syllabus.milestones" />
            <UiTextarea name="reason" label="Lý do chỉnh sửa riêng cho lớp" required :rows="2" :value="editing.override_reason" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
            <UiButton type="submit" form="class-lesson-form" icon="save">Lưu cho lớp</UiButton>
        </template>
    </UiModal>
</template>
