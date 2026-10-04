<script setup>
/**
 * Nội dung form Lập / Sửa phiếu thu — dùng chung trang đầy đủ và modal 4xl (Tuition/ReceiptForm).
 * `form`: trạng thái từ useReceiptForm (tính tiền, nội dung CK, VietQR, minh chứng). `prefix`: tiền tố id ("modal-" trong modal).
 * Nằm trong <UiForm>: lỗi validate đọc từ form (useFormContext).
 */
import { computed, ref } from 'vue';
import { Link, useFormContext } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/format';
import { promotionLabel } from '@/lib/promotion';

const props = defineProps({
    form: { type: Object, required: true },
    prefix: { type: String, default: '' },
    recentRejection: { type: Object, default: null },
    hasDefaultBank: { type: Boolean, default: false },
    canRequestCancel: { type: Boolean, default: false },
});

const ctx = useFormContext();
const errorList = computed(() => Object.values(ctx?.errors ?? {}).flat());
const s = computed(() => props.form.state);
const money = (value) => formatMoney(value || 0);
const fileInput = ref(null);

const tuitionOptions = computed(() =>
    props.form.tuitions.map((t) => ({ value: String(t.id), label: t.student_name + ' (' + t.student_code_short + ') - ' + t.class_name + ' · Nợ: ' + money(t.debt_amount) })),
);
const studentOptions = computed(() => props.form.students.map((st) => ({ value: String(st.id), label: st.name + ' (' + st.code_short + ') · ' + st.class_name + ' (' + st.branch_name + ')' })));
const promotionOptions = computed(() => props.form.availablePromotions.map((p) => ({ value: String(p.id), label: promotionLabel(p) })));
const itemOptions = computed(() => props.form.merchandiseItems.map((m) => ({ value: String(m.id), label: `[${m.category_label}] ${m.name} · ${money(m.price)}` })));
const itemPick = ref('');
function pickItem(value) {
    props.form.addItem(value);
    itemPick.value = '';
}
const methodClass = (method) => (s.value.paymentMethod === method ? 'border-primary-container bg-primary-container/10 ring-1 ring-primary-container' : 'border-surface-container-highest hover:bg-surface-container-low');
</script>

<template>
    <input v-if="form.editing" type="hidden" name="remove_proof" :value="s.proofRemoved ? 1 : 0" />

    <!-- Thông báo -->
    <UiAlert v-if="errorList.length" type="error" title="Vui lòng kiểm tra các thông tin sau:">
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(err, i) in errorList" :key="i">{{ err }}</li>
        </ul>
    </UiAlert>

    <UiAlert v-if="recentRejection" type="error" :title="`Lý do từ chối gần nhất (Phiếu: ${recentRejection.receipt_number})`">
        <p class="leading-relaxed">{{ recentRejection.rejection_reason }}</p>
        <Link v-if="recentRejection.can_edit" :href="route('tuition.receipts.edit', recentRejection.id)" class="mt-1.5 inline-flex items-center gap-1 text-xs font-bold text-error underline">
            <span class="material-symbols-outlined text-sm">edit</span> Sửa phiếu bị trả về &amp; gửi duyệt lại
        </Link>
    </UiAlert>

    <UiAlert v-show="s.currentTuition?.class_changed && !s.skipTuition" type="info" title="Học viên vừa chuyển lớp mới">
        Khoản học phí này thuộc lớp <strong>{{ s.currentTuition?.class_name }}</strong>, học viên đang học lớp <strong>{{ s.currentTuition?.current_class_name }}</strong>. Hệ thống ghi nhận thay đổi lộ trình — chỉ cần thu phần còn thiếu của khoản học phí (công nợ hiện tại).
    </UiAlert>

    <!-- Khối 1: Chọn Học viên & Hồ sơ Học phí -->
    <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
        <div class="border-b border-surface-container-highest bg-surface-container-low/50 p-4 md:p-5">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <UiField label="1. Chọn Hồ sơ Học phí đến hạn" name="student_tuition_id" :for="prefix + 'receipt_student_tuition_id'" required>
                    <UiSelect
                        v-model="s.selectedTuitionId"
                        name="student_tuition_id"
                        :id="prefix + 'receipt_student_tuition_id'"
                        :options="tuitionOptions"
                        :disabled="!!form.editing"
                        class="font-bold"
                        placeholder="-- Thu riêng phụ thu (Không gắn hồ sơ học phí) --"
                        @change="form.onTuitionChange()"
                    />
                </UiField>

                <UiSelect v-model="s.selectedStudentId" name="student_id" :id="prefix + 'receipt_student_id'" label="Học viên được ghi nhận" required :options="studentOptions" :disabled="!!form.editing" @change="form.onStudentChange()" />
            </div>
        </div>

        <!-- Thẻ tóm tắt học viên -->
        <div class="border-b border-surface-container-highest bg-surface-container-lowest p-5">
            <div class="flex flex-wrap items-center gap-4 sm:flex-nowrap">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-container/10 text-primary shadow-xs">
                    <span class="material-symbols-outlined text-3xl">person</span>
                </div>
                <div class="grid flex-grow grid-cols-2 gap-4 text-xs md:grid-cols-4">
                    <div>
                        <span class="block text-xs font-semibold uppercase text-on-surface-subtle">Học viên</span>
                        <span class="text-sm font-bold text-on-surface">{{ s.currentStudent?.name || '—' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-semibold uppercase text-on-surface-subtle">Mã học viên</span>
                        <span class="font-code text-xs font-bold text-primary" :title="s.currentStudent?.code">{{ s.currentStudent?.code_short || '—' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-semibold uppercase text-on-surface-subtle">Lớp học hiện tại</span>
                        <span class="text-xs font-medium text-on-surface">{{ s.currentStudent?.class_name || '—' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-semibold uppercase text-on-surface-subtle">Trạng thái</span>
                        <span class="inline-flex items-center rounded border border-secondary/30 bg-secondary/10 px-2 py-0.5 text-xs font-bold text-secondary">{{ s.currentStudent?.status_label || '—' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thanh tùy chọn: Khoản học phí đến hạn -->
        <div class="flex flex-col items-start justify-between gap-3 border-b border-surface-container-highest/80 bg-surface-container-low/70 p-4 px-5 md:flex-row md:items-center">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                    <span class="material-symbols-outlined text-base text-primary">event_available</span>
                    <span>Khoản học phí đến hạn:</span>
                    <span class="rounded bg-primary-container/10 px-2 py-0.5 text-xs font-bold text-primary">Tùy chọn</span>
                </div>
                <div v-if="!s.skipTuition && s.currentTuition" class="flex items-center gap-1.5 rounded-lg border border-primary-container/60 bg-surface-container-lowest px-3 py-1 text-xs text-on-surface shadow-2xs">
                    <span class="material-symbols-outlined text-sm text-tertiary">check_circle</span>
                    <span>Đã chọn: <strong>{{ 'Học phí đợt ' + (s.currentTuition.receipt_count + 1) + ' - ' + s.currentTuition.class_name }}</strong></span>
                </div>
                <div v-else class="flex items-center gap-1.5 rounded-lg border border-warning/30 bg-warning-container px-3 py-1 text-xs text-on-warning-container">
                    <span class="material-symbols-outlined text-sm text-warning">info</span>
                    <span>Đang bỏ qua khoản học phí (Lập phiếu chỉ thu riêng Phụ thu)</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <UiButton v-if="!s.skipTuition && s.currentTuition" variant="secondary" size="sm" icon="close" @click="form.toggleSkipTuition(true)">Bỏ qua khoản học phí (để chỉ thu phụ thu)</UiButton>
                <UiButton v-if="s.skipTuition && s.currentTuition" variant="secondary" size="sm" icon="add" class="border-primary-container text-primary" @click="form.toggleSkipTuition(false)">Bật lại khoản học phí</UiButton>
            </div>
        </div>

        <div class="flex items-center gap-1.5 border-b border-surface-container-highest bg-surface-container-lowest px-5 py-2 text-xs italic text-on-surface-variant">
            <span class="material-symbols-outlined text-sm text-primary">lightbulb</span>
            <span>Có thể bỏ qua khoản học phí để lập phiếu chỉ thu riêng phụ thu. Khi bỏ qua, khối thông tin số buổi và bảng kê học phí bên dưới sẽ tự động ẩn.</span>
        </div>

        <!-- Khối số buổi & bảng kê học phí (chỉ khi KHÔNG bỏ qua) -->
        <div v-show="!s.skipTuition && s.currentTuition" class="space-y-0">
            <div v-show="s.currentTuition?.total_sessions" class="grid grid-cols-2 divide-x divide-surface-container-highest border-b border-surface-container-highest text-center text-xs md:grid-cols-4">
                <div class="p-4">
                    <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Tổng số buổi</span>
                    <span class="text-lg font-bold text-on-surface">{{ s.currentTuition?.total_sessions ?? '—' }}</span>
                </div>
                <div class="p-4">
                    <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Đã học</span>
                    <span class="text-lg font-bold text-tertiary">{{ s.currentTuition?.attended_sessions ?? '—' }}</span>
                </div>
                <div class="p-4">
                    <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Số buổi còn tồn</span>
                    <span class="text-lg font-bold text-primary">{{ s.currentTuition?.remaining_sessions ?? '—' }}</span>
                </div>
                <div class="p-4">
                    <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Trạng thái học</span>
                    <span :class="['text-sm font-bold', s.currentTuition?.is_deferred ? 'text-secondary' : 'text-on-surface-variant']">{{ s.currentTuition?.is_deferred ? 'Đang bảo lưu' : s.currentStudent?.status_label || '—' }}</span>
                </div>
            </div>

            <div class="bg-surface-container-low/50 p-5">
                <h4 class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                    <span class="material-symbols-outlined text-base text-primary">receipt_long</span>
                    Bảng kê chi tiết khoản thu học phí
                </h4>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between border-b border-dashed border-surface-container-highest py-1.5">
                        <span class="text-on-surface-variant">{{ 'Học phí khóa / lớp ' + (s.currentTuition?.class_name || '—') }}</span>
                        <span class="font-code font-bold text-on-surface">{{ money(s.currentTuition?.total_amount) }}</span>
                    </div>
                    <div v-for="(item, idx) in s.currentTuition?.fee_items || []" :key="idx" class="flex justify-between border-b border-dashed border-surface-container-highest py-1.5">
                        <span class="text-on-surface-variant">{{ item.name }}</span>
                        <span class="font-code font-bold text-on-surface">{{ money(item.amount) }}</span>
                    </div>
                    <div v-show="s.currentTuition?.other_fees > 0 && !(s.currentTuition?.fee_items || []).length" class="flex justify-between border-b border-dashed border-surface-container-highest py-1.5">
                        <span class="text-on-surface-variant">Phí học liệu &amp; khoản thu khác</span>
                        <span class="font-code font-bold text-on-surface">{{ money(s.currentTuition?.other_fees) }}</span>
                    </div>
                    <div v-show="s.currentTuition?.discount_amount > 0" class="flex justify-between border-b border-dashed border-surface-container-highest py-1.5">
                        <span class="text-on-surface-variant">Ưu đãi trên hợp đồng</span>
                        <span class="font-code font-bold text-tertiary">{{ '-' + money(s.currentTuition?.discount_amount) }}</span>
                    </div>
                    <div class="flex justify-between border-b border-dashed border-surface-container-highest py-1.5">
                        <span class="text-on-surface-variant">Đã nộp / Còn nợ</span>
                        <span class="font-code font-bold text-on-surface">{{ money(s.currentTuition?.paid_amount) + ' / ' + money(s.currentTuition?.debt_amount) }}</span>
                    </div>
                </div>

                <!-- Giảm trừ & tổng học phí -->
                <div class="mt-4 flex flex-col items-start justify-between gap-4 border-t border-surface-container-highest pt-4 md:flex-row md:items-center">
                    <!-- Giảm trừ: ưu tiên chọn ưu đãi có sẵn; nhập tay là ca đặc biệt, phải ghi lý do. -->
                    <div class="grid w-full gap-3 md:w-96">
                        <input type="hidden" name="promotion_id" :value="form.selectedPromotion ? form.selectedPromotion.id : ''" />
                        <input type="hidden" name="discount_amount" :value="form.discountValue" />
                        <UiSelect
                            v-model="s.promotionId"
                            :id="prefix + 'promotion_id'"
                            label="Ưu đãi áp dụng"
                            :options="promotionOptions"
                            placeholder="-- Không theo ưu đãi (nhập tay) --"
                            :hint="form.selectedPromotion ? 'Tính trên số còn phải thu ' + money(form.tuitionSubtotal) + '.' : 'Ưu tiên chọn ưu đãi có sẵn. Ưu đãi trên hợp đồng đã trừ trong công nợ.'"
                        />
                        <UiInput v-if="form.selectedPromotion" :id="prefix + 'discount_amount'" label="Số tiền giảm trừ (VNĐ)" :model-value="form.discountValue" readonly suffix="VNĐ" class="font-code font-bold" />
                        <UiInput v-else v-model.number="s.discountAmount" type="number" :id="prefix + 'discount_amount'" label="Số tiền giảm trừ (VNĐ)" hint="Không vượt tổng trước giảm." suffix="VNĐ" min="0" :max="form.tuitionSubtotal" class="font-code font-bold" placeholder="0" />
                        <UiInput
                            v-if="form.selectedPromotion || form.needsDiscountReason"
                            v-model="s.discountReason"
                            name="discount_reason"
                            :id="prefix + 'discount_reason'"
                            :label="form.needsDiscountReason ? 'Lý do giảm (ca đặc biệt)' : 'Ghi chú ưu đãi'"
                            :required="form.needsDiscountReason"
                            :placeholder="form.needsDiscountReason ? 'VD: Quản lý duyệt giảm do học viên chuyển lớp muộn' : 'Tùy chọn'"
                        />
                    </div>
                    <div class="flex w-full flex-col items-end gap-1 text-xs md:w-auto">
                        <div class="flex items-baseline gap-4">
                            <span class="font-medium text-on-surface-variant">Tổng trước giảm:</span>
                            <span class="font-code font-bold text-on-surface">{{ money(form.tuitionSubtotal) }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <label :for="prefix + 'collectAmount'" class="font-medium text-on-surface-variant">Thu đợt này (để trống = thu hết):</label>
                            <input :id="prefix + 'collectAmount'" v-model="s.collectAmount" type="number" min="0" :max="form.tuitionSubtotal" class="h-9 w-36 rounded-xl border border-surface-container-highest px-2 text-right font-code text-xs font-bold" placeholder="Toàn bộ" />
                        </div>
                        <div class="flex items-baseline gap-4">
                            <span class="font-bold uppercase tracking-wider text-primary">TỔNG PHẢI THU (HỌC PHÍ):</span>
                            <span class="font-code text-base font-bold text-primary">{{ money(form.tuitionAmountAfterDiscount) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Khối 2: Phụ thu -->
    <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
        <div class="flex items-center justify-between border-b border-surface-container-highest/80 bg-surface-container-low/70 p-4 px-5">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-xl text-primary">add_shopping_cart</span>
                <h3 class="text-sm font-bold text-on-surface">Phụ thu (Phí phát sinh ngoài học phí)</h3>
                <span class="inline-flex items-center rounded border border-primary-container/30 bg-primary-container/10 px-2 py-0.5 text-xs font-bold text-primary">Tùy chọn độc lập - Chuẩn 11/09/2026</span>
            </div>
            <span class="text-xs italic text-on-surface-subtle">Mới cập nhật</span>
        </div>

        <div class="space-y-4 p-5">
            <!-- Hàng hóa trong danh mục: xuất kho chi nhánh khi phiếu được duyệt -->
            <div class="space-y-2">
                <UiField label="Sách / hàng hóa giao kèm phiếu" name="collected_items" :for="prefix + 'surcharge_item_pick'" hint="Chọn từ danh mục hàng hóa: giá theo danh mục, kho chi nhánh tự trừ khi phiếu được duyệt.">
                    <UiSelect :id="prefix + 'surcharge_item_pick'" :model-value="itemPick" :options="itemOptions" searchable :placeholder="`-- Thêm sách / hàng hóa (${form.merchandiseItems.length} mặt hàng) --`" @update:model-value="pickItem" />
                </UiField>
                <input type="hidden" name="collected_items" :value="form.collectedItemsJson" />
                <div v-if="s.surchargeLines.length" class="overflow-x-auto rounded-xl border border-surface-container-highest">
                    <table class="w-full min-w-[560px] text-xs">
                        <thead class="bg-surface-container-low text-on-surface-variant">
                            <tr>
                                <th class="px-3 py-2 text-left">Mặt hàng</th>
                                <th class="px-3 py-2 text-right">Đơn giá</th>
                                <th class="px-3 py-2 text-center">SL</th>
                                <th class="px-3 py-2 text-right">Tồn chi nhánh</th>
                                <th class="px-3 py-2 text-right">Thành tiền</th>
                                <th class="px-3 py-2"><span class="sr-only">Bỏ</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, idx) in s.surchargeLines" :key="line.id" class="border-t border-surface-container-highest">
                                <td class="px-3 py-2 font-semibold text-on-surface">{{ form.itemById(line.id)?.name }}</td>
                                <td class="px-3 py-2 text-right font-code">{{ money(form.itemById(line.id)?.price) }}</td>
                                <td class="px-3 py-2 text-center">
                                    <input v-model.number="line.quantity" type="number" min="1" max="1000" class="h-8 w-16 rounded-lg border border-surface-container-highest px-2 text-center font-code" :aria-label="`Số lượng ${form.itemById(line.id)?.name}`" />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <template v-if="form.stockOf(line.id) !== null">
                                        <span :class="['font-code font-bold', form.stockOf(line.id) < line.quantity ? 'text-error' : 'text-on-surface']">{{ form.stockOf(line.id) }}</span>
                                        <span v-if="form.stockOf(line.id) < line.quantity" class="block text-[11px] text-error">Không đủ hàng: vẫn thu được, hệ thống giao Admin nhập bù</span>
                                    </template>
                                    <span v-else class="text-on-surface-subtle">—</span>
                                </td>
                                <td class="px-3 py-2 text-right font-code font-bold text-primary">{{ money((form.itemById(line.id)?.price || 0) * (line.quantity || 0)) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <UiButton variant="danger-text" size="sm" icon="close" :aria-label="`Bỏ ${form.itemById(line.id)?.name}`" @click="form.removeItem(idx)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <input type="hidden" name="surcharge_amount" :value="form.surchargeTotal" />
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <UiField label="Phụ thu khác ngoài danh mục (VNĐ)" name="surcharge_amount" :for="prefix + 'surcharge_amount'">
                    <UiInput v-model.number="s.surchargeAmount" type="number" :id="prefix + 'surcharge_amount'" suffix="VNĐ" min="0" step="10000" class="font-code font-bold" placeholder="Nhập số tiền > 0..." />
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-on-surface-subtle">Gợi ý nhanh:</span>
                        <UiButton variant="secondary" size="sm" @click="form.setSurcharge(50000)">50.000đ</UiButton>
                        <UiButton variant="secondary" size="sm" @click="form.setSurcharge(100000)">100.000đ</UiButton>
                        <UiButton variant="secondary" size="sm" @click="form.setSurcharge(150000)">150.000đ</UiButton>
                        <UiButton variant="secondary" size="sm" @click="form.setSurcharge(200000)">200.000đ</UiButton>
                    </div>
                </UiField>

                <div class="flex flex-col gap-xs">
                    <label :for="prefix + 'surcharge_reason'" class="font-body-small text-body-small text-on-surface-variant">Lý do phụ thu khác <span v-show="s.surchargeAmount > 0" class="text-error">*</span></label>
                    <UiInput v-model="s.surchargeReason" name="surcharge_reason" :id="prefix + 'surcharge_reason'" placeholder="Ví dụ: Phụ thu giáo trình in ấn bổ sung, đồng phục, thẻ học viên..." />
                    <p class="text-xs italic text-on-surface-subtle">* Bắt buộc nhập lý do khi có phụ thu khác. Chỉ chọn sách / hàng hóa thì không cần.</p>
                </div>
            </div>

            <UiAlert type="info">Khoản phụ thu luôn hoạt động độc lập và không loại trừ lẫn nhau với học phí. Hệ thống cho phép: <strong>Học phí + Phụ thu</strong>, hoặc chỉ thu riêng <strong>Học phí</strong>, hoặc chỉ thu riêng <strong>Phụ thu</strong>.</UiAlert>
        </div>
    </div>

    <!-- Khối 3: Tổng thực thu -->
    <div class="rounded-2xl border-2 border-primary-container/50 bg-surface-container-lowest p-5 shadow-sm">
        <div class="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
            <div class="space-y-1">
                <span class="text-xs font-bold uppercase tracking-wider text-primary">TỔNG THỰC THU CỦA PHIẾU NÀY</span>
                <div class="flex flex-wrap items-center gap-3 text-xs text-on-surface-variant">
                    <span>Học phí cần thu: <strong class="font-code text-on-surface">{{ money(form.tuitionAmountAfterDiscount) }}</strong></span>
                    <span class="text-on-surface-subtle">+</span>
                    <span>Tiền phụ thu: <strong class="font-code text-primary">{{ '+' + money(form.surchargeTotal) }}</strong></span>
                </div>
                <div>
                    <span v-if="form.isValidReceipt" class="flex items-center gap-1 text-xs font-medium text-tertiary">
                        <span class="material-symbols-outlined text-sm text-tertiary">check_circle</span>
                        Hợp lệ: Đã có ít nhất 1 nguồn tiền (Chọn học phí HOẶC nhập phụ thu > 0) để Gửi duyệt.
                    </span>
                    <span v-else class="flex items-center gap-1 text-xs font-medium text-error">
                        <span class="material-symbols-outlined text-sm text-error">warning</span>
                        {{ form.needsDiscountReason && !s.discountReason.trim() ? 'Chưa hợp lệ: Nhập lý do giảm trừ (không theo ưu đãi có sẵn).' : 'Chưa hợp lệ: Cần chọn khoản học phí hoặc nhập số tiền phụ thu > 0.' }}
                    </span>
                </div>
            </div>
            <div class="flex w-full items-baseline justify-end gap-3 border-t border-surface-container-highest pt-3 md:w-auto md:border-t-0 md:pt-0">
                <span class="text-xs font-bold uppercase text-on-surface-variant">TỔNG THỰC THU:</span>
                <span class="font-code text-2xl font-bold text-primary md:text-3xl">{{ money(form.totalAmount) }}</span>
            </div>
        </div>
    </div>

    <input type="hidden" name="amount" :value="form.totalAmount" />
    <input type="hidden" name="tuition_amount" :value="form.tuitionAmountAfterDiscount" />

    <!-- Khối 4: Hình thức thu tiền & Thông tin bổ sung -->
    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h3 class="flex items-center gap-1.5 text-sm font-bold text-on-surface">
                    <span class="material-symbols-outlined text-base text-primary">payments</span>
                    Hình thức thu tiền
                </h3>
                <span class="text-xs italic text-on-surface-subtle">CM chọn phương thức</span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label :class="['relative flex cursor-pointer items-center justify-center rounded-xl border p-3.5 transition', methodClass('transfer')]">
                    <input v-model="s.paymentMethod" type="radio" name="payment_method" value="transfer" class="sr-only" />
                    <div class="flex flex-col items-center">
                        <span :class="['material-symbols-outlined mb-1', s.paymentMethod === 'transfer' ? 'text-primary' : 'text-on-surface-subtle']">account_balance</span>
                        <span :class="['text-xs font-bold', s.paymentMethod === 'transfer' ? 'text-primary' : 'text-on-surface-variant']">Chuyển khoản</span>
                    </div>
                </label>
                <label :class="['relative flex cursor-pointer items-center justify-center rounded-xl border p-3.5 transition', methodClass('cash')]">
                    <input v-model="s.paymentMethod" type="radio" name="payment_method" value="cash" class="sr-only" />
                    <div class="flex flex-col items-center">
                        <span :class="['material-symbols-outlined mb-1', s.paymentMethod === 'cash' ? 'text-primary' : 'text-on-surface-subtle']">payments</span>
                        <span :class="['text-xs font-bold', s.paymentMethod === 'cash' ? 'text-primary' : 'text-on-surface-variant']">Tiền mặt</span>
                    </div>
                </label>
            </div>

            <!-- Chuyển khoản -->
            <div v-show="s.paymentMethod === 'transfer'" class="space-y-3 pt-2">
                <UiAlert type="info" title="Trạng thái đối soát: Tạm thu">
                    Hệ thống tự động ghi nhận &amp; khớp giao dịch khi phụ huynh chuyển khoản đúng cú pháp (Nội dung CK: <strong>{{ form.transferMemo }}</strong>).
                </UiAlert>

                <div v-show="form.bank" class="space-y-3 rounded-xl border border-surface-container-highest bg-surface-container-low/50 p-3.5 text-xs">
                    <div class="flex items-center justify-between border-b border-surface-container-highest/80 pb-2">
                        <h5 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Tài khoản ngân hàng nhận học phí</h5>
                        <span class="text-xs italic text-on-surface-subtle">{{ form.bank?.scope }}</span>
                    </div>
                    <div class="flex flex-col items-center gap-4 sm:flex-row">
                        <div class="w-full flex-grow space-y-2">
                            <div class="flex justify-between border-b border-dashed border-surface-container-highest pb-1">
                                <span class="text-on-surface-variant">Ngân hàng</span>
                                <span class="font-bold text-on-surface">{{ form.bank?.bank_name }}</span>
                            </div>
                            <div class="flex justify-between border-b border-dashed border-surface-container-highest pb-1">
                                <span class="text-on-surface-variant">Số tài khoản</span>
                                <span class="font-code font-bold text-primary">{{ form.bank?.account_number }}</span>
                            </div>
                            <div class="flex justify-between border-b border-dashed border-surface-container-highest pb-1">
                                <span class="text-on-surface-variant">Chủ tài khoản</span>
                                <span class="font-bold uppercase text-on-surface">{{ form.bank?.account_holder }}</span>
                            </div>
                            <div class="flex items-center justify-between pt-0.5">
                                <span class="text-on-surface-variant">Nội dung CK</span>
                                <span class="rounded bg-secondary/10 px-2 py-0.5 font-code text-xs font-bold text-secondary">{{ form.transferMemo }}</span>
                            </div>
                            <input type="hidden" name="transfer_memo" :value="form.transferMemo" />
                        </div>
                        <!-- VietQR động -->
                        <div class="flex shrink-0 flex-col items-center gap-1">
                            <div class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-xl border-2 border-primary-container/20 bg-surface-container-lowest p-1 shadow-xs">
                                <img v-if="form.vietQrUrl" :src="form.vietQrUrl" alt="VietQR Thanh toán" class="h-full w-full object-contain" />
                            </div>
                            <span class="text-xs italic text-on-surface-subtle">Quét VietQR tự điền số tiền</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-lg bg-surface-container px-2.5 py-1.5 text-xs italic text-on-surface-variant">
                        <span class="material-symbols-outlined text-xs text-on-surface-subtle">lock</span>
                        <span>Tài khoản lấy theo hợp đồng / chi nhánh của học viên; CM không đổi được trên màn hình này.</span>
                    </div>
                </div>
                <UiAlert v-if="!hasDefaultBank" v-show="!form.bank" type="warning"><strong>Chưa cấu hình tài khoản ngân hàng</strong> đang hoạt động để nhận học phí. Mã VietQR sẽ không được tạo — vui lòng liên hệ Kế toán/Admin cấu hình tài khoản trước khi hướng dẫn phụ huynh chuyển khoản.</UiAlert>

                <UiInput v-model="s.transactionCode" name="transaction_code" :id="prefix + 'transaction_code'" label="Mã tham chiếu / Mã giao dịch ngân hàng (nếu có)" placeholder="Ví dụ: FT232981354789..." class="font-code" />
            </div>

            <!-- Tiền mặt -->
            <div v-show="s.paymentMethod === 'cash'" class="space-y-3 pt-2">
                <!-- Chi nhánh có dải hóa đơn giấy: hệ thống cấp số theo thứ tự -->
                <div v-if="form.paperMode" class="space-y-2 rounded-xl border-2 border-primary-container/40 bg-primary-container/5 p-3.5" data-testid="paper-invoice-number">
                    <template v-if="form.paperNumber">
                        <span class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant">{{ form.issuedPaperInvoice ? 'Số hóa đơn giấy đã cấp cho phiếu' : 'Số hóa đơn giấy hệ thống cấp' }}</span>
                        <span class="block font-code text-xl font-bold text-primary">{{ form.paperNumber }}</span>
                        <p class="text-xs text-on-surface-variant">
                            Dùng tờ hóa đơn giấy mang <strong>đúng số này</strong>, ghi <strong>đúng nội dung thu</strong> dưới đây lên hóa đơn, chụp ảnh và tải lên mục
                            <strong>Minh chứng</strong> (bắt buộc).
                            <template v-if="!form.issuedPaperInvoice"> Số được giữ cho phiếu khi bấm Lưu nháp hoặc Gửi duyệt.</template>
                        </p>
                        <dl class="space-y-1 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-2.5 text-xs" data-testid="paper-invoice-content">
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-on-surface-variant">Người nộp</dt>
                                <dd class="font-semibold text-on-surface">{{ s.payerName || '—' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-on-surface-variant">Nội dung thu</dt>
                                <dd class="space-y-0.5 text-on-surface">
                                    <div v-for="(line, i) in form.paperInvoiceContent" :key="i" class="flex justify-between gap-3">
                                        <span>{{ line.label }}</span><span class="font-code">{{ formatMoney(line.amount) }}</span>
                                    </div>
                                    <span v-if="!form.paperInvoiceContent.length">—</span>
                                </dd>
                            </div>
                            <div class="flex gap-2 border-t border-surface-container pt-1">
                                <dt class="w-24 shrink-0 text-on-surface-variant">Tổng tiền</dt>
                                <dd class="font-code font-bold text-on-surface">{{ formatMoney(form.totalAmount || 0) }}</dd>
                            </div>
                        </dl>
                        <p class="text-xs text-on-surface-variant">
                            Ghi sai số trên giấy? Không sửa số: tạo yêu cầu <strong>Hủy hóa đơn</strong> số đó, phiếu lập mới nhận số kế tiếp.
                            <Link v-if="form.issuedPaperInvoice && canRequestCancel" :href="route('tuition.invoices.cancellations', { cancel_invoice: form.issuedPaperInvoice })" class="font-bold text-error underline">Hủy số hóa đơn này</Link>
                        </p>
                        <input v-if="!form.issuedPaperInvoice" type="hidden" name="expected_paper_invoice_number" :value="form.paperNumber" />
                    </template>
                    <UiAlert v-else type="error" title="Dải hóa đơn giấy của chi nhánh đã hết số">Nhờ Kế toán thêm dải số mới ở Cấu hình dải số hóa đơn trước khi thu tiền mặt.</UiAlert>
                </div>
                <div v-else class="rounded-xl border border-surface-container-highest bg-surface-container-low p-3.5">
                    <UiInput name="paper_invoice_number" :id="prefix + 'paper_invoice_number'" label="Số hóa đơn giấy thu tiền mặt (bắt buộc khi gửi duyệt)" hint="Chi nhánh chưa cấu hình dải hóa đơn giấy: xuất hóa đơn giấy cho khách rồi ghi số vào đây." :value="form.editing?.paper_invoice_number" placeholder="Ví dụ: HĐG-0824/PTM-042..." class="font-code font-bold" />
                </div>
            </div>
        </div>

        <!-- Thông tin bổ sung -->
        <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
            <h3 class="flex items-center gap-1.5 text-sm font-bold text-on-surface">
                <span class="material-symbols-outlined text-base text-primary">description</span>
                Thông tin bổ sung
            </h3>
            <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/70 p-3.5">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-lg text-on-surface-variant">receipt</span>
                    <div>
                        <span class="block text-xs font-bold text-on-surface">Yêu cầu xuất hóa đơn đỏ (VAT)</span>
                        <span class="text-xs text-on-surface-subtle">Xuất theo thông tin doanh nghiệp/cá nhân</span>
                    </div>
                </div>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" name="is_vat_invoice" value="1" class="peer sr-only" :checked="!!form.editing?.is_vat_invoice" aria-label="Yêu cầu xuất hóa đơn đỏ (VAT)" />
                    <div class="peer h-6 w-11 rounded-full bg-surface-container-high after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-outline-variant after:bg-surface-container-lowest after:transition-all after:content-[''] peer-checked:bg-primary-container peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none"></div>
                </label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <UiInput v-model="s.payerName" name="payer_name" :id="prefix + 'payer_name'" label="Người nộp tiền" placeholder="Họ và tên người nộp..." />
                <UiInput v-model="s.payerPhone" type="tel" name="payer_phone" :id="prefix + 'payer_phone'" label="Số điện thoại" placeholder="09xxxxxxxx..." class="font-code" />
            </div>
            <UiTextarea name="notes" :id="prefix + 'notes'" label="Ghi chú nội bộ" rows="3" :value="form.editing?.notes" placeholder="Nhập ghi chú quan trọng cho bộ phận kế toán và quản lý lớp..." />
        </div>
    </div>

    <!-- Khối 5: Minh chứng thanh toán -->
    <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
        <div class="flex items-center justify-between">
            <h3 class="flex items-center gap-1.5 text-sm font-bold text-on-surface">
                <span class="material-symbols-outlined text-base text-primary">upload_file</span>
                Minh chứng thanh toán <span v-show="form.proofRequired" class="text-error">*</span>
            </h3>
            <span :class="['flex items-center gap-1 text-xs font-medium', form.proofRequired ? 'text-warning' : 'text-on-surface-subtle']">
                <span class="material-symbols-outlined text-xs">{{ form.proofRequired ? 'warning' : 'info' }}</span>
                <span>{{ form.paperMode ? 'Bắt buộc khi gửi duyệt: ảnh chụp hóa đơn giấy số ' + (form.paperNumber || '') + ' đã ghi đúng nội dung thu' : form.proofRequired ? 'Bắt buộc khi gửi duyệt: ủy nhiệm chi / ảnh chuyển khoản' : 'Tiền mặt: không cần ảnh minh chứng, chỉ cần số hóa đơn giấy' }}</span>
            </span>
        </div>

        <div class="relative flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-outline-variant p-6 text-center transition hover:bg-surface-container-low/50" @click="fileInput?.click()">
            <input ref="fileInput" type="file" name="proof_image" accept="image/*,.pdf" class="hidden" @change="form.handleFileSelected($event)" />
            <div class="mb-2.5 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-container/10 text-primary">
                <span class="material-symbols-outlined text-2xl">cloud_upload</span>
            </div>
            <div>
                <p class="text-xs font-bold text-on-surface">Kéo thả hoặc <span class="text-primary underline">chọn tệp</span> để tải lên {{ form.paperMode ? 'ảnh chụp hóa đơn giấy' : 'ủy nhiệm chi/biên lai chuyển khoản' }}</p>
                <p class="mt-1 text-xs text-on-surface-subtle">Hỗ trợ: JPG, PNG, PDF (Tối đa 5MB) - Đảm bảo rõ ràng thông tin giao dịch &amp; mã tham chiếu</p>
            </div>
        </div>

        <div v-if="s.proofPreviewUrl" class="space-y-1.5 pt-2">
            <p class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Minh chứng đã đính kèm</p>
            <div class="flex items-center gap-3 rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
                <div class="group relative h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-outline-variant bg-surface-container-high">
                    <img v-show="!s.proofIsPdf" :src="s.proofPreviewUrl" alt="Minh chứng" class="h-full w-full object-cover" />
                    <span v-show="s.proofIsPdf" class="flex h-full w-full items-center justify-center text-xs font-bold text-on-surface-variant">PDF</span>
                </div>
                <div class="flex-grow space-y-0.5 text-xs">
                    <span class="flex items-center gap-1 text-xs font-bold text-tertiary">
                        <span class="material-symbols-outlined text-xs">verified</span>
                        Đã tải lên tệp minh chứng
                    </span>
                    <p class="font-code text-xs text-on-surface-variant">{{ s.proofFileName }}</p>
                    <p class="text-xs text-on-surface-subtle">{{ s.proofFileSize }}</p>
                </div>
                <UiButton variant="danger-text" icon="delete" title="Xóa tệp" aria-label="Xóa tệp" @click="form.clearProof(fileInput)" />
            </div>
        </div>
    </div>
</template>
