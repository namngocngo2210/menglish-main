<script setup>
/**
 * Cấu hình dải số hóa đơn theo chi nhánh (mockup cauhinhhoadon-ui-mockup).
 * "Thêm cấu hình mới" / nút Sửa mở hộp thoại ngay trên trang; ?edit=<id> hoặc ?new=1 mở sẵn hộp thoại.
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Cấu hình dải số hóa đơn' } });

const props = defineProps({
    ranges: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    editingId: { type: Number, default: null },
    openNew: { type: Boolean, default: false },
    canManageDefault: { type: Boolean, default: false },
});

const formOpen = ref(props.editingId !== null || props.openNew);
const editId = ref(props.editingId);
const formKey = ref(0);
const editing = computed(() => (editId.value ? props.ranges.find((r) => r.id === editId.value) ?? null : null));
const historyRange = ref(null);

function openCreate() {
    editId.value = null;
    newKind.value = 'electronic';
    formKey.value++;
    formOpen.value = true;
}
function openEdit(range) {
    editId.value = range.id;
    formKey.value++;
    formOpen.value = true;
}
const branchLabel = (range) => range.branch_name ?? 'Dải mặc định (dùng chung)';
const kindOptions = [
    { value: 'electronic', label: 'Hóa đơn điện tử — cấp số khi duyệt phiếu' },
    { value: 'paper', label: 'Hóa đơn giấy (tiền mặt) — cấp số khi lập phiếu' },
];
const newKind = ref('electronic');
const currentHint = computed(() =>
    editing.value?.max_issued
        ? `Đã cấp tới ${editing.value.max_issued} — chỉ được đặt từ ${editing.value.max_issued + 1} trở lên.`
        : 'Chưa cấp số nào trong dải này.',
);
</script>

<template>
    <UiPageHeader title="Cấu hình dải số hóa đơn" description="Quản lý dải số hóa đơn điện tử và hóa đơn giấy (thu tiền mặt) cho từng chi nhánh. Số đã cấp không bao giờ được cấp lại.">
        <template #actions>
            <UiButton variant="secondary" icon="account_balance" :href="route('system-config.bank-accounts')">Tài khoản ngân hàng</UiButton>
            <UiButton v-if="can('invoice_range.manage')" icon="add" @click="openCreate">Thêm cấu hình mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
        <div class="space-y-md xl:col-span-3">
            <UiDataTable min-width="760px">
                <table>
                    <thead>
                        <tr>
                            <th>Chi nhánh</th>
                            <th>Loại</th>
                            <th>Ký hiệu / Mẫu số</th>
                            <th>Dải số (đầu – cuối)</th>
                            <th>Số hiện tại</th>
                            <th>Số còn lại</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="range in ranges" :key="range.id" :class="{ 'opacity-60': !range.is_active }">
                            <td>
                                <div class="font-body-medium text-body-medium">{{ branchLabel(range) }}</div>
                                <div v-if="!range.branch_id" class="font-caption text-caption text-on-surface-variant">Dùng khi chi nhánh chưa có dải riêng / dải riêng đã hết</div>
                            </td>
                            <td>
                                <UiBadge :color="range.kind === 'paper' ? 'warning' : 'info'" :dot="false">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ range.kind === 'paper' ? 'payments' : 'receipt_long' }}</span>{{ range.kind_label }}
                                </UiBadge>
                            </td>
                            <td>
                                <div class="font-code text-code">{{ range.series_code }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">Mẫu {{ range.template_code }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code">{{ range.range_label }}</td>
                            <td class="font-code text-code">
                                {{ range.current_label }}
                                <div v-if="range.max_issued_label" class="font-caption text-caption text-on-surface-variant">Đã cấp tới {{ range.max_issued_label }}</div>
                            </td>
                            <td>
                                <span v-if="range.remaining === null" class="text-on-surface-variant">Không giới hạn</span>
                                <UiBadge v-else-if="range.remaining === 0" color="error">Hết số</UiBadge>
                                <span v-else-if="range.low" class="inline-flex items-center gap-xs font-body-medium text-error">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">warning</span>{{ range.remaining }}
                                    <span class="font-caption text-caption">Sắp hết số</span>
                                </span>
                                <span v-else class="font-code text-code">{{ range.remaining_label }}</span>
                            </td>
                            <td>
                                <UiBadge v-if="range.is_active" color="success" pill>Đang hiệu lực</UiBadge>
                                <UiBadge v-else color="neutral" pill>Đã ngừng dùng</UiBadge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-xs">
                                    <UiButton variant="ghost" size="sm" icon="history" title="Số hóa đơn đã cấp gần đây" aria-label="Lịch sử cấp số" @click="historyRange = range" />
                                    <template v-if="range.can_manage">
                                        <UiButton variant="ghost" size="sm" icon="edit" title="Sửa dải số" aria-label="Sửa dải số" @click="openEdit(range)" />
                                        <UiForm :action="route('tuition.config.ranges.toggle', range.id)" method="post" class="inline">
                                            <UiButton
                                                type="submit"
                                                :variant="range.is_active ? 'danger-text' : 'ghost'"
                                                size="sm"
                                                :icon="range.is_active ? 'block' : 'restart_alt'"
                                                :title="range.is_active ? 'Ngừng dùng dải số' : 'Dùng lại dải số'"
                                                :aria-label="range.is_active ? 'Ngừng dùng dải số' : 'Dùng lại dải số'"
                                            />
                                        </UiForm>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!ranges.length">
                            <td colspan="8">
                                <UiEmptyState icon="receipt_long" title="Chưa có dải số hóa đơn" description="Hệ thống sẽ tự tạo dải mặc định C26MEN khi duyệt phiếu đầu tiên. Nên cấu hình dải riêng cho từng chi nhánh." />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <UiAlert type="info" title="Chính sách cấp số hóa đơn">
                <ul class="list-disc space-y-xs pl-md">
                    <li><strong>Hóa đơn điện tử:</strong> khi duyệt phiếu thu, hệ thống lấy số từ dải đang hiệu lực của <strong>chi nhánh ghi nhận học phí</strong>; chi nhánh chưa có dải riêng hoặc dải đã hết thì lấy từ <strong>dải mặc định</strong>.</li>
                    <li><strong>Hóa đơn giấy (tiền mặt):</strong> mỗi chi nhánh một dải theo cuốn hóa đơn giấy. Khi lập phiếu tiền mặt, hệ thống cấp số kế tiếp của chi nhánh; Học vụ ghi đúng nội dung thu của phiếu lên tờ hóa đơn giấy mang số đó và tải ảnh lên phiếu (bắt buộc). Ghi sai thì tạo yêu cầu <strong>Hủy hóa đơn</strong> số đó, phiếu mới nhận số kế tiếp. Chi nhánh chưa có dải giấy thì Học vụ nhập tay số hóa đơn giấy như trước.</li>
                    <li>Dải số không được chồng lấn dải khác cùng ký hiệu. "Số hiện tại" là số kế tiếp sẽ cấp và không được lùi về số đã cấp.</li>
                    <li>Hóa đơn bị hủy vẫn giữ số (không cấp lại cho phiếu khác).</li>
                    <li>Mọi thay đổi dải số (thêm, sửa, ngừng / dùng lại) được thông báo trong hệ thống tới Kế toán và Quản lý cơ sở của chi nhánh liên quan.</li>
                </ul>
            </UiAlert>
        </div>
    </div>

    <UiModal :show="!!historyRange" :title="historyRange ? 'Số đã cấp gần đây — ' + historyRange.series_code : ''" max-width="md" @close="historyRange = null">
        <div v-if="historyRange" class="space-y-xs text-left">
            <div v-for="invoice in historyRange.recent" :key="invoice.invoice_number" class="flex items-center justify-between border-b border-surface-container py-xs font-body-small text-body-small">
                <span class="font-code">{{ invoice.invoice_number }}</span>
                <span class="text-on-surface-variant">{{ invoice.label }}</span>
            </div>
            <p v-if="!historyRange.recent.length" class="text-on-surface-variant">Dải này chưa cấp số nào.</p>
        </div>
    </UiModal>

    <!-- Form thêm / sửa dải số -->
    <UiModal
        v-if="can('invoice_range.manage')"
        :show="formOpen"
        :title="editing ? `Sửa dải số ${editing.series_code} · ${branchLabel(editing)}` : 'Thêm cấu hình mới'"
        max-width="lg"
        @close="formOpen = false"
    >
        <UiForm v-if="editing" id="range-form" :key="'edit-' + formKey" :action="route('tuition.config.update')" method="post" class="space-y-md" @success="formOpen = false">
            <input type="hidden" name="config_id" :value="editing.id" />
            <div class="grid grid-cols-2 gap-sm">
                <UiInput name="template_code" label="Mẫu số" :value="editing.template_code" required />
                <UiInput name="series_code" label="Ký hiệu" :value="editing.series_code" required />
                <UiInput name="start_number" type="number" min="1" label="Số bắt đầu" :value="editing.start_number" required />
                <UiInput name="end_number" type="number" min="1" label="Số kết thúc" :value="editing.end_number" hint="Để trống = không giới hạn" />
            </div>
            <UiInput name="current_number" type="number" min="1" label="Số hiện tại (số kế tiếp sẽ cấp)" :value="editing.current_number" required :hint="currentHint" />
        </UiForm>
        <UiForm v-else id="range-form" :key="'new-' + formKey" :action="route('tuition.config.ranges.store')" method="post" class="space-y-md" @success="formOpen = false">
            <UiSelect v-model="newKind" name="kind" label="Loại hóa đơn" :options="kindOptions" required />
            <UiSelect v-if="canManageDefault && newKind === 'electronic'" name="branch_id" label="Chọn chi nhánh" placeholder="Dải mặc định (dùng chung)" :options="branches" />
            <UiSelect v-else name="branch_id" label="Chọn chi nhánh" :options="branches" required :hint="newKind === 'paper' ? 'Hóa đơn giấy luôn theo từng chi nhánh (mỗi chi nhánh một cuốn).' : null" />
            <div class="grid grid-cols-2 gap-sm">
                <UiInput name="template_code" label="Mẫu số" value="1/001" required />
                <UiInput name="series_code" label="Ký hiệu" value="C26MEN" required />
                <UiInput name="start_number" type="number" min="1" label="Số bắt đầu" placeholder="Ví dụ: 1" required />
                <UiInput name="end_number" type="number" min="1" label="Số kết thúc" placeholder="Ví dụ: 1000" required />
            </div>
            <UiAlert type="info">Dải số này phải duy nhất trên hệ thống và không được chồng lấn với các dải số đã tồn tại cùng ký hiệu. Hóa đơn giấy nên dùng ký hiệu riêng (VD: C26HDG) và số đầu – cuối đúng cuốn hóa đơn đang dùng.</UiAlert>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="formOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="range-form" icon="save">{{ editing ? 'Lưu thay đổi' : 'Lưu cấu hình' }}</UiButton>
        </template>
    </UiModal>
</template>
