<script setup>
/** Shared receipt fields; state and calculations come from useReceiptForm. */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ReceiptSummary from './ReceiptSummary.vue';
import { Link, useFormContext } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/format';
import { promotionLabel } from '@/lib/promotion';

const props = defineProps({
    form: { type: Object, required: true },
    prefix: { type: String, default: '' },
    layout: { type: String, default: 'modal', validator: (value) => ['page', 'modal'].includes(value) },
    submitLabel: { type: String, default: 'Lưu phiếu thu & Gửi duyệt' },
    cancelUrl: { type: String, default: '' },
    recentRejection: { type: Object, default: null },
    canRequestCancel: { type: Boolean, default: false },
});

const ctx = useFormContext();
const errorList = computed(() => Object.values(ctx?.errors ?? {}).flat());
const s = computed(() => props.form.state);
const money = (value) => formatMoney(value || 0);
const fileInput = ref(null);
const q = computed(() => props.form.quote);

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
const methodClass = (method) => (s.value.paymentMethod === method ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container-lowest/60');
const stepsContainer = ref(null);
let desktopQuery;
function expandDesktopSteps() {
    if (desktopQuery?.matches) stepsContainer.value?.querySelectorAll('details').forEach((step) => { step.open = true; });
}
function keepStepExpanded(event) {
    if (props.layout === 'modal' || desktopQuery?.matches) event.preventDefault();
}
onMounted(() => {
    desktopQuery = window.matchMedia('(min-width: 1024px)');
    desktopQuery.addEventListener('change', expandDesktopSteps);
    expandDesktopSteps();
});
onBeforeUnmount(() => desktopQuery?.removeEventListener('change', expandDesktopSteps));
</script>

<template>
    <input v-if="form.editing" type="hidden" name="remove_proof" :value="s.proofRemoved ? 1 : 0" />

    <!-- Notices. -->
    <UiAlert v-if="errorList.length" type="error" title="Vui lòng kiểm tra các thông tin sau:">
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(err, i) in errorList" :key="i">{{ err }}</li>
        </ul>
    </UiAlert>

    <UiAlert v-if="recentRejection" type="error" :title="`Lý do từ chối gần nhất (Phiếu: ${recentRejection.receipt_number})`">
        <p class="leading-relaxed">{{ recentRejection.rejection_reason }}</p>
        <Link v-if="recentRejection.can_edit" :href="route('tuition.receipts.edit', recentRejection.id)" class="mt-1.5 inline-flex min-h-11 items-center gap-1 text-xs font-bold text-error underline">
            <span class="material-symbols-outlined text-sm">edit</span> Sửa phiếu bị trả về &amp; gửi duyệt lại
        </Link>
    </UiAlert>

    <UiAlert v-show="s.currentTuition?.class_changed && !s.skipTuition" type="info" title="Học viên vừa chuyển lớp mới">
        Khoản học phí này thuộc lớp <strong>{{ s.currentTuition?.class_name }}</strong>, học viên đang học lớp <strong>{{ s.currentTuition?.current_class_name }}</strong>. Sổ buổi tính theo học viên nên buổi tồn được mang sang lớp mới; số buổi cần thu tính theo lớp đang học.
    </UiAlert>

    <div :class="['grid items-start gap-6', layout === 'page' ? 'lg:grid-cols-[minmax(0,1fr)_22rem]' : '']">
        <div ref="stepsContainer" class="order-1 min-w-0 space-y-6">
            <!-- Select the student and tuition record. -->
            <details open class="group min-w-0 rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <summary class="flex min-h-16 cursor-pointer list-none items-center gap-3 p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container sm:p-5 [&::-webkit-details-marker]:hidden" :class="layout === 'page' ? 'lg:cursor-default' : 'cursor-default'" @click="keepStepExpanded">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-light text-sm font-bold text-primary" aria-hidden="true">1</span>
                    <span class="min-w-0 flex-1">
                        <span v-if="layout === 'page'" class="mb-1 block text-xs font-medium text-primary lg:hidden">Bước 1/4</span>
                        <span role="heading" aria-level="2" class="text-sm font-bold text-on-surface">Học viên &amp; khoản thu</span>
                    </span>
                    <span v-if="layout === 'page'" class="material-symbols-outlined shrink-0 text-xl text-on-surface-variant group-open:rotate-180 lg:hidden" aria-hidden="true">expand_more</span>
                </summary>

                <div class="border-y border-surface-container-highest bg-surface-container-low/50 p-4 md:p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiField label="Khoản học phí đang nợ (tùy chọn)" name="student_tuition_id" :for="prefix + 'receipt_student_tuition_id'" required>
                            <UiSelect
                                v-model="s.selectedTuitionId"
                                name="student_tuition_id"
                                :id="prefix + 'receipt_student_tuition_id'"
                                :options="tuitionOptions"
                                :disabled="!!form.editing"
                                class="font-bold"
                                placeholder="-- Không chọn khoản (thu theo sổ buổi của học viên) --"
                                @change="form.onTuitionChange()"
                            />
                        </UiField>

                        <UiSelect v-model="s.selectedStudentId" name="student_id" :id="prefix + 'receipt_student_id'" label="Học viên được ghi nhận" required :options="studentOptions" :disabled="!!form.editing" @change="form.onStudentChange()" />
                    </div>
                </div>

                <!-- Student identity; session counts remain in the ledger below. -->
                <div class="flex min-w-0 items-center gap-3 border-b border-surface-container-highest p-4 sm:px-5">
                    <span class="material-symbols-outlined flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-light text-xl text-primary" aria-hidden="true">person</span>
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-bold text-on-surface">{{ s.currentStudent?.name || '—' }}</p>
                        <p class="break-words text-xs text-on-surface-variant"><span class="font-code text-primary" :title="s.currentStudent?.code">{{ s.currentStudent?.code_short || '—' }}</span> · {{ s.currentStudent?.class_name || '—' }}</p>
                    </div>
                    <UiBadge color="secondary" class="shrink-0">{{ s.currentStudent?.status_label || '—' }}</UiBadge>
                </div>

                <!-- Choose session collection or extras only. -->
                <div class="flex flex-col items-start justify-between gap-3 border-b border-surface-container-highest/80 bg-surface-container-low/70 p-4 sm:px-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                            <span class="material-symbols-outlined text-base text-primary">event_available</span>
                            <span>Học phí theo sổ buổi:</span>
                        </div>
                        <div v-if="!s.skipTuition && q && q.mode === 'contract'" class="flex items-center gap-1.5 rounded-lg border border-primary-container/60 bg-surface-container-lowest px-3 py-1 text-xs text-on-surface shadow-2xs">
                            <span class="material-symbols-outlined text-sm text-tertiary">check_circle</span>
                            <span>Thu tiếp khoản học phí đang nợ{{ s.currentTuition ? ' - ' + s.currentTuition.class_name : '' }}</span>
                        </div>
                        <div v-else-if="!s.skipTuition && q && q.mode === 'new'" class="flex items-center gap-1.5 rounded-lg border border-primary-container/60 bg-surface-container-lowest px-3 py-1 text-xs text-on-surface shadow-2xs">
                            <span class="material-symbols-outlined text-sm text-tertiary">add_circle</span>
                            <span>Khóa kế tiếp - lớp <strong>{{ q.class_name }}</strong> (tạo khoản học phí khi phiếu được duyệt)</span>
                        </div>
                        <div v-else class="flex items-center gap-1.5 rounded-lg border border-warning/30 bg-warning-container px-3 py-1 text-xs text-on-warning-container">
                            <span class="material-symbols-outlined text-sm text-warning">info</span>
                            <span>{{ s.skipTuition ? 'Đang bỏ qua học phí (lập phiếu chỉ thu học liệu / phụ thu)' : 'Học viên chưa xếp lớp và chưa có khoản học phí: chưa thu theo buổi được' }}</span>
                        </div>
                    </div>
                    <div class="flex w-full flex-wrap items-center gap-2">
                        <UiButton v-if="!s.skipTuition && form.canCollectSessions" variant="secondary" size="sm" icon="close" class="w-full !whitespace-normal" @click="form.toggleSkipTuition(true)">Bỏ qua học phí (chỉ thu phụ thu)</UiButton>
                        <UiButton v-if="s.skipTuition" variant="secondary" size="sm" icon="add" class="border-primary-container text-primary" @click="form.toggleSkipTuition(false)">Bật lại học phí</UiButton>
                    </div>
                </div>

                <!-- Session ledger and sessions to collect when tuition is enabled. -->
                <div v-if="!s.skipTuition && q" class="space-y-0" data-testid="session-ledger">
                    <div class="grid grid-cols-2 divide-x divide-surface-container-highest border-b border-surface-container-highest text-center text-xs md:grid-cols-4">
                        <div class="p-4">
                            <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Buổi đã đóng</span>
                            <span class="text-lg font-bold text-on-surface">{{ q.paid }}</span>
                        </div>
                        <div class="p-4">
                            <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle" title="Mỗi buổi lớp diễn ra trừ 1, vắng vẫn trừ">Buổi đã trừ</span>
                            <span class="text-lg font-bold text-tertiary">{{ q.used }}</span>
                        </div>
                        <div class="p-4">
                            <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Buổi tồn</span>
                            <span :class="['text-lg font-bold', q.balance < 0 ? 'text-error' : 'text-primary']">{{ q.balance }}</span>
                        </div>
                        <div class="p-4">
                            <span class="mb-1 block text-xs font-semibold uppercase text-on-surface-subtle">Trạng thái học</span>
                            <span :class="['text-sm font-bold', s.currentTuition?.is_deferred ? 'text-secondary' : 'text-on-surface-variant']">{{ s.currentTuition?.is_deferred ? 'Đang bảo lưu' : s.currentStudent?.status_label || '—' }}</span>
                        </div>
                    </div>

                    <div class="space-y-4 bg-surface-container-low/50 p-5">
                        <div class="space-y-1.5 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 text-xs">
                            <div class="flex flex-wrap justify-between gap-x-3">
                                <span class="text-on-surface-variant">Khóa hiện tại {{ q.class_name ? '(lớp ' + q.class_name + ')' : '(chưa xếp lớp)' }}</span>
                                <span class="font-code text-on-surface">{{ q.course_sessions }} buổi · đã diễn ra {{ q.held }}</span>
                            </div>
                            <div class="flex flex-wrap justify-between gap-x-3">
                                <span class="text-on-surface-variant">Buổi còn lại của khóa</span>
                                <span class="font-code font-bold text-on-surface">{{ q.course_sessions }} − {{ q.held }} = {{ q.remaining }}</span>
                            </div>
                            <div class="flex flex-wrap justify-between gap-x-3 border-t border-dashed border-surface-container-highest pt-1.5">
                                <span class="font-bold text-on-surface">Buổi cần thu = MAX(còn lại − tồn, 0)</span>
                                <span class="font-code font-bold text-primary">MAX({{ q.remaining }} − {{ q.balance }}, 0) = {{ q.needed }}</span>
                            </div>
                            <p v-if="q.carry_over > 0" class="text-tertiary">Buổi tồn nhiều hơn số buổi còn lại: thu 0 buổi, dư {{ q.carry_over }} buổi tự trừ vào khóa kế tiếp.</p>
                        </div>

                        <div v-if="form.canCollectSessions" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <UiField label="Số buổi thu đợt này" :for="prefix + 'session_count'" name="session_count" :hint="q.mode === 'contract' ? `Tối đa ${q.max_sessions} buổi chưa đóng của khoản học phí. Thu ít hơn để đóng từng phần.` : `Tối đa ${q.max_sessions} buổi (Buổi cần thu). Thu ít hơn để đóng từng phần.`">
                                <UiInput v-model.number="s.sessionCount" type="number" :id="prefix + 'session_count'" min="0" :max="form.maxSessions" suffix="buổi" class="font-code font-bold" />
                            </UiField>
                            <UiInput :id="prefix + 'session_unit_price'" label="Đơn giá / buổi" :model-value="money(q.unit_price)" readonly :hint="q.mode === 'contract' ? 'Học phí sau ưu đãi khi chốt / số buổi của khoản.' : 'Học phí niêm yết của lớp / số buổi của khóa.'" class="font-code" />
                            <UiInput :id="prefix + 'session_value'" label="Tiền học phí theo buổi" :model-value="money(form.calc.sessionValue)" readonly class="font-code font-bold" />
                        </div>

                        <!-- Discounts apply to tuition only; manual discounts require a reason. -->
                        <div v-if="form.sessions > 0" class="grid w-full min-w-0 gap-3 sm:grid-cols-2">
                            <UiSelect
                                v-model="s.promotionId"
                                :id="prefix + 'promotion_id'"
                                label="Ưu đãi áp dụng"
                                :options="promotionOptions"
                                placeholder="-- Không theo ưu đãi (nhập tay) --"
                                :hint="'Tính trên tiền học phí theo buổi ' + money(form.tuitionSubtotal) + '.'"
                            />
                            <UiInput v-if="form.selectedPromotion" :id="prefix + 'discount_amount'" label="Số tiền giảm trừ (VNĐ)" :model-value="form.discountValue" readonly suffix="VNĐ" class="font-code font-bold" />
                            <UiInput v-else v-model.number="s.discountAmount" type="number" :id="prefix + 'discount_amount'" label="Số tiền giảm trừ (VNĐ)" hint="Không vượt tiền học phí theo buổi." suffix="VNĐ" min="0" :max="form.tuitionSubtotal" class="font-code font-bold" placeholder="0" />
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
                    </div>
                </div>
            </details>

            <input type="hidden" name="session_count" :value="form.sessions" />
            <input type="hidden" name="promotion_id" :value="form.selectedPromotion ? form.selectedPromotion.id : ''" />
            <input type="hidden" name="discount_amount" :value="form.discountValue" />

            <details open class="group min-w-0 rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <summary class="flex min-h-16 cursor-pointer list-none items-center gap-3 p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container sm:p-5 [&::-webkit-details-marker]:hidden" :class="layout === 'page' ? 'lg:cursor-default' : 'cursor-default'" @click="keepStepExpanded">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-light text-sm font-bold text-primary" aria-hidden="true">2</span>
                    <span class="min-w-0 flex-1">
                        <span v-if="layout === 'page'" class="mb-1 block text-xs font-medium text-primary lg:hidden">Bước 2/4</span>
                        <span role="heading" aria-level="2" class="text-sm font-bold text-on-surface">Học liệu, phí thi &amp; phụ thu</span>
                    </span>
                    <span v-if="layout === 'page'" class="material-symbols-outlined shrink-0 text-xl text-on-surface-variant group-open:rotate-180 lg:hidden" aria-hidden="true">expand_more</span>
                </summary>
                <div class="space-y-4 border-t border-surface-container-highest p-4 sm:p-5">
                    <div v-if="form.calc.feeDue > 0" class="flex justify-between rounded-xl border border-warning/30 bg-warning-container px-3.5 py-2.5 text-xs text-on-warning-container">
                        <span>Học liệu còn nợ lúc chốt (thu kèm khi thu buổi của khoản này)</span>
                        <span class="font-code font-bold">{{ money(form.calc.feeDue) }}</span>
                    </div>

                    <!-- Catalog merchandise is deducted from branch stock on approval. -->
                    <div class="space-y-2">
                        <UiField label="Học liệu: sách / hàng hóa giao kèm phiếu" name="collected_items" :for="prefix + 'surcharge_item_pick'" hint="Chọn từ danh mục hàng hóa: giá theo danh mục, kho chi nhánh tự trừ khi phiếu được duyệt.">
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
                                            <input v-model.number="line.quantity" type="number" min="1" max="1000" class="h-10 w-16 rounded-lg border border-surface-container-highest px-2 text-center font-code text-base md:text-xs" :aria-label="`Số lượng ${form.itemById(line.id)?.name}`" />
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <template v-if="form.stockOf(line.id) !== null">
                                                <span :class="['font-code font-bold', form.stockOf(line.id) < line.quantity ? 'text-error' : 'text-on-surface']">{{ form.stockOf(line.id) }}</span>
                                                <span v-if="form.stockOf(line.id) < line.quantity" class="block text-xs text-error">Không đủ hàng: vẫn thu được, hệ thống giao Admin nhập bù</span>
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

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <UiInput v-model.number="s.examFee" type="number" name="exam_fee" :id="prefix + 'exam_fee'" label="Phí thi (VNĐ)" suffix="VNĐ" min="0" step="10000" class="font-code font-bold" placeholder="0" />
                        <UiInput v-model.number="s.otherFee" type="number" name="other_fee" :id="prefix + 'other_fee'" label="Khoản thu khác (VNĐ)" suffix="VNĐ" min="0" step="10000" class="font-code font-bold" placeholder="0" />
                        <UiInput v-model="s.otherFeeReason" name="other_fee_reason" :id="prefix + 'other_fee_reason'" label="Nội dung khoản khác" :required="s.otherFee > 0" placeholder="VD: Đồng phục, thẻ học viên..." />
                    </div>

                    <input type="hidden" name="surcharge_amount" :value="form.surchargeTotal" />
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiField label="Phụ thu (VNĐ)" name="surcharge_amount" :for="prefix + 'surcharge_amount'" hint="Cộng sau giảm trừ, ngoài tổng phải thu.">
                            <UiInput v-model.number="s.surchargeAmount" type="number" :id="prefix + 'surcharge_amount'" suffix="VNĐ" min="0" step="10000" class="font-code font-bold" placeholder="0" />
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span class="text-xs text-on-surface-subtle">Gợi ý nhanh:</span>
                                <UiButton variant="secondary" size="sm" @click="form.setSurcharge(50000)">50.000đ</UiButton>
                                <UiButton variant="secondary" size="sm" @click="form.setSurcharge(100000)">100.000đ</UiButton>
                                <UiButton variant="secondary" size="sm" @click="form.setSurcharge(200000)">200.000đ</UiButton>
                            </div>
                        </UiField>
                        <UiInput v-model="s.surchargeReason" name="surcharge_reason" :id="prefix + 'surcharge_reason'" label="Lý do phụ thu" :required="s.surchargeAmount > 0" placeholder="VD: Phí in tài liệu bổ sung..." />
                    </div>
                </div>
            </details>

            <input type="hidden" name="amount" :value="form.totalAmount" />
            <input type="hidden" name="tuition_amount" :value="form.tuitionAmountAfterDiscount" />

            <details open class="group min-w-0 rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <summary class="flex min-h-16 cursor-pointer list-none items-center gap-3 p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container sm:p-5 [&::-webkit-details-marker]:hidden" :class="layout === 'page' ? 'lg:cursor-default' : 'cursor-default'" @click="keepStepExpanded">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-light text-sm font-bold text-primary" aria-hidden="true">3</span>
                    <span class="min-w-0 flex-1">
                        <span v-if="layout === 'page'" class="mb-1 block text-xs font-medium text-primary lg:hidden">Bước 3/4</span>
                        <span role="heading" aria-level="2" class="text-sm font-bold text-on-surface">Hình thức thu tiền</span>
                    </span>
                    <span v-if="layout === 'page'" class="material-symbols-outlined shrink-0 text-xl text-on-surface-variant group-open:rotate-180 lg:hidden" aria-hidden="true">expand_more</span>
                </summary>
                <div class="space-y-5 border-t border-surface-container-highest p-4 sm:p-5">
                    <div role="radiogroup" aria-label="Hình thức thu tiền" class="grid grid-cols-2 gap-1 rounded-xl bg-surface-container-low p-1">
                        <label :class="['relative flex min-h-11 min-w-0 cursor-pointer items-center justify-center rounded-lg px-2 py-2 transition focus-within:ring-2 focus-within:ring-primary-container focus-within:ring-offset-2', methodClass('transfer')]">
                            <input v-model="s.paymentMethod" type="radio" name="payment_method" value="transfer" class="sr-only" />
                            <div class="flex items-center gap-2">
                                <span :class="['material-symbols-outlined text-lg', s.paymentMethod === 'transfer' ? 'text-primary' : 'text-on-surface-subtle']" aria-hidden="true">account_balance</span>
                                <span :class="['text-xs font-bold', s.paymentMethod === 'transfer' ? 'text-primary' : 'text-on-surface-variant']">Chuyển khoản</span>
                            </div>
                        </label>
                        <label :class="['relative flex min-h-11 min-w-0 cursor-pointer items-center justify-center rounded-lg px-2 py-2 transition focus-within:ring-2 focus-within:ring-primary-container focus-within:ring-offset-2', methodClass('cash')]">
                            <input v-model="s.paymentMethod" type="radio" name="payment_method" value="cash" class="sr-only" />
                            <div class="flex items-center gap-2">
                                <span :class="['material-symbols-outlined text-lg', s.paymentMethod === 'cash' ? 'text-primary' : 'text-on-surface-subtle']" aria-hidden="true">payments</span>
                                <span :class="['text-xs font-bold', s.paymentMethod === 'cash' ? 'text-primary' : 'text-on-surface-variant']">Tiền mặt</span>
                            </div>
                        </label>
                    </div>

                    <!-- Bank transfer. -->
                    <div v-show="s.paymentMethod === 'transfer'" class="min-w-0 space-y-3 break-words pt-2">
                        <UiAlert type="info" title="Trạng thái đối soát: Tạm thu">
                            Hệ thống tự động ghi nhận &amp; khớp giao dịch khi phụ huynh chuyển khoản đúng cú pháp (Nội dung CK: <strong>{{ form.transferMemo }}</strong>).
                        </UiAlert>

                        <div v-show="form.bank" class="space-y-3 rounded-xl border border-surface-container-highest bg-surface-container-low/50 p-3.5 text-xs">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest/80 pb-2">
                                <h5 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Tài khoản ngân hàng nhận học phí</h5>
                                <span class="text-xs italic text-on-surface-subtle">{{ form.bank?.scope }}</span>
                            </div>
                            <div class="flex flex-col items-center gap-4 sm:flex-row">
                                <div class="w-full min-w-0 flex-grow space-y-2">
                                    <div class="flex flex-wrap justify-between gap-x-3 border-b border-dashed border-surface-container-highest pb-1">
                                        <span class="text-on-surface-variant">Ngân hàng</span>
                                        <span class="font-bold text-on-surface">{{ form.bank?.bank_name }}</span>
                                    </div>
                                    <div class="flex flex-wrap justify-between gap-x-3 border-b border-dashed border-surface-container-highest pb-1">
                                        <span class="text-on-surface-variant">Số tài khoản</span>
                                        <span class="break-all font-code font-bold text-primary">{{ form.bank?.account_number }}</span>
                                    </div>
                                    <div class="flex flex-wrap justify-between gap-x-3 border-b border-dashed border-surface-container-highest pb-1">
                                        <span class="text-on-surface-variant">Chủ tài khoản</span>
                                        <span class="font-bold uppercase text-on-surface">{{ form.bank?.account_holder }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between gap-2 pt-0.5">
                                        <span class="text-on-surface-variant">Nội dung CK</span>
                                        <span class="break-all rounded bg-secondary/10 px-2 py-0.5 font-code text-xs font-bold text-secondary">{{ form.transferMemo }}</span>
                                    </div>
                                    <input type="hidden" name="transfer_memo" :value="form.transferMemo" />
                                </div>
                                <!-- Dynamic VietQR. -->
                                <div class="flex shrink-0 flex-col items-center gap-1">
                                    <div class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-xl border-2 border-primary-container/20 bg-surface-container-lowest p-1 shadow-xs">
                                        <img v-if="form.vietQrUrl" :src="form.vietQrUrl" alt="VietQR Thanh toán" class="h-full w-full object-contain" />
                                    </div>
                                    <span class="text-xs italic text-on-surface-subtle">Quét VietQR tự điền số tiền</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 rounded-lg bg-surface-container px-2.5 py-1.5 text-xs italic text-on-surface-variant">
                                <span class="material-symbols-outlined text-xs text-on-surface-subtle">lock</span>
                                <span>Tài khoản lấy theo hợp đồng / cơ sở của học viên; CM không đổi được trên màn hình này.</span>
                            </div>
                        </div>
                        <UiAlert v-show="!form.bank" type="warning"><strong>Chưa cấu hình tài khoản ngân hàng</strong> đang hoạt động cho cơ sở này (tài khoản riêng của cơ sở hoặc tài khoản dùng chung). Mã VietQR sẽ không được tạo — vui lòng liên hệ Kế toán/Admin cấu hình tài khoản trước khi hướng dẫn phụ huynh chuyển khoản.</UiAlert>

                        <UiInput v-model="s.transactionCode" name="transaction_code" :id="prefix + 'transaction_code'" label="Mã tham chiếu / Mã giao dịch ngân hàng (nếu có)" placeholder="Ví dụ: FT232981354789..." class="font-code" />
                    </div>

                    <!-- Cash payment. -->
                    <div v-show="s.paymentMethod === 'cash'" class="min-w-0 space-y-3 break-words pt-2">
                        <!-- Branches with paper invoice ranges receive the next sequential number. -->
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
                                        <dd class="min-w-0 flex-1 space-y-0.5 text-on-surface">
                                            <div v-for="(line, i) in form.paperInvoiceContent" :key="i" class="flex flex-wrap justify-between gap-x-3">
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
                                    <Link v-if="form.issuedPaperInvoice && canRequestCancel" :href="route('tuition.invoices.cancellations', { cancel_invoice: form.issuedPaperInvoice })" class="inline-flex min-h-11 items-center font-bold text-error underline">Hủy số hóa đơn này</Link>
                                </p>
                                <input v-if="!form.issuedPaperInvoice" type="hidden" name="expected_paper_invoice_number" :value="form.paperNumber" />
                            </template>
                            <UiAlert v-else type="error" title="Dải hóa đơn giấy của chi nhánh đã hết số">Nhờ Kế toán thêm dải số mới ở Cấu hình dải số hóa đơn trước khi thu tiền mặt.</UiAlert>
                        </div>
                        <div v-else class="rounded-xl border border-surface-container-highest bg-surface-container-low p-3.5">
                            <UiInput name="paper_invoice_number" :id="prefix + 'paper_invoice_number'" label="Số hóa đơn giấy thu tiền mặt (bắt buộc khi gửi duyệt)" hint="Chi nhánh chưa cấu hình dải hóa đơn giấy: xuất hóa đơn giấy cho khách rồi ghi số vào đây." :value="form.editing?.paper_invoice_number" placeholder="Ví dụ: HĐG-0824/PTM-042..." class="font-code font-bold" />
                        </div>
                    </div>

                    <!-- Supplementary payer and invoice information. -->
                    <div class="space-y-4 rounded-xl border border-surface-container-highest p-4">
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
                            <label class="relative inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center">
                                <input type="checkbox" name="is_vat_invoice" value="1" class="peer sr-only" :checked="!!form.editing?.is_vat_invoice" aria-label="Yêu cầu xuất hóa đơn đỏ (VAT)" />
                                <div class="peer h-6 w-11 rounded-full bg-surface-container-high after:absolute after:left-[2px] after:top-1/2 after:-translate-y-1/2 after:h-5 after:w-5 after:rounded-full after:border after:border-outline-variant after:bg-surface-container-lowest after:transition-all after:content-[''] peer-checked:bg-primary-container peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus-visible:ring-2 peer-focus-visible:ring-primary-container peer-focus-visible:ring-offset-2"></div>
                            </label>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <UiInput v-model="s.payerName" name="payer_name" :id="prefix + 'payer_name'" label="Người nộp tiền" placeholder="Họ và tên người nộp..." />
                            <UiInput v-model="s.payerPhone" type="tel" name="payer_phone" :id="prefix + 'payer_phone'" label="Số điện thoại" placeholder="09xxxxxxxx..." class="font-code" />
                        </div>
                        <UiTextarea name="notes" :id="prefix + 'notes'" label="Ghi chú nội bộ" rows="3" :value="form.editing?.notes" placeholder="Nhập ghi chú quan trọng cho bộ phận kế toán và quản lý lớp..." />
                    </div>
                </div>
            </details>

            <details open class="group min-w-0 rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <summary class="flex min-h-16 cursor-pointer list-none items-center gap-3 p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container sm:p-5 [&::-webkit-details-marker]:hidden" :class="layout === 'page' ? 'lg:cursor-default' : 'cursor-default'" @click="keepStepExpanded">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-light text-sm font-bold text-primary" aria-hidden="true">4</span>
                    <span class="min-w-0 flex-1">
                        <span v-if="layout === 'page'" class="mb-1 block text-xs font-medium text-primary lg:hidden">Bước 4/4</span>
                        <span role="heading" aria-level="2" class="text-sm font-bold text-on-surface">Minh chứng thanh toán <span v-show="form.proofRequired" class="text-error">*</span></span>
                    </span>
                    <span v-if="layout === 'page'" class="material-symbols-outlined shrink-0 text-xl text-on-surface-variant group-open:rotate-180 lg:hidden" aria-hidden="true">expand_more</span>
                </summary>
                <div class="space-y-4 border-t border-surface-container-highest p-4 sm:p-5">
                    <span :class="['flex items-center gap-1 text-xs font-medium', form.proofRequired ? 'text-warning' : 'text-on-surface-subtle']">
                        <span class="material-symbols-outlined text-xs">{{ form.proofRequired ? 'warning' : 'info' }}</span>
                        <span>{{ form.paperMode ? 'Bắt buộc khi gửi duyệt: ảnh chụp hóa đơn giấy số ' + (form.paperNumber || '') + ' đã ghi đúng nội dung thu' : form.proofRequired ? 'Bắt buộc khi gửi duyệt: ủy nhiệm chi / ảnh chuyển khoản' : 'Tiền mặt: không cần ảnh minh chứng, chỉ cần số hóa đơn giấy' }}</span>
                    </span>

                    <div class="relative flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-outline-variant p-6 text-center transition hover:bg-surface-container-low/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container" @click="fileInput?.click()" role="button" aria-label="Chọn tệp minh chứng thanh toán" tabindex="0" @keydown.enter.self.prevent="fileInput?.click()" @keydown.space.self.prevent="fileInput?.click()">
                        <input ref="fileInput" type="file" name="proof_image" aria-label="Minh chứng thanh toán" accept="image/*,.pdf" class="hidden" @change="form.handleFileSelected($event)" />
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
                            <div class="min-w-0 flex-grow space-y-0.5 break-words text-xs">
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
            </details>
        </div>
        <ReceiptSummary :form="form" :layout="layout" :submit-label="submitLabel" :cancel-url="cancelUrl" />
    </div>
</template>
