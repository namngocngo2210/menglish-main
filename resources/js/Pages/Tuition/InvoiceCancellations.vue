<script setup>
/**
 * Duyệt hủy hóa đơn (mockup duyet-huy-hoa-don). Duyệt / từ chối theo quyền invoice.approve_cancel
 * (mặc định chỉ Admin); tạo yêu cầu theo invoice.request_cancel.
 * Bấm dòng → chi tiết mở trong hộp thoại (?selected_id=; đóng thì bỏ query).
 */
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { currentUrl, urlWith } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Duyệt hủy hóa đơn', hideErrors: true } });

const props = defineProps({
    filters: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    pendingCount: { type: Number, default: 0 },
    approvedMonthCount: { type: Number, default: 0 },
    rejectedCount: { type: Number, default: 0 },
    canApproveCancel: { type: Boolean, default: false },
    cancellations: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    prefill: { type: Object, default: null },
});

const page = usePage();
const errorList = computed(() => Object.values(page.props.errors ?? {}).flat());
const userName = computed(() => page.props.shell?.user?.name ?? 'Admin');

const branchOptions = computed(() => [{ value: 'all', label: 'Tất cả cơ sở' }, ...props.branches]);
const statusOptions = computed(() => [
    { value: 'pending', label: `Trạng thái: Chờ duyệt hủy (${props.pendingCount})` },
    { value: 'approved', label: 'Đã duyệt hủy' },
    { value: 'rejected', label: 'Đã từ chối hủy' },
    { value: 'all', label: 'Tất cả' },
]);
const exportUrl = computed(() => route('tuition.invoices.cancellations.export') + currentUrl().search);
const autoSubmit = (event) => event.target.form?.requestSubmit();
const detailUrl = (id) => urlWith({ selected_id: id });

function openDetail(item, event) {
    if (event.target.closest('a, button, input, select, textarea, label')) return;
    router.get(detailUrl(item.id), {}, { preserveScroll: true, preserveState: true });
}

const detailOpen = ref(!!props.selected);
watch(
    () => props.selected?.id,
    (id) => (detailOpen.value = !!id),
);
function closeDetail() {
    detailOpen.value = false;
    router.get(urlWith({ selected_id: null }), {}, { preserveScroll: true, preserveState: true, replace: true });
}

const confirmOpen = ref(false);
const rejectOpen = ref(false);
const zoomOpen = ref(false);
const newOpen = ref(!!props.prefill);
const newKey = ref(0);
function openNew() {
    newKey.value++;
    newOpen.value = true;
}
const onRejectError = (errors) => {
    if (!errors.rejection_reason) rejectOpen.value = false;
};
const invoiceStatus = (status) => (status === 'approved' ? 'Đã duyệt hủy' : status === 'rejected' ? 'Bị từ chối' : 'Chờ duyệt hủy');
</script>

<template>
    <UiPageHeader title="Duyệt hủy hóa đơn" description="Kiểm soát và phê duyệt các yêu cầu hủy hóa đơn thu học phí & phụ thu từ Học vụ / CM. Chống thất thoát và nhảy số hóa đơn tự ý.">
        <template #breadcrumbs>
            <Link :href="route('tuition.students')" class="hover:text-primary">Học phí &amp; Hóa đơn</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Duyệt hủy hóa đơn</span>
            <UiBadge color="warning" class="ml-sm">{{ pendingCount }} yêu cầu chờ xử lý</UiBadge>
            <UiBadge color="neutral" :dot="false">Admin phê duyệt (theo phân quyền)</UiBadge>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="exportUrl" native>Xuất danh sách</UiButton>
            <!-- Việc chính của màn là duyệt → nút tạo yêu cầu là nút viền; màu đỏ chỉ ở nút xác nhận trong hộp thoại. -->
            <UiButton v-if="can('invoice.request_cancel')" variant="secondary" icon="add_circle" @click="openNew">Yêu cầu hủy HĐ</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="!selected && !newOpen && errorList.length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(message, i) in errorList" :key="i">{{ message }}</li>
        </ul>
    </UiAlert>

    <div class="mx-auto max-w-[1520px] space-y-5">
        <div class="flex flex-col gap-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-base font-bold text-on-surface">Báo cáo kiểm toán dải số hóa đơn &amp; yêu cầu hủy</h2>
                <p class="text-xs text-on-surface-variant">Mọi thao tác duyệt hủy đều kích hoạt cơ chế trừ lùi doanh thu và hoàn trả công nợ học viên tự động</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <UiStatCard label="Chờ duyệt hủy" :value="pendingCount + ' hóa đơn'" tone="warning" />
                <UiStatCard label="Đã duyệt hủy (Tháng này)" :value="approvedMonthCount + ' hóa đơn'" tone="success" />
                <UiStatCard label="Đã từ chối" :value="rejectedCount + ' yêu cầu'" />
            </div>
        </div>

        <UiAlert type="info" title="Quy tắc nghiệp vụ kiểm soát số hóa đơn & hoàn tác công nợ (Cập nhật 11/09/2026):">
            <div class="grid gap-x-6 gap-y-1 text-xs md:grid-cols-2">
                <p>• <strong>Bảo toàn dải số:</strong> Số hóa đơn giấy đã hủy vẫn tính là <em>đã sử dụng</em> trong dải số, tuyệt đối không được cấp phát hay tái sử dụng lại.</p>
                <p>• <strong>Tự động hoàn tác công nợ:</strong> Khi duyệt hủy phiếu từng ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ lùi số tiền đã thu và hoàn trả công nợ còn lại của học viên.</p>
            </div>
        </UiAlert>

        <UiFilterBar :action="route('tuition.invoices.cancellations')" search="q" placeholder="Tìm theo mã phiếu, số hóa đơn, học viên..." class="!mb-0">
            <UiSelect name="branch_id" label="Cơ sở" :options="branchOptions" :value="filters.branch_id" @change="autoSubmit" />
            <UiSelect name="status" label="Trạng thái" :options="statusOptions" :value="filters.status" @change="autoSubmit" />
        </UiFilterBar>

        <UiDataTable min-width="960px" sticky="last">
            <template #header>
                <div class="flex items-center gap-2">
                    <h3 class="font-h3 text-h3 text-on-surface">Danh sách yêu cầu hủy</h3>
                    <UiBadge color="warning" pill :dot="false" class="font-code">{{ cancellations.length }}</UiBadge>
                </div>
                <span class="text-xs text-on-surface-variant">Mới gửi xếp trước. Phiếu đang có yêu cầu chờ duyệt không tạo thêm yêu cầu thứ 2.</span>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Số hóa đơn</th>
                        <th>Phiếu thu</th>
                        <th>Học viên</th>
                        <th class="text-right">Số tiền</th>
                        <th>Lý do hủy</th>
                        <th>Người yêu cầu · Gửi lúc</th>
                        <th>Trạng thái</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in cancellations" :key="item.id" :class="['cursor-pointer', selected?.id === item.id ? 'bg-primary-container/5' : '']" tabindex="0" @click="openDetail(item, $event)" @keydown.enter.self="openDetail(item, $event)">
                        <td class="whitespace-nowrap">
                            <Link :href="detailUrl(item.id)" preserve-scroll preserve-state class="font-code font-semibold text-on-surface hover:text-primary">{{ item.invoice_number }}</Link>
                        </td>
                        <td class="whitespace-nowrap font-code text-on-surface-variant">
                            <UiCode v-if="item.receipt_number" :value="item.receipt_number" />
                            <template v-else>PT-Trực tiếp</template>
                        </td>
                        <td class="min-w-[10rem]">
                            <div class="font-semibold">{{ item.student_name ?? '—' }}</div>
                            <UiCode :value="item.student_code" class="font-code text-xs text-on-surface-subtle" />
                        </td>
                        <td class="whitespace-nowrap text-right"><UiMoney :value="item.amount" class="font-bold" /></td>
                        <td class="max-w-[260px]"><p class="line-clamp-2 text-on-surface-variant" :title="item.reason">{{ item.reason }}</p></td>
                        <td>
                            <div>{{ item.requester_name ?? '—' }}</div>
                            <div class="whitespace-nowrap text-xs text-on-surface-subtle" :title="item.created_at">{{ item.created_ago }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <UiBadge v-if="item.status === 'approved'" color="success" :dot="false">Đã duyệt hủy</UiBadge>
                            <UiBadge v-else-if="item.status === 'rejected'" color="error" :dot="false">Đã từ chối</UiBadge>
                            <UiBadge v-else color="warning" :dot="false">Chờ duyệt</UiBadge>
                        </td>
                        <td class="text-right">
                            <UiButton variant="secondary" size="sm" icon="visibility" :href="detailUrl(item.id)" preserve-scroll preserve-state>Xem</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!cancellations.length">
                        <td colspan="8"><UiEmptyState icon="task" title="Không có yêu cầu hủy hóa đơn nào." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <template v-if="selected">
        <!-- Chi tiết yêu cầu hủy -->
        <UiModal :show="detailOpen" :title="'Yêu cầu hủy hóa đơn ' + selected.invoice_number" max-width="4xl" @close="closeDetail">
            <div class="space-y-5" :data-cancellation-detail="selected.id">
                <UiAlert v-if="errorList.length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
                    <ul class="list-inside list-disc space-y-0.5">
                        <li v-for="(message, i) in errorList" :key="i">{{ message }}</li>
                    </ul>
                </UiAlert>

                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-surface-container-highest pb-4">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <UiBadge v-if="selected.status === 'approved'" color="success" pill>Đã duyệt hủy</UiBadge>
                            <UiBadge v-else-if="selected.status === 'rejected'" color="error" pill>Đã từ chối hủy</UiBadge>
                            <UiBadge v-else color="error" pill>Đang yêu cầu hủy</UiBadge>
                        </div>
                        <p class="mt-1 text-xs text-on-surface-variant">
                            Gắn với phiếu thu: <strong class="font-code text-on-surface"><UiCode :value="selected.receipt?.receipt_number" /></strong> • Tạo ngày {{ selected.created_date }} bởi
                            <span class="font-medium text-on-surface-variant">{{ selected.requester_name ?? 'Chưa cập nhật' }}</span>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-on-surface-variant">Trạng thái phiếu thu:</span>
                            <UiBadge :color="selected.receipt?.status_color ?? 'neutral'" :dot="false">{{ selected.receipt?.status_label ?? 'Không xác định phiếu thu' }}</UiBadge>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-on-surface-variant">Trạng thái hóa đơn:</span>
                            <UiBadge color="warning" :dot="false">{{ invoiceStatus(selected.status) }}</UiBadge>
                        </div>
                    </div>
                </div>

                <!-- 1. Lý do yêu cầu hủy -->
                <div class="space-y-2 rounded-xl border border-error/30 bg-error-container/40 p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-xs font-bold uppercase text-on-error-container">
                            <span class="material-symbols-outlined text-base text-error">report_problem</span>
                            Thông tin yêu cầu hủy hóa đơn
                        </div>
                        <span class="text-xs font-medium text-error">Bắt buộc xem xét kỹ</span>
                    </div>
                    <div class="grid grid-cols-1 gap-3 pt-1 text-xs md:grid-cols-2">
                        <div>
                            <span class="text-xs text-error">Người gửi yêu cầu:</span>
                            <div class="mt-0.5 font-bold text-on-surface">{{ selected.requester_name ?? 'Chưa cập nhật' }} (Học vụ - {{ selected.branch_name }})</div>
                        </div>
                        <div>
                            <span class="text-xs text-error">Thời gian gửi:</span>
                            <div class="mt-0.5 font-code font-bold text-on-surface">{{ selected.created_at }}</div>
                        </div>
                    </div>
                    <div class="border-t border-error/20 pt-2 text-xs">
                        <span class="mb-1 block font-bold text-on-error-container">Nội dung giải trình lý do hủy (*):</span>
                        <p class="rounded-lg border border-error/30 bg-surface-container-lowest p-3 italic leading-relaxed text-on-surface">"{{ selected.reason }}"</p>
                    </div>
                </div>

                <!-- 2. Tác động tài chính -->
                <UiAlert type="warning" title='Hệ thống sẽ tự động thực hiện khi Admin bấm "Duyệt hủy hóa đơn":'>
                    <ul class="list-disc space-y-1.5 pl-5 text-xs leading-relaxed">
                        <li>
                            <strong>Trừ lùi công nợ (Revert):</strong> Vì phiếu <code>{{ selected.receipt?.receipt_number ?? 'Gốc' }}</code> trước đó đã ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ <strong>{{ formatMoney(selected.amount) }}</strong> khỏi
                            <code>tong_da_thu</code> và cộng ngược <strong>{{ formatMoney(selected.amount) }}</strong> vào <code>tong_con_lai</code> của học viên {{ selected.student_name }}.
                        </li>
                        <li><strong>Khóa số hóa đơn:</strong> Số hóa đơn <code>{{ selected.invoice_number }}</code> chuyển thành <em>"Đã hủy"</em>, giữ nguyên lịch sử kiểm toán trong dải số và <strong>không được tái sử dụng</strong>.</li>
                        <li><strong>Ghi nhật ký hệ thống:</strong> Lưu vết người duyệt là <em>{{ userName }}</em> cùng thời điểm thực thi.</li>
                    </ul>
                </UiAlert>

                <!-- 3. Phiếu thu gốc -->
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Thông tin phiếu thu gốc</h4>
                    <div class="grid grid-cols-2 gap-3 rounded-xl border border-surface-container-highest bg-surface-container-low p-4 text-xs sm:grid-cols-3">
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Học viên</span>
                            <span class="font-bold text-on-surface">{{ selected.student_name ?? '—' }}</span>
                            <span class="block font-code text-xs text-on-surface-variant">Mã: <UiCode :value="selected.student_code" /></span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Lớp học hiện tại</span>
                            <span class="font-semibold text-on-surface">{{ selected.class_name }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ selected.branch_name }}</span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Người nộp tiền</span>
                            <span class="font-semibold text-on-surface">{{ selected.receipt?.payer_name ?? selected.payer_name }}</span>
                            <span class="block font-code text-xs text-on-surface-variant">{{ selected.receipt?.payer_phone ?? selected.payer_phone }}</span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Hình thức thu</span>
                            <span class="mt-0.5 flex items-center gap-1 font-semibold text-on-surface">
                                <span class="material-symbols-outlined text-sm text-on-surface-variant">payments</span>
                                {{ selected.receipt?.payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản' }}
                            </span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Số hóa đơn giấy</span>
                            <span class="font-code font-bold text-error">{{ selected.invoice_number }}</span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Hóa đơn đỏ (VAT)</span>
                            <span class="font-medium text-on-surface-variant">{{ selected.receipt?.is_vat_invoice ? 'Yêu cầu VAT' : 'Không yêu cầu' }}</span>
                        </div>
                    </div>

                    <UiDataTable>
                        <table>
                            <thead>
                                <tr>
                                    <th>Khoản mục</th>
                                    <th>Nội dung chi tiết</th>
                                    <th class="text-right">Số tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="selected.receipt && selected.receipt.tuition_amount > 0">
                                    <td class="font-semibold">Học phí đào tạo</td>
                                    <td class="text-on-surface-variant">Khóa {{ selected.class_name }} (Đã giảm trừ: {{ formatMoney(selected.receipt.discount_amount) }})</td>
                                    <td><UiMoney :value="selected.receipt.tuition_amount" class="font-semibold" /></td>
                                </tr>
                                <tr v-if="selected.receipt && selected.receipt.surcharge_amount > 0">
                                    <td class="flex items-center gap-1 font-semibold text-primary"><span class="h-1.5 w-1.5 rounded-full bg-primary-container"></span> Phụ thu phát sinh</td>
                                    <td class="text-on-surface-variant">{{ selected.receipt.surcharge_reason || 'Phụ thu giáo trình & học liệu' }}</td>
                                    <td class="text-right font-code font-semibold text-primary">+{{ formatMoney(selected.receipt.surcharge_amount) }}</td>
                                </tr>
                                <tr class="bg-surface-container-low font-bold">
                                    <td colspan="2" class="font-bold">TỔNG SỐ TIỀN TRÊN HÓA ĐƠN:</td>
                                    <td class="text-right font-code font-bold text-primary">{{ formatMoney(selected.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>

                    <div class="space-y-2 rounded-xl border border-surface-container-highest p-4">
                        <div class="flex items-center justify-between text-xs">
                            <span class="flex items-center gap-1.5 font-bold text-on-surface">
                                <span class="material-symbols-outlined text-base text-on-surface-variant">image</span>
                                Ảnh chụp minh chứng hóa đơn hỏng / gạch chéo hủy:
                            </span>
                            <span class="font-code text-xs text-on-surface-subtle">{{ selected.proof_name ?? 'Không có tệp' }}</span>
                        </div>
                        <div class="relative flex min-h-[160px] items-center justify-center overflow-hidden rounded-xl border border-inverse-surface bg-inverse-surface p-4 text-inverse-on-surface">
                            <img v-if="selected.proof_image" :src="selected.proof_image" alt="Minh chứng hủy" class="max-h-56 cursor-pointer rounded-lg object-contain" @click="zoomOpen = true" role="button" tabindex="0" @keydown.enter.self.prevent="zoomOpen = true" @keydown.space.self.prevent="zoomOpen = true" />
                            <div v-else class="space-y-2 text-center">
                                <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-inverse-on-surface/70">
                                    <span class="material-symbols-outlined text-2xl">image_not_supported</span>
                                </div>
                                <div class="text-xs text-inverse-on-surface/70">Người yêu cầu không đính kèm ảnh hóa đơn hỏng / gạch chéo. Đối chiếu bản giấy trước khi duyệt.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quyền duyệt & kết quả -->
                <div class="space-y-3 border-t border-surface-container-highest pt-4">
                    <div class="flex items-center justify-between text-xs text-on-surface-variant">
                        <span>Quyền thực hiện: <strong class="text-on-surface-variant">Duyệt hủy hóa đơn</strong> (mặc định Admin)<template v-if="canApproveCancel"> — {{ userName }}</template></span>
                        <span class="text-on-surface-subtle">Hệ thống ghi nhận thời điểm thao tác chính xác vào Audit Log</span>
                    </div>
                    <UiAlert v-if="selected.status === 'pending' && !canApproveCancel" type="warning">Yêu cầu đang chờ Admin (hoặc người được cấp quyền duyệt hủy hóa đơn) phê duyệt.</UiAlert>
                    <UiAlert v-else-if="selected.status === 'approved'" type="success">
                        <div class="flex items-center justify-between gap-sm">
                            <span class="font-bold">Hóa đơn đã được Admin phê duyệt hủy và hoàn tác công nợ.</span>
                            <span class="font-code text-xs">{{ selected.updated_at }}</span>
                        </div>
                    </UiAlert>
                    <UiAlert v-else-if="selected.status === 'rejected'" type="error">
                        <div class="flex items-center justify-between gap-sm">
                            <span class="font-bold">Yêu cầu hủy đã bị Admin từ chối: {{ selected.rejection_reason }}</span>
                            <span class="font-code text-xs">{{ selected.updated_at }}</span>
                        </div>
                    </UiAlert>
                </div>
            </div>

            <template v-if="selected.status === 'pending' && canApproveCancel" #footer>
                <p class="mr-auto hidden items-center gap-1.5 self-center text-xs text-on-surface-variant md:flex">
                    <span class="material-symbols-outlined text-base text-warning" aria-hidden="true">verified_user</span>
                    Duyệt sẽ hoàn tác công nợ và khóa vĩnh viễn số hóa đơn này.
                </p>
                <UiButton variant="secondary" icon="close" @click="rejectOpen = true">Từ chối hủy</UiButton>
                <UiButton icon="delete_forever" @click="confirmOpen = true">Duyệt hủy hóa đơn</UiButton>
            </template>
        </UiModal>

        <!-- Xác nhận lần 2 khi duyệt hủy -->
        <UiModal :show="confirmOpen" title="Xác nhận duyệt hủy hóa đơn?" max-width="md" @close="confirmOpen = false">
            <div class="space-y-4">
                <p class="text-xs text-on-surface-variant">Hành động này là quyết định cuối cùng của Admin và không thể hoàn tác.</p>
                <div class="space-y-1.5 rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-xs">
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Số hóa đơn hủy:</span>
                        <strong class="font-code text-on-surface">{{ selected.invoice_number }}</strong>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Số tiền hủy &amp; hoàn công nợ:</span>
                        <strong class="font-code font-bold text-error">{{ formatMoney(selected.amount) }}</strong>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Học viên hưởng revert:</span>
                        <strong class="text-on-surface">{{ selected.student_name }} ({{ selected.student_code }})</strong>
                    </div>
                </div>
                <UiAlert type="warning">
                    <span class="text-xs"><strong>Lưu ý:</strong> Số hóa đơn <code>{{ selected.invoice_number }}</code> sẽ bị đánh dấu <strong>Đã hủy</strong> và nằm lại trong dải số, không cấp lại cho bất kỳ ai.</span>
                </UiAlert>
            </div>
            <UiForm id="confirm-cancellation-form" :action="route('tuition.invoices.cancellations.approve', selected.id)" method="post" @finish="confirmOpen = false" />
            <template #footer>
                <UiButton variant="secondary" @click="confirmOpen = false">Quay lại kiểm tra</UiButton>
                <UiButton variant="danger" type="submit" form="confirm-cancellation-form">Xác nhận duyệt hủy ngay</UiButton>
            </template>
        </UiModal>

        <!-- Từ chối duyệt hủy -->
        <UiModal :show="rejectOpen" title="Từ chối duyệt hủy hóa đơn" max-width="md" @close="rejectOpen = false">
            <p class="mb-3 font-code text-xs text-on-surface-variant">{{ selected.invoice_number }}</p>
            <UiForm id="reject-cancellation-form" :action="route('tuition.invoices.cancellations.reject', selected.id)" method="post" class="space-y-3" @success="rejectOpen = false" @error="onRejectError">
                <UiTextarea name="rejection_reason" label="Lý do từ chối yêu cầu hủy" required rows="3" placeholder="Nhập lý do chi tiết (ví dụ: Hóa đơn chưa có chữ ký xác nhận của phụ huynh, thông tin hợp lệ không cần hủy)..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="rejectOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="reject-cancellation-form">Xác nhận từ chối</UiButton>
            </template>
        </UiModal>

        <!-- Phóng to ảnh minh chứng -->
        <UiModal :show="zoomOpen" :title="'Minh chứng hủy: ' + selected.invoice_number" max-width="3xl" @close="zoomOpen = false">
            <div class="flex min-h-[300px] items-center justify-center">
                <img v-if="selected.proof_image" :src="selected.proof_image" alt="Minh chứng hủy" class="max-h-[70vh] rounded-lg object-contain" />
                <UiEmptyState v-else icon="receipt_long" title="Chưa có minh chứng" />
            </div>
        </UiModal>
    </template>

    <!-- Tạo yêu cầu hủy mới -->
    <UiModal :show="newOpen" title="Yêu cầu hủy hóa đơn thu tiền" max-width="md" @close="newOpen = false">
        <UiForm id="new-cancel-form" :key="newKey" :action="route('tuition.invoices.cancellations.store')" method="post" class="space-y-3" @success="newOpen = false" #default="{ errors }">
            <UiAlert v-if="Object.keys(errors).length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
                <ul class="list-inside list-disc space-y-0.5">
                    <li v-for="(message, key) in errors" :key="key">{{ message }}</li>
                </ul>
            </UiAlert>
            <UiInput name="invoice_number" label="Số Hóa đơn / Biên lai cần hủy" placeholder="Ví dụ: C26MEN-0001001" required class="font-code font-bold" :value="prefill?.invoice_number" hint="Số HĐĐT của phiếu đã duyệt, hoặc số hóa đơn giấy tiền mặt hệ thống đã cấp (kể cả phiếu chưa duyệt, khi ghi sai số trên giấy). Phiếu lập mới sẽ nhận số kế tiếp." />
            <UiField label="Số tiền trên hóa đơn (VNĐ)" for="cancel_amount" required>
                <UiInput type="number" name="amount" id="cancel_amount" placeholder="13500000" required class="font-code font-bold text-error" :value="prefill?.amount" />
                <p v-if="errors.amount" class="font-caption text-caption text-error">Số tiền phải khớp giá trị hóa đơn. {{ errors.amount }}</p>
            </UiField>
            <UiField label="Đính kèm ảnh hóa đơn hỏng / gạch chéo" name="proof_image" for="cancel_proof_image">
                <input id="cancel_proof_image" type="file" name="proof_image" accept="image/*,.pdf" class="w-full rounded-lg border border-surface-container-highest bg-surface-container-low p-2 text-xs" />
            </UiField>
            <UiTextarea name="reason" id="cancel_reason" label="Lý do giải trình hủy hóa đơn" rows="3" placeholder="Nhập lý do chi tiết (ví dụ: Viết sai tên phụ huynh, rách liên đỏ khi xé hóa đơn giao khách)..." required />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="newOpen = false">Hủy bỏ</UiButton>
            <UiButton variant="danger" type="submit" form="new-cancel-form">Gửi yêu cầu hủy</UiButton>
        </template>
    </UiModal>
</template>
