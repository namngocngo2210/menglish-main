<script setup>
/**
 * Mockup 01_Web_Admin/06: danh sách order đề — bấm dòng → chi tiết & phân phối trong hộp thoại (?order=; đóng thì bỏ query).
 * Đợt thi Big Test (gắn chặng, duyệt & phân phối); tạo đợt thi mới (bản nháp) bằng hộp thoại.
 */
import { nextTick, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';

defineOptions({ layout: { title: 'Duyệt & Phân phối đề Big Test' } });

const props = defineProps({
    classes: { type: Array, default: () => [] },
    defaultScheduledAt: { type: String, default: '' },
    orders: { type: Object, required: true },
    orderStatus: { type: String, default: 'pending' },
    orderSearch: { type: String, default: '' },
    orderStatusOptions: { type: Array, default: () => [] },
    pendingOrders: { type: Number, default: 0 },
    bigTests: { type: Object, required: true },
    selectedOrder: { type: Object, default: null },
    listUrl: { type: String, required: true },
    leadDays: { type: Number, default: 0 },
    canReview: { type: Boolean, default: false },
    // Duyệt / phân phối đề: chỉ Admin (canReview vẫn cho Học thuật xem link đề, gắn chặng).
    canApprove: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const testTypes = [
    { value: 'midterm', label: 'Giữa kỳ (Mid-term)' },
    { value: 'final', label: 'Cuối khóa (Final)' },
];

// Tạo đợt thi mới
const creating = ref(false);

// Gắn chặng cho đợt thi
const staging = ref(null);
const stageValue = ref('');
function openStage(bt) {
    stageValue.value = bt.stage_id;
    staging.value = bt;
}

// Chi tiết order
const orderOpen = ref(!!props.selectedOrder);
watch(() => props.selectedOrder?.id, (id) => (orderOpen.value = !!id));
const link = ref('');
const mode = ref('create');
const rejecting = ref(!!page.props.errors?.rejection_reason);
watch(() => page.props.errors?.rejection_reason, (e) => e && (rejecting.value = true));
async function startReject() {
    rejecting.value = true;
    await nextTick();
    document.getElementById('reject-order-reason')?.focus();
}
function preview() {
    if (link.value) window.open(link.value, '_blank', 'noopener');
}
</script>

<template>
    <UiPageHeader title="Duyệt & Phân phối đề Big Test" description="Quản lý yêu cầu ra đề từ giáo viên và phân phối tài liệu kiểm tra.">
        <template #actions>
            <UiButton v-if="canManage" icon="add_circle" @click="creating = true">Tạo đợt Big Test mới</UiButton>
        </template>
    </UiPageHeader>

    <UiModal v-if="canManage" :show="creating" title="Tạo đợt thi Big Test (bản nháp)" max-width="md" @close="creating = false">
        <UiForm id="new-big-test-form" :action="route('syllabus.big-tests.store')" method="post" class="space-y-3 p-md" reset-on-success @success="creating = false">
            <UiInput name="title" label="Tên đợt thi" required placeholder="Final Big Test #09 (Cuối khóa)" />
            <UiSelect name="class_id" label="Lớp thi" value="" required :options="classes" />
            <UiSelect name="test_type" label="Loại kỳ thi" value="midterm" :options="testTypes" />
            <UiInput type="datetime-local" name="scheduled_at" label="Thời gian thi" required :value="defaultScheduledAt" />
            <UiInput name="room" label="Phòng thi" required placeholder="VD: Phòng Lab 201" />
            <p class="font-caption text-caption text-on-surface-variant">Đợt thi tự gắn với <strong>chặng đang mở</strong> của lớp (Big Test cuối chặng). Khi kết quả của cả lớp được duyệt và gửi phụ huynh, chặng đóng và chặng kế tiếp tự mở.</p>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
            <UiButton type="submit" form="new-big-test-form">Lưu bản nháp</UiButton>
        </template>
    </UiModal>

    <div class="space-y-6">
        <UiDataTable min-width="880px">
            <template #header>
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary">pending_actions</span>Yêu cầu chờ duyệt
                    <UiBadge color="primary" :dot="false" pill>{{ pendingOrders }}</UiBadge>
                </h2>
                <GetForm class="flex items-center gap-2">
                    <UiInput name="order_search" icon="search" :value="orderSearch" placeholder="Tìm tên lớp..." />
                    <UiSelect name="order_status" :value="orderStatus" placeholder="Tất cả" :options="orderStatusOptions" aria-label="Trạng thái order" />
                </GetForm>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Lớp</th>
                        <th>Chặng · Loại đề</th>
                        <th>Giáo viên</th>
                        <th>Ngày thi</th>
                        <th>Hạn xử lý</th>
                        <th>Trạng thái</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="order in orders.data" :key="order.id" :data-href="order.detail_url" :class="['cursor-pointer', { 'bg-primary-fixed/30': selectedOrder?.id === order.id }]">
                        <td>
                            <Link :href="order.detail_url" class="font-semibold text-on-surface hover:text-primary">{{ order.class_label }}</Link>
                            <span class="block font-caption text-caption text-on-surface-variant">Order {{ order.ordered_ago }}</span>
                        </td>
                        <td>{{ order.stage_type }}</td>
                        <td>{{ order.teacher ?? '—' }}</td>
                        <td class="whitespace-nowrap font-code text-body-small">{{ order.exam_date ?? 'chưa chốt' }}</td>
                        <td :class="['whitespace-nowrap font-code text-body-small', { 'font-semibold text-error': order.overdue }]">{{ order.due_date ?? '—' }}</td>
                        <td class="whitespace-nowrap">
                            <UiBadge v-if="order.overdue" color="error">Trễ hạn</UiBadge>
                            <UiBadge v-else-if="order.sla_warning" color="warning">Cảnh báo SLA</UiBadge>
                            <UiBadge v-else :color="order.status_color">{{ order.status_label }}</UiBadge>
                        </td>
                        <td class="text-right"><UiButton variant="secondary" size="sm" icon="visibility" :href="order.detail_url">Xem</UiButton></td>
                    </tr>
                    <tr v-if="!orders.data.length">
                        <td colspan="7"><UiEmptyState icon="inbox" title="Không có order đề nào" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="orders" :options="[]" unit="order" /></template>
        </UiDataTable>

        <!-- Đợt thi Big Test (gắn chặng) -->
        <UiDataTable min-width="980px">
            <template #header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-[20px] text-primary">event_note</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Đợt thi Big Test</h2>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Mã kỳ thi</th>
                        <th>Tên kỳ thi</th>
                        <th>Lớp thi / Chặng</th>
                        <th>Thời gian &amp; Địa điểm</th>
                        <th>Giám thị</th>
                        <th>Mật mã thi</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bt in bigTests.data" :key="bt.id">
                        <td class="font-mono font-bold text-on-surface">{{ bt.code }}</td>
                        <td class="font-semibold text-on-surface">
                            {{ bt.title }}
                            <p v-if="bt.paper_warning" :class="['flex items-center gap-xs font-caption text-caption', bt.paper_warning.level === 'overdue' ? 'text-error' : 'text-warning']">
                                <span class="material-symbols-outlined text-[16px]">{{ bt.paper_warning.level === 'overdue' ? 'error' : 'warning' }}</span>{{ bt.paper_warning.label }}
                            </p>
                            <div class="flex flex-wrap gap-sm font-caption text-caption">
                                <a v-if="bt.content_url" :href="bt.content_url" target="_blank" rel="noopener" class="text-primary hover:underline">Link đề</a>
                                <a v-if="bt.speaking_url" :href="bt.speaking_url" target="_blank" rel="noopener" class="text-primary hover:underline">Phần Speaking</a>
                            </div>
                        </td>
                        <td>
                            <span class="font-semibold text-primary">{{ bt.class_name }}</span>
                            <span :class="['block font-caption text-caption', bt.stage_label ? 'text-on-surface-variant' : 'text-warning']">{{ bt.stage_label ?? 'Chưa gắn chặng' }}</span>
                        </td>
                        <td>
                            <div>{{ bt.scheduled_at ?? '—' }}</div>
                            <div class="font-caption text-caption text-on-surface-variant">{{ bt.room }}</div>
                        </td>
                        <td>{{ bt.proctor ?? '—' }}</td>
                        <td class="font-mono font-bold text-tertiary">{{ bt.passcode }}</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <UiButton v-if="canReview && bt.class_id" variant="ghost" size="sm" icon="flag" title="Gắn chặng cho đợt thi" @click="openStage(bt)">Gắn chặng</UiButton>
                                <template v-if="!bt.is_distributed">
                                    <UiForm v-if="canApprove" :action="route('syllabus.big-tests.approve', bt.id)" method="post">
                                        <UiButton type="submit" variant="secondary" size="sm" icon="task_alt">Duyệt &amp; phân phối</UiButton>
                                    </UiForm>
                                    <UiBadge v-else color="warning">Chờ duyệt đề</UiBadge>
                                </template>
                                <UiBadge v-else color="success">Đã phân phối</UiBadge>
                                <UiButton variant="ghost" size="sm" :href="route('syllabus.big-tests.results', bt.id)">Bảng điểm</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!bigTests.data.length">
                        <td colspan="7"><UiEmptyState icon="event_busy" title="Chưa có kỳ thi Big Test nào" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="bigTests" unit="đợt thi" /></template>
        </UiDataTable>
    </div>

    <UiModal v-if="canReview" :show="!!staging" title="Gắn chặng cho đợt Big Test" max-width="md" @close="staging = null">
        <UiForm v-if="staging" id="big-test-stage-form" :key="staging.id" :action="route('syllabus.big-tests.stage', staging.id)" method="post" class="space-y-3 p-md" @success="staging = null">
            <p class="font-body-small text-body-small text-on-surface-variant">Đợt thi: <strong>{{ staging.code }} · {{ staging.class_name }}</strong>. Big Test cuối chặng: khi kết quả được duyệt và gửi đủ phụ huynh, chặng đang mở tương ứng của lớp sẽ đóng và chặng kế tiếp tự mở.</p>
            <UiSelect v-model="stageValue" label="Chặng" name="syllabus_stage_id" placeholder="— Không gắn chặng —" :options="staging.stage_options" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="staging = null">Hủy</UiButton>
            <UiButton type="submit" form="big-test-stage-form" icon="save">Lưu</UiButton>
        </template>
    </UiModal>

    <!-- Chi tiết & phân phối order đề: mở sẵn khi URL có ?order=; đóng → bỏ order khỏi thanh địa chỉ. -->
    <UiModal v-if="selectedOrder" :show="orderOpen" :title="'Order đề ' + selectedOrder.code" max-width="3xl" :dismiss-url="listUrl" @close="orderOpen = false">
        <div class="space-y-lg">
            <div class="space-y-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="rounded bg-surface-container-high px-sm py-0.5 font-label text-label text-on-surface-variant">CLASS ID: {{ selectedOrder.class_code ?? '—' }}</span>
                    <UiBadge :color="selectedOrder.status_color">{{ selectedOrder.status_label }}</UiBadge>
                </div>
                <h3 class="font-h2 text-h2 text-on-surface">{{ selectedOrder.class_label }}</h3>
                <p class="font-body-small text-body-small text-on-surface-variant">{{ selectedOrder.summary }}</p>
                <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                    <div>
                        <dt class="font-label text-label uppercase text-on-surface-variant">Ngày thi dự kiến</dt>
                        <dd class="mt-xs font-code text-body-small text-on-surface">{{ selectedOrder.exam_date ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-label text-label uppercase text-on-surface-variant">Giáo viên</dt>
                        <dd class="mt-xs font-body-medium text-body-small text-on-surface">{{ selectedOrder.teacher ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-label text-label uppercase text-on-surface-variant">Hạn xử lý</dt>
                        <dd :class="['mt-xs font-code text-body-small', selectedOrder.overdue ? 'font-semibold text-error' : 'text-on-surface']">{{ selectedOrder.due_date ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div>
                <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Yêu cầu từ Giáo viên</p>
                <p class="whitespace-pre-line rounded-lg border-l-4 border-primary-container bg-surface-container-low p-md font-body-small text-body-small italic text-on-surface">{{ selectedOrder.note ? `"${selectedOrder.note}"` : 'Không có ghi chú.' }}</p>
            </div>

            <UiAlert v-if="selectedOrder.status === 'approved'" type="success" class="space-y-1 font-body-small text-body-small">
                <p class="font-semibold">Đã duyệt bởi {{ selectedOrder.reviewer }} lúc {{ selectedOrder.reviewed_at }}</p>
                <a v-if="canReview" :href="selectedOrder.test_link" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary underline"><span class="material-symbols-outlined text-[16px]">link</span>Link đề</a>
                <a v-if="selectedOrder.speaking_link" :href="selectedOrder.speaking_link" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary underline"><span class="material-symbols-outlined text-[16px]">record_voice_over</span>Phần Speaking</a>
                <p v-else-if="!canReview" class="text-on-surface-variant">Học thuật chưa gửi link phần Speaking.</p>
                <p v-if="selectedOrder.big_test">Đợt thi: <strong>{{ selectedOrder.big_test.code }}</strong> · {{ selectedOrder.big_test.scheduled_at }} · {{ selectedOrder.big_test.room }}</p>
            </UiAlert>
            <UiAlert v-else-if="selectedOrder.status === 'rejected'" type="error" class="font-body-small text-body-small">
                <p class="font-semibold">Đã từ chối bởi {{ selectedOrder.reviewer }} lúc {{ selectedOrder.reviewed_at }}</p>
                <p class="mt-1">Lý do: {{ selectedOrder.rejection_reason }}</p>
            </UiAlert>
            <div v-else-if="selectedOrder.reviewing" class="space-y-md">
                <p class="font-label text-label uppercase text-on-surface-variant">Phân phối đề</p>
                <UiForm v-show="!rejecting" id="approve-order-form" :action="route('syllabus.big-tests.orders.approve', selectedOrder.id)" method="post" class="space-y-md">
                    <UiInput v-model="link" type="url" name="test_link" label="Link đề Big Test (Folder lớp)" icon="link" required placeholder="Dán link Google Drive hoặc OneDrive tại đây..." />
                    <UiInput type="url" name="speaking_link" label="Link phần Speaking (GV xem sau khi phân phối)" icon="record_voice_over" placeholder="Link riêng phần Speaking (tùy chọn)..." />
                    <div v-if="selectedOrder.is_big" class="space-y-md rounded-lg border border-outline-variant p-md" data-testid="order-big-test-mode">
                        <p class="font-body-medium text-body-medium font-semibold text-on-surface">Đợt Big Test</p>
                        <div class="flex flex-wrap gap-md font-body-small text-body-small">
                            <label class="flex items-center gap-xs"><input v-model="mode" type="radio" value="create" /> Tạo đợt thi mới</label>
                            <label v-if="selectedOrder.class_tests.length" class="flex items-center gap-xs"><input v-model="mode" type="radio" value="link" /> Gắn đợt thi có sẵn</label>
                        </div>
                        <div v-show="mode === 'create'" class="grid grid-cols-1 gap-md sm:grid-cols-2">
                            <UiInput type="datetime-local" name="scheduled_at" label="Ngày giờ thi" :disabled="mode !== 'create'" :value="selectedOrder.default_scheduled_at" />
                            <UiInput name="room" label="Phòng thi" :value="selectedOrder.default_room" placeholder="VD: Phòng Lab 201" :disabled="mode !== 'create'" />
                            <p class="font-caption text-caption text-on-surface-variant sm:col-span-2">Hệ thống tạo đợt Big Test gắn <strong>chặng đang mở</strong> của lớp và phân phối luôn để giáo viên nhập kết quả.</p>
                        </div>
                        <div v-if="selectedOrder.class_tests.length" v-show="mode === 'link'">
                            <UiSelect name="big_test_id" label="Gắn vào đợt thi" placeholder="-- Chọn đợt thi --" value="" :disabled="mode !== 'link'" :options="selectedOrder.class_tests" />
                        </div>
                    </div>
                </UiForm>

                <UiAlert v-if="selectedOrder.overdue || selectedOrder.sla_warning" type="error" class="font-body-small text-body-small" :title="selectedOrder.overdue ? 'Cảnh báo: Phân phối trễ hạn SLA' : 'Cảnh báo: Sắp hết hạn SLA'">
                    <p>Hệ thống ghi nhận cần hoàn thành phân phối trước {{ leadDays }} ngày so với lịch thi (Hạn chót: {{ selectedOrder.due_date }}). Vui lòng ưu tiên xử lý ngay.</p>
                </UiAlert>

                <UiForm v-show="rejecting" id="reject-order-form" :action="route('syllabus.big-tests.orders.reject', selectedOrder.id)" method="post">
                    <UiTextarea id="reject-order-reason" name="rejection_reason" label="Lý do từ chối" required rows="3" />
                </UiForm>
            </div>
        </div>

        <template v-if="selectedOrder.reviewing" #footer>
            <div v-show="!rejecting" class="flex w-full flex-wrap items-center justify-between gap-sm">
                <UiButton variant="danger-text" icon="close" @click="startReject">Từ chối yêu cầu</UiButton>
                <div class="flex flex-wrap items-center gap-sm">
                    <UiButton variant="secondary" icon="visibility" :disabled="!link" @click="preview">Xem trước tệp</UiButton>
                    <UiButton type="submit" form="approve-order-form" icon="send">Phê duyệt &amp; Phân phối</UiButton>
                </div>
            </div>
            <div v-show="rejecting" class="flex flex-wrap justify-end gap-sm">
                <UiButton variant="secondary" @click="rejecting = false">Quay lại</UiButton>
                <UiButton type="submit" form="reject-order-form" variant="danger" icon="close">Xác nhận từ chối</UiButton>
            </div>
        </template>
    </UiModal>
</template>
