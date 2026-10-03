<script setup>
/**
 * Danh sách vi phạm (mockup epic-8-danh-sach-phat) — luồng BPMN 9b: ghi nhận → nhân sự giải trình → HT/CM chốt lỗi,
 * chốt mức phạt → nộp trong 2 ngày (quá hạn trừ lương) → khắc phục. Mỗi dòng chỉ giữ nút của bước tiếp theo;
 * "Đóng - không phạt" / "Hủy vi phạm" nằm trong hộp thoại chi tiết (nút ⋯). Lọc nhanh theo bước + bộ lọc nâng cao.
 */
import { computed, reactive, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { can } from '@/lib/can';

defineOptions({ layout: { title: 'Danh sách vi phạm' } });

const props = defineProps({
    penalties: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    canViewAll: { type: Boolean, default: false },
    counts: { type: Object, required: true },
    steps: { type: Object, required: true },
    categoryOptions: { type: Array, default: () => [] },
    categoryConfirmerOptions: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    commonViolations: { type: Array, default: () => [] },
    lockedPenalty: { type: String, default: null },
    violationWindow: { type: Object, required: true },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const firstError = computed(() => Object.values(errors.value)[0] ?? null);
const lockedTitle = computed(() => (props.lockedPenalty ? props.lockedPenalty.split(' Vui lòng')[0] : null));

const stepColors = {
    recorded: 'warning', confirmed: 'error', fined: 'primary', paid: 'success',
    remedied: 'info', resolved: 'secondary', cancelled: 'neutral',
};
const currentStep = computed(() => new URL(page.url ?? '/', 'http://localhost').searchParams.get('step') ?? '');
const stepLinks = computed(() => [['', 'Tất cả'], ...Object.entries(props.steps)]);

// Hộp thoại đang mở: "view-5", "explain-5", "decide-5", "remedy-5" hoặc "new-penalty".
const errored = errors.value.user_id || errors.value.violation_type || errors.value.violation_at || errors.value.evidence || (errors.value.violation_date && !props.lockedPenalty);
const open = ref(errored && can('violation.create') ? 'new-penalty' : null);
const decisions = reactive(Object.fromEntries(props.penalties.data.map((pen) => [pen.id, pen.status === 'confirmed' ? 'fine' : 'error'])));
const decisionOptions = [
    { value: 'error', label: 'Chốt lỗi (xác nhận có lỗi, chốt mức phạt sau)' },
    { value: 'fine', label: 'Chốt mức phạt (nộp trong 2 ngày, quá hạn trừ lương)' },
];
const category = ref('operations');
const decidable = (pen) => ['pending', 'explained', 'confirmed'].includes(pen.status);
const showAmount = (pen) => pen.amount > 0 && !['pending', 'explained', 'confirmed', 'resolved'].includes(pen.status);
</script>

<template>
    <div>
        <UiPageHeader title="Danh sách vi phạm" description="Quản lý và theo dõi các bước xử lý vi phạm nhân sự tại MEnglish: ghi nhận → nhân sự giải trình → HT/CM chốt lỗi, chốt mức phạt → nộp trong 2 ngày (quá hạn trừ lương) → khắc phục.">
            <template v-if="can('violation.create')" #actions>
                <UiButton icon="add_circle" @click="open = 'new-penalty'">Ghi nhận vi phạm mới</UiButton>
            </template>
        </UiPageHeader>

        <UiAlert v-if="lockedPenalty" type="error" dismissible class="mb-md" :title="lockedTitle">
            Vui lòng liên hệ bộ phận Kế toán để được hỗ trợ mở khóa kỳ lương nếu cần thiết.
        </UiAlert>
        <UiAlert v-else-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>

        <div class="mb-lg grid grid-cols-2 gap-md lg:grid-cols-4">
            <UiStatCard label="Chờ giải trình" :value="counts.pending" icon="edit_note" tone="warning" />
            <UiStatCard label="Chờ HT/CM chốt" :value="counts.deciding" icon="gavel" tone="secondary" />
            <UiStatCard label="Đã chốt phạt (trong hạn nộp)" :value="counts.fined" icon="schedule" tone="primary" />
            <UiStatCard label="Quá hạn — sẽ trừ lương" :value="counts.overdue" icon="money_off" tone="error" />
        </div>

        <UiFilterBar :action="route('penalties.index')" placeholder="Nhập tên hoặc mã nhân viên...">
            <template #quick>
                <div class="flex flex-col gap-xs sm:flex-row sm:items-center sm:gap-md">
                    <span class="font-label text-label uppercase tracking-wide text-on-surface-variant">Lọc theo bước</span>
                    <div class="flex flex-wrap gap-xs">
                        <Link
                            v-for="[key, label] in stepLinks"
                            :key="key"
                            :href="urlWith({ step: key || null, page: null })"
                            :class="['rounded-full border px-md py-xs font-body-small text-body-small transition-colors', currentStep === key ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:border-primary-container hover:text-primary']"
                            >{{ label }}</Link
                        >
                    </div>
                </div>
            </template>
            <input v-if="currentStep" type="hidden" name="step" :value="currentStep" />
            <UiSelect name="category" label="Loại lỗi" placeholder="Tất cả loại lỗi" :options="categoryOptions" />
            <UiSelect name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :options="statusOptions" />
            <UiDateRange label="Ngày vi phạm" from="from" to="to" />
        </UiFilterBar>

        <UiDataTable min-width="1100px">
            <table>
                <thead>
                    <tr>
                        <th>Nhân viên</th>
                        <th>Ngày vi phạm</th>
                        <th>Nguồn</th>
                        <th>Lỗi vi phạm</th>
                        <th>Bước hiện tại</th>
                        <th class="text-right">Số tiền phạt</th>
                        <th>Trạng thái GV</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="pen in penalties.data" :key="pen.id" class="align-top">
                        <td>
                            <p class="font-semibold text-on-surface">{{ pen.user_name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">ID: {{ pen.employee_code || '—' }} · <span class="font-code">{{ pen.code }}</span></p>
                        </td>
                        <td class="font-code text-code">
                            {{ pen.violation_date }}
                            <span v-if="pen.violation_time" class="block font-caption text-caption text-on-surface-variant">{{ pen.violation_time }}</span>
                        </td>
                        <td>{{ pen.source_label }}</td>
                        <td class="max-w-xs">
                            <p class="font-medium text-error">{{ pen.violation_type }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ pen.category_label }} · chốt bởi {{ pen.confirmer_label }}</p>
                        </td>
                        <td>
                            <UiBadge :color="stepColors[pen.step] ?? 'neutral'">{{ pen.step_label }}</UiBadge>
                            <span v-if="pen.overdue" class="mt-xs block font-caption text-caption font-semibold text-error">Quá hạn nộp — sẽ trừ lương</span>
                            <span v-else-if="pen.status === 'deducted'" class="mt-xs block font-caption text-caption text-on-surface-variant">Đã trừ vào bảng lương</span>
                        </td>
                        <td class="text-right">
                            <template v-if="showAmount(pen)">
                                <UiMoney :value="pen.amount" suffix="đ" />
                                <span v-if="pen.due_date && pen.status === 'fined'" class="block font-caption text-caption text-on-surface-variant">Hạn nộp {{ pen.due_date }}</span>
                                <span v-else-if="pen.paid_at" class="block font-caption text-caption text-tertiary">Nộp {{ pen.paid_at }}</span>
                            </template>
                            <span v-else-if="pen.status === 'resolved'" class="font-mono">0đ</span>
                            <span v-else class="text-on-surface-variant">---</span>
                        </td>
                        <td>
                            <UiBadge :color="pen.employee_color">{{ pen.employee_state }}</UiBadge>
                            <span v-if="['resolved', 'cancelled'].includes(pen.status) || pen.remedied" class="mt-xs block font-caption text-caption text-on-surface-variant">Đã kết thúc</span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-xs">
                                <UiButton v-if="pen.can_explain" size="sm" icon="edit_note" @click="open = `explain-${pen.id}`">Giải trình</UiButton>
                                <UiButton v-if="pen.can_decide && pen.step === 'recorded'" size="sm" variant="secondary" icon="gavel" @click="open = `decide-${pen.id}`">Chốt lỗi</UiButton>
                                <UiButton v-if="pen.can_decide && pen.status === 'confirmed'" size="sm" variant="secondary" icon="payments" @click="open = `decide-${pen.id}`">Chốt mức phạt</UiButton>
                                <!-- Quá hạn 2 ngày: không nhận nộp trực tiếp nữa, bảng lương trừ -->
                                <UiForm v-if="can('violation.mark_paid') && pen.status === 'fined' && !pen.overdue" :action="route('penalties.mark-paid', pen.id)" method="post">
                                    <UiButton type="submit" size="sm" variant="secondary" icon="payments">Đánh dấu đã nộp</UiButton>
                                </UiForm>
                                <UiButton v-if="can('violation.mark_resolved') && pen.step === 'paid'" size="sm" variant="secondary" icon="build" @click="open = `remedy-${pen.id}`">Ghi nhận khắc phục</UiButton>
                                <!-- Mỗi dòng chỉ giữ nút của bước tiếp theo; Đóng - không phạt / Hủy vi phạm nằm trong "⋯" (xem chi tiết) -->
                                <UiButton size="sm" variant="ghost" icon="more_horiz" title="Xem chi tiết & thao tác khác" :aria-label="`Xem chi tiết & thao tác khác ${pen.code}`" @click="open = `view-${pen.id}`" />
                            </div>

                            <UiModal :show="open === `view-${pen.id}`" :title="`Biên bản ${pen.code}`" class="text-left" @close="open = null">
                                <dl class="grid grid-cols-3 gap-sm font-body-small text-body-small">
                                    <dt class="text-on-surface-variant">Nhân viên</dt><dd class="col-span-2">{{ pen.user_name }} ({{ pen.employee_code || 'chưa có mã' }})</dd>
                                    <dt class="text-on-surface-variant">Lỗi vi phạm</dt><dd class="col-span-2">{{ pen.violation_type }} — {{ pen.category_label }}</dd>
                                    <dt class="text-on-surface-variant">Thời điểm vi phạm</dt><dd class="col-span-2">{{ pen.violation_time ? `${pen.violation_time} ` : '' }}{{ pen.violation_date }}</dd>
                                    <dt class="text-on-surface-variant">Bằng chứng</dt>
                                    <dd class="col-span-2">
                                        <a v-if="pen.evidence_url" :href="pen.evidence_url" target="_blank" rel="noopener" class="inline-flex items-center gap-xs font-semibold text-primary hover:underline">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">attach_file</span>Xem bằng chứng
                                        </a>
                                        <span v-else class="text-on-surface-variant">Không có (biên bản cũ / hệ thống tự lập)</span>
                                    </dd>
                                    <dt class="text-on-surface-variant">Lớp liên quan</dt><dd class="col-span-2">{{ pen.class_name ?? '—' }}</dd>
                                    <dt class="text-on-surface-variant">Lập bởi</dt><dd class="col-span-2">{{ pen.reporter ?? 'Hệ thống' }} ({{ pen.source_label }})</dd>
                                    <dt class="text-on-surface-variant">Mô tả</dt><dd class="col-span-2">{{ pen.notes || '—' }}</dd>
                                    <dt class="text-on-surface-variant">Giải trình</dt><dd class="col-span-2">{{ pen.explanation || 'Chưa giải trình' }}</dd>
                                    <dt class="text-on-surface-variant">Kết luận</dt><dd class="col-span-2">{{ pen.decision_note || '—' }}<template v-if="pen.decider"> ({{ pen.decider }})</template></dd>
                                    <dt class="text-on-surface-variant">Trạng thái</dt><dd class="col-span-2">{{ pen.status_label }}</dd>
                                    <template v-if="pen.remedy">
                                        <dt class="text-on-surface-variant">Khắc phục</dt><dd class="col-span-2">{{ pen.remedy }}</dd>
                                    </template>
                                </dl>
                                <template #footer>
                                    <UiForm v-if="can('violation.mark_resolved') && decidable(pen)" :action="route('penalties.resolve', pen.id)" method="post" preserve-state="errors" :confirm="`Đóng biên bản ${pen.code} — không phạt tiền?`">
                                        <UiButton type="submit" variant="secondary">Đóng - không phạt</UiButton>
                                    </UiForm>
                                    <UiForm v-if="can('violation.cancel') && decidable(pen)" :action="route('penalties.cancel', pen.id)" method="post" preserve-state="errors" :confirm="`Hủy biên bản ${pen.code}?`" confirm-label="Hủy biên bản" danger>
                                        <UiButton type="submit" variant="danger-text">Hủy vi phạm</UiButton>
                                    </UiForm>
                                    <UiButton variant="secondary" @click="open = null">Quay lại</UiButton>
                                </template>
                            </UiModal>

                            <UiModal v-if="pen.can_explain" :show="open === `explain-${pen.id}`" :title="`Giải trình biên bản ${pen.code}`" class="text-left" @close="open = null">
                                <UiForm :id="`explain-form-${pen.id}`" :action="route('penalties.explain', pen.id)" method="post" preserve-state="errors" class="space-y-md">
                                    <p>Lỗi: <strong>{{ pen.violation_type }}</strong> ngày {{ pen.violation_date }}.</p>
                                    <UiTextarea name="explanation" label="Nội dung giải trình" required rows="4" placeholder="Trình bày lý do, hoàn cảnh..." />
                                </UiForm>
                                <template #footer>
                                    <UiButton variant="secondary" @click="open = null">Hủy</UiButton>
                                    <UiButton type="submit" :form="`explain-form-${pen.id}`" icon="send">Gửi giải trình</UiButton>
                                </template>
                            </UiModal>

                            <UiModal v-if="pen.can_decide" :show="open === `decide-${pen.id}`" :title="(pen.status === 'confirmed' ? 'Chốt mức phạt ' : 'Chốt lỗi ') + pen.code" class="text-left" @close="open = null">
                                <UiForm :id="`decide-form-${pen.id}`" :action="route('penalties.confirm', pen.id)" method="post" preserve-state="errors" class="space-y-md">
                                    <div class="rounded-lg bg-surface-container-low p-sm font-body-small text-body-small">
                                        <p><strong>{{ pen.user_name }}</strong> — {{ pen.violation_type }} ({{ pen.category_label }})</p>
                                        <p class="mt-xs">{{ pen.explanation ? `Giải trình: ${pen.explanation}` : 'Nhân sự chưa gửi giải trình.' }}</p>
                                    </div>
                                    <UiSelect v-model="decisions[pen.id]" name="decision" label="Kết luận" required :options="decisionOptions" />
                                    <div v-show="decisions[pen.id] === 'fine'">
                                        <UiInput type="number" name="amount" label="Số tiền phạt (VNĐ)" min="1000" step="1000" :value="pen.amount > 0 ? Math.trunc(pen.amount) : null" />
                                    </div>
                                    <UiTextarea name="decision_note" label="Ghi chú kết luận" rows="2" />
                                </UiForm>
                                <template #footer>
                                    <UiButton variant="secondary" @click="open = null">Hủy</UiButton>
                                    <UiButton type="submit" :form="`decide-form-${pen.id}`" icon="gavel">Chốt</UiButton>
                                </template>
                            </UiModal>

                            <UiModal v-if="can('violation.mark_resolved') && pen.step === 'paid'" :show="open === `remedy-${pen.id}`" :title="`Ghi nhận khắc phục ${pen.code}`" max-width="md" class="text-left" @close="open = null">
                                <UiForm :id="`remedy-form-${pen.id}`" :action="route('penalties.remedy', pen.id)" method="post" preserve-state="errors" class="space-y-md">
                                    <p class="font-body-small text-body-small text-on-surface-variant">{{ pen.user_name }} — {{ pen.violation_type }}</p>
                                    <UiTextarea name="remedy_note" label="Nội dung khắc phục" rows="3" placeholder="VD: Đã bổ sung nhận xét, cam kết không tái phạm..." />
                                </UiForm>
                                <template #footer>
                                    <UiButton variant="secondary" @click="open = null">Hủy</UiButton>
                                    <UiButton type="submit" :form="`remedy-form-${pen.id}`" icon="check">Ghi nhận khắc phục</UiButton>
                                </template>
                            </UiModal>
                        </td>
                    </tr>
                    <tr v-if="!penalties.data.length">
                        <td colspan="8">
                            <UiEmptyState icon="gavel" title="Không có biên bản vi phạm nào" :description="canViewAll ? 'Thử đổi từ khoá hoặc xoá bộ lọc.' : 'Bạn không có biên bản vi phạm nào.'" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="penalties" /></template>
        </UiDataTable>

        <UiModal v-if="can('violation.create')" :show="open === 'new-penalty'" title="Ghi nhận vi phạm mới" data-modal="new-penalty" @close="open = null">
            <UiForm id="new-penalty-form" :action="route('penalties.store')" method="post" preserve-state="errors" class="space-y-md">
                <UiSelect name="user_id" label="Nhân sự vi phạm" required placeholder="-- Chọn nhân sự --" :options="users" />
                <UiSelect v-model="category" name="error_category" label="Loại lỗi" required hint="Lỗi chuyên môn do Học thuật (HT) chốt; lỗi vận hành do Học vụ / Quản lý (CM) chốt." :options="categoryConfirmerOptions" />
                <UiField label="Lỗi vi phạm" name="violation_type" required for="f_violation_type">
                    <input id="f_violation_type" list="violation-types" name="violation_type" required placeholder="Chọn lỗi thường gặp hoặc nhập mô tả" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50" />
                    <datalist id="violation-types">
                        <option v-for="type in commonViolations" :key="type" :value="type"></option>
                    </datalist>
                </UiField>
                <div class="grid grid-cols-2 gap-md">
                    <!-- Chỉ ghi nhận vi phạm trong 24h gần nhất (server kiểm tra lại) -->
                    <UiInput type="datetime-local" name="violation_at" label="Thời điểm vi phạm" required :min="violationWindow.min" :max="violationWindow.max" :value="violationWindow.max" hint="Trong vòng 24h gần nhất." />
                    <UiSelect name="class_id" label="Lớp liên quan" placeholder="— Không —" :options="classes" />
                </div>
                <UiField label="Bằng chứng vi phạm" name="evidence" for="f_evidence" required hint="Ảnh (JPG, PNG, GIF, WEBP) hoặc PDF, tối đa 10MB.">
                    <input
                        id="f_evidence"
                        type="file"
                        name="evidence"
                        accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                        required
                        class="block w-full font-body-small text-body-small file:mr-sm file:rounded-lg file:border-0 file:bg-surface-container-high file:px-sm file:py-xs"
                    />
                </UiField>
                <UiTextarea name="notes" label="Mô tả sự việc" rows="2" />
                <p class="font-caption text-caption text-on-surface-variant">
                    Vi phạm quá 24h không ghi nhận được. Chưa cần nhập số tiền: mức phạt do HT/CM chốt sau khi nhân sự giải trình.
                </p>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="open = null">Hủy</UiButton>
                <UiButton type="submit" form="new-penalty-form" icon="save">Ghi nhận</UiButton>
            </template>
        </UiModal>
    </div>
</template>
