<script setup>
/**
 * Lịch sử thu học phí (mockup lich-su-thu-hoc-phi): tra cứu phiếu thu, xem chi tiết ở ngăn bên phải, in phiếu thu.
 * Sửa phiếu nháp / bị trả về mở modal 4xl; lưu xong trang tự có dữ liệu mới (giữ bộ lọc).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatNumber } from '@/lib/format';
import { currentUrl } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Lịch sử thu học phí' } });

const props = defineProps({
    receipts: { type: Object, required: true },
    filters: { type: Object, required: true },
    student: { type: Object, default: null },
    methodOptions: { type: Array, default: () => [] },
    center: { type: Object, required: true },
});

const statusOptions = [
    { value: 'draft', label: 'Bản nháp' },
    { value: 'pending', label: 'Chờ duyệt' },
    { value: 'approved', label: 'Đã duyệt' },
    { value: 'rejected', label: 'Bị từ chối' },
    { value: 'cancelled', label: 'Đã hủy hóa đơn' },
];
const kindOptions = [
    { value: 'all', label: 'Tất cả khoản thu' },
    { value: 'renewal', label: 'Chỉ khoản thu tái tục' },
];
const money = (value) => formatNumber(value, 0);
const exportUrl = computed(() => route('tuition.history.export') + currentUrl().search);

const detail = ref(null);
const printing = ref(null);

// In phiếu: chỉ hiện mẫu in (lớp .printing-receipt trên body chỉ gắn trong lúc in).
function print(row) {
    printing.value = row;
    nextTick(() => {
        document.body.classList.add('printing-receipt');
        window.print();
    });
}
const afterPrint = () => document.body.classList.remove('printing-receipt');
const onKey = (event) => {
    if (event.key === 'Escape' && detail.value) detail.value = null;
};
onMounted(() => {
    window.addEventListener('afterprint', afterPrint);
    window.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    window.removeEventListener('afterprint', afterPrint);
    window.removeEventListener('keydown', onKey);
    afterPrint();
});
</script>

<template>
    <UiPageHeader title="Lịch sử thu học phí" :description="student ? `Học viên: ${student.name} • Mã HV: ${student.code}` : 'Tra cứu phiếu thu đã lập, trạng thái duyệt, đối soát và in phiếu thu.'">
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="exportUrl" native>Xuất Excel</UiButton>
            <!-- Nút chính "Lập phiếu thu" đã ở thanh tab Học phí → nút này là nút phụ. -->
            <UiButton v-if="can('tuition.create')" variant="secondary" icon="add" :href="route('tuition.receipts.create', student ? { student_id: student.id } : {})">Tải lên biên lai mới</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :action="route('tuition.history')" search="search" placeholder="Mã phiếu, số HĐ, mã GD, tên / mã học viên..." class="mb-md">
        <input v-if="filters.student_id" type="hidden" name="student_id" :value="filters.student_id" />
        <UiSelect name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :value="filters.status" :options="statusOptions" />
        <UiSelect name="method" label="Hình thức" placeholder="Tất cả hình thức" :value="filters.method" :options="methodOptions" />
        <UiDateRange label="Ngày thu" :from-value="filters.from" :to-value="filters.to" />
        <UiSelect name="kind" label="Khoản thu" :value="filters.kind" :options="kindOptions" />
    </UiFilterBar>

    <UiAlert v-if="filters.kind === 'renewal'" type="info" class="mb-md">Danh sách chỉ hiển thị các khoản thu tái tục — khoản phí đăng ký ban đầu xem tại hồ sơ CRM của học viên.</UiAlert>

    <div id="receipt-history">
        <UiDataTable min-width="960px">
            <table>
                <thead>
                    <tr>
                        <th>Mã phiếu · Số HĐĐT</th>
                        <th>Học viên</th>
                        <th>Khoản thu</th>
                        <th class="text-right">Số tiền</th>
                        <th>Hình thức</th>
                        <th>Trạng thái · Đối soát</th>
                        <th>Ngày thu</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rc in receipts.data" :key="rc.id">
                        <td class="whitespace-nowrap">
                            <UiCode :value="rc.receipt_number" class="font-code text-code text-primary" />
                            <div class="font-code text-caption text-on-surface-variant">{{ rc.invoice_number ?? '—' }}</div>
                        </td>
                        <td>
                            <Link :href="route('tuition.history', rc.student_id ? { student_id: rc.student_id } : {})" class="font-body-medium hover:text-primary">{{ rc.student_name ?? '—' }}</Link>
                            <div class="font-code text-caption text-on-surface-variant"><UiCode :value="rc.student_code" /></div>
                        </td>
                        <td>
                            {{ rc.row_fee_label }}
                            <div v-if="rc.surcharge > 0" class="font-caption text-caption text-on-surface-variant">+ Phụ thu {{ money(rc.surcharge) }} đ</div>
                        </td>
                        <td><UiMoney :value="rc.amount" /></td>
                        <td class="whitespace-nowrap">
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">{{ rc.method_icon }}</span>{{ rc.method }}</span>
                        </td>
                        <td class="whitespace-nowrap">
                            <UiBadge :color="rc.status_color">{{ rc.status_label }}</UiBadge>
                            <div v-if="rc.status === 'approved' && rc.invoice_number" class="font-caption text-caption text-tertiary">Chính thức</div>
                            <div v-else-if="rc.status === 'cancelled'" class="font-caption text-caption text-error">Đã hủy HĐ</div>
                            <div v-if="rc.deposit_state === 'pending'"><UiBadge color="warning" :dot="false" title="Tiền mặt phải nộp về TK công ty trước 19:00 cùng ngày">Chưa nộp về TK</UiBadge></div>
                            <div v-else-if="rc.deposit_state === 'late'"><UiBadge color="error" :dot="false" :title="'Nộp lúc ' + rc.deposited_at">Nộp trễ sau 19h</UiBadge></div>
                        </td>
                        <td class="whitespace-nowrap font-code text-code">{{ rc.payment_date_short ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right">
                            <UiButton size="sm" variant="ghost" icon="visibility" title="Xem chi tiết" aria-label="Xem chi tiết" @click="detail = rc" />
                            <UiButton size="sm" variant="ghost" icon="print" title="In phiếu thu" aria-label="In phiếu thu" @click="print(rc)" />
                            <UiForm v-if="rc.can_confirm_deposit" :action="route('tuition.receipts.confirm-deposit', rc.id)" method="post" back class="inline" confirm="Xác nhận tiền mặt của phiếu này đã nộp về tài khoản công ty?" confirm-label="Xác nhận đã nộp">
                                <UiButton type="submit" size="sm" variant="ghost" icon="savings" title="Xác nhận đã nộp về TK công ty" aria-label="Xác nhận đã nộp về TK công ty" />
                            </UiForm>
                            <UiButton v-if="rc.can_edit" size="sm" variant="ghost" icon="edit" :href="route('tuition.receipts.edit', rc.id)" modal="4xl" title="Sửa phiếu nháp / bị trả về rồi gửi duyệt lại" aria-label="Sửa phiếu" />
                        </td>
                    </tr>
                    <tr v-if="!receipts.data.length">
                        <td colspan="8"><UiEmptyState icon="receipt_long" title="Chưa có phiếu thu phù hợp" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer>
                <UiPagination :paginator="receipts" unit="phiếu thu" />
            </template>
        </UiDataTable>
    </div>

    <!-- Chi tiết phiếu thu (ngăn bên phải) -->
    <!-- Khung ngăn luôn có trong trang (v-show) như bản Blade; nội dung theo phiếu đang chọn. -->
    <div v-show="detail" class="fixed inset-0 z-50 flex justify-end print:hidden" role="dialog" aria-modal="true" aria-label="Chi tiết phiếu thu">
        <div class="absolute inset-0 bg-on-surface/40" @click="detail = null"></div>
        <aside class="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-surface-container-lowest shadow-level-3">
            <header class="flex items-center justify-between border-b border-surface-container p-md">
                <h3 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary" aria-hidden="true">receipt_long</span>Chi tiết Phiếu thu</h3>
                <UiButton variant="ghost" icon="close" aria-label="Đóng" @click="detail = null" />
            </header>
            <div v-if="detail" class="space-y-md p-md">
                <dl class="grid grid-cols-2 gap-sm font-body-small text-body-small">
                    <dt class="text-on-surface-variant">Mã phiếu</dt>
                    <dd class="font-code text-primary">{{ detail.receipt_number }}</dd>
                    <dt class="text-on-surface-variant">Số HĐĐT</dt>
                    <dd class="font-code">{{ detail.invoice_number || '—' }}</dd>
                    <dt class="text-on-surface-variant">Học viên</dt>
                    <dd>{{ (detail.student_name || '—') + (detail.student_code ? ' (' + detail.student_code + ')' : '') }}</dd>
                    <dt class="text-on-surface-variant">Khoản thu</dt>
                    <dd>{{ detail.fee_label }}</dd>
                    <dt class="text-on-surface-variant">Số tiền</dt>
                    <dd class="font-code">{{ formatMoney(detail.amount) }}</dd>
                    <template v-if="detail.surcharge > 0">
                        <dt class="text-on-surface-variant">Phụ thu</dt>
                        <dd>{{ formatMoney(detail.surcharge) + ' — ' + (detail.surcharge_reason || '') }}</dd>
                    </template>
                    <dt class="text-on-surface-variant">Hình thức</dt>
                    <dd>{{ detail.method + (detail.transaction_code ? ' · ' + detail.transaction_code : '') }}</dd>
                    <dt class="text-on-surface-variant">Ngày thu</dt>
                    <dd>{{ detail.payment_date || '—' }}</dd>
                    <dt class="text-on-surface-variant">Ngày lập</dt>
                    <dd>{{ detail.created_at }}</dd>
                    <dt class="text-on-surface-variant">Người lập</dt>
                    <dd>{{ detail.creator_name || '—' }}</dd>
                    <dt class="text-on-surface-variant">Người duyệt</dt>
                    <dd>{{ detail.approver_name || '—' }}</dd>
                    <dt class="text-on-surface-variant">Trạng thái</dt>
                    <dd>{{ detail.status_label }}</dd>
                    <dt class="text-on-surface-variant">Ghi chú</dt>
                    <dd>{{ detail.notes || '—' }}</dd>
                    <template v-if="detail.rejection_reason">
                        <dt class="text-error">Lý do từ chối</dt>
                        <dd class="text-error">{{ detail.rejection_reason }}</dd>
                    </template>
                </dl>
                <div v-if="can('tuition.approve') && detail.status === 'pending'" class="flex gap-sm">
                    <UiButton :href="detail.approve_url" icon="check" class="flex-1">Phê duyệt phiếu</UiButton>
                    <UiButton variant="secondary" :href="detail.approve_url" icon="undo" class="flex-1">Yêu cầu chỉnh sửa</UiButton>
                </div>
                <section class="space-y-sm">
                    <h4 class="flex items-center gap-xs font-label text-label uppercase text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">image</span>Minh chứng</h4>
                    <a v-if="detail.proof && !detail.proof_is_pdf" :href="detail.proof" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-outline-variant">
                        <img :src="detail.proof" alt="Minh chứng thanh toán" class="max-h-72 w-full object-contain" />
                        <span class="block bg-surface-container-low p-xs text-center font-caption text-caption text-primary">Phóng to</span>
                    </a>
                    <a v-if="detail.proof && detail.proof_is_pdf" :href="detail.proof" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>Mở file PDF minh chứng</a>
                    <p v-if="!detail.proof" class="font-body-small text-body-small text-on-surface-variant">Phiếu không có file minh chứng.</p>
                </section>
            </div>
        </aside>
    </div>

    <!-- Mẫu in phiếu thu (chỉ hiện khi in) -->
    <div id="printableReceipt" class="hidden print:block">
        <div v-if="printing" class="space-y-6 p-8 text-on-surface">
            <div class="flex items-start justify-between border-b border-surface-container-highest pb-4">
                <div>
                    <div class="text-xs font-black uppercase tracking-wider">{{ center.name }}</div>
                    <div class="text-xs text-on-surface-subtle">{{ printing.branch_name || '' }}<template v-if="center.phone"> · Hotline: {{ center.phone }}</template></div>
                    <div v-if="center.extra" class="text-xs text-on-surface-subtle">{{ center.extra }}</div>
                </div>
                <div class="text-right font-mono text-xs">
                    <div v-if="printing.template_code">Mẫu số: {{ printing.template_code }}</div>
                    <div v-if="printing.series">Ký hiệu: {{ printing.series }}</div>
                    <div>Số HĐĐT: {{ printing.invoice_number || 'Chưa cấp' }}</div>
                </div>
            </div>
            <div class="text-center">
                <h2 class="text-xl font-black uppercase">Phiếu thu học phí</h2>
                <p class="text-xs">Số phiếu: <strong>{{ printing.receipt_number }}</strong> · Ngày thu: {{ printing.payment_date || printing.created_at }}</p>
                <p v-if="printing.status !== 'approved'" class="mt-1 text-xs font-bold uppercase text-error">{{ 'Phiếu ' + printing.status_label.toLowerCase() + ' — không có giá trị thanh toán' }}</p>
            </div>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between"><span>Người nộp tiền:</span><strong>{{ printing.payer_name || '—' }}</strong></div>
                <div class="flex justify-between"><span>Học viên:</span><strong>{{ (printing.student_name || '—') + ' (' + (printing.student_code || '') + ')' }}</strong></div>
                <div class="flex justify-between"><span>Số điện thoại:</span><span>{{ printing.student_phone || '—' }}</span></div>
                <div class="flex justify-between"><span>Khoản thu:</span><span>{{ printing.fee_label }}</span></div>
                <div class="flex justify-between"><span>Lớp học:</span><span>{{ printing.class_name || '—' }}</span></div>
                <div class="flex justify-between"><span>Hình thức:</span><span>{{ printing.method }}</span></div>
                <div class="flex justify-between border-t pt-2"><strong>Số tiền thực thu:</strong><strong>{{ formatMoney(printing.amount) }}</strong></div>
                <div class="flex justify-between"><span>Nội dung:</span><span>{{ printing.notes || '' }}</span></div>
            </div>
            <div class="grid grid-cols-3 gap-4 pt-4 text-center text-xs">
                <div class="space-y-12"><div class="font-bold">Người nộp tiền</div><div class="italic text-on-surface-subtle">(Ký &amp; ghi rõ họ tên)</div></div>
                <div class="space-y-12"><div class="font-bold">Người lập phiếu</div><div>{{ printing.creator_name || '' }}</div></div>
                <div class="space-y-12"><div class="font-bold">Kế toán / Thủ quỹ</div><div>{{ printing.approver_name || '' }}</div></div>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    body.printing-receipt * {
        visibility: hidden;
    }
    body.printing-receipt #printableReceipt,
    body.printing-receipt #printableReceipt * {
        visibility: visible;
    }
    body.printing-receipt #printableReceipt {
        position: absolute;
        inset: 0;
    }
}
</style>
