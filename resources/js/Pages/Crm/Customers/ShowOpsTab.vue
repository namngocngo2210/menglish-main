<script setup>
/**
 * Tab "Đặt lịch & Kết quả" của hồ sơ khách (Show.vue). Thứ tự theo luồng: Lịch test → Kết quả → Gửi kết quả → Học thử;
 * khối chưa tới giai đoạn thu về một dòng xám. Nút mở modal (hẹn test, nhập điểm, xếp học thử) phát sự kiện `open`.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { toast } from '@/lib/toast';
import RubricResult from '@/Components/Crm/RubricResult.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    customer: { type: Object, required: true },
    test: { type: Object, required: true },
    rubric: { type: Object, default: null },
    result: { type: Object, default: null },
    skills: { type: Object, default: () => ({}) },
    noRubricNotice: { type: String, default: '' },
    resultLogs: { type: Array, default: () => [] },
    hasResultLogs: { type: Boolean, default: false },
    nowInput: { type: String, default: null },
    today: { type: String, default: null },
    tomorrow: { type: String, default: null },
    placementTests: { type: Array, default: () => [] },
    gradeLevels: { type: Array, default: () => [] },
    portalTestLink: { type: String, default: null },
    linkTtlDays: { type: Number, default: 7 },
    unlinkedSubmissions: { type: Array, default: () => [] },
    canBookTrial: { type: Boolean, default: false },
    trial: { type: Object, required: true },
    trialBookings: { type: Array, default: () => [] },
    stageControls: { type: Object, required: true },
});
const emit = defineEmits(['open']);

const collapsedRow = 'flex flex-wrap items-center justify-between gap-sm border-t border-surface-container-highest pt-lg font-body-medium text-body-medium text-on-surface-variant';
// Nút "Lưu …" trong khối: kiểu phụ, chỉ tô cam khi form đang được sửa.
const dirtySave = '!border-transparent !bg-primary-container !text-white hover:!bg-primary';
const resultDirty = ref(false);

// Hẹn test: "Chọn cấp độ" → "Danh sách đề tương ứng" (mockup).
const level = ref('');
const testId = ref(String(props.placementTests[0]?.id ?? ''));
const levelTests = computed(() => props.placementTests.filter((t) => !level.value || t.group === level.value)
    .map((t) => ({ value: String(t.id), label: `[${t.code}] ${t.title}${t.duration_minutes ? ` (${t.duration_minutes}')` : ''}` })));
const levelEmpty = computed(() => level.value && !props.placementTests.some((t) => t.group === level.value));
function onLevelChange() {
    testId.value = levelTests.value[0]?.value ?? '';
}
const canScheduleHere = computed(() => ['consulting', 'test_scheduled', 'testing', 'tested'].includes(props.customer.stage));
const trialNote = computed(() => (props.trial.bookable ? 'Chưa có buổi học thử' : props.customer.stage === 'new' ? 'Mở khi khách sang Đang tư vấn' : 'Không đặt học thử ở giai đoạn ' + props.customer.stage_label));
const bookingTone = (status) => (status === 'attended' ? 'success' : status === 'scheduled' ? 'info' : 'error');

async function copyLink(message) {
    try {
        await navigator.clipboard.writeText(props.portalTestLink);
    } catch {
        // Trình duyệt chặn clipboard: vẫn báo để người dùng mở link thủ công.
    }
    toast(message);
}
</script>

<template>
    <div class="space-y-xl p-lg">
        <!-- Lịch hẹn Test + link test online -->
        <section class="space-y-md">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-secondary">event_available</span>Lịch hẹn Test
                </h4>
                <UiBadge v-if="test.hasResult" color="success">Đã có kết quả</UiBadge>
                <UiBadge v-else-if="test.hasScheduled" color="info">Đã gửi link</UiBadge>
                <UiBadge v-else color="error">Chưa gửi đề</UiBadge>
            </div>

            <p v-if="!test.hasResult && !test.hasScheduled && !canScheduleHere" class="font-body-small text-body-small text-on-surface-variant">
                {{ customer.stage === 'new' ? 'Khách đang ở bước Mới: Học vụ / Quản lý cơ sở chuyển sang Đang tư vấn rồi mới hẹn test.' : 'Không hẹn test ở giai đoạn ' + customer.stage_label + '.' }}
            </p>
            <template v-else-if="!test.hasResult && !test.hasScheduled">
                <UiForm v-if="can('entrance_test.send')" :action="route('crm.customers.schedule-test', customer.id)" method="post" class="space-y-md">
                    <input type="hidden" name="appointment_type" value="online" />
                    <UiAlert v-if="!placementTests.length" type="warning">
                        Chưa có đề test đầu vào nào đang mở.
                        <template v-if="can('placement_test.create')">
                            <Link :href="route('placement-tests.create')" class="font-body-semibold underline">Tạo đề test</Link> (chọn cấp độ khi tạo đề) rồi quay lại hẹn test.
                        </template>
                        <template v-else>Nhờ Quản lý cơ sở / Admin tạo đề ở mục Test đầu vào &amp; học thử → Đề test đầu vào.</template>
                    </UiAlert>
                    <!-- Mockup: "Chọn cấp độ" → "Danh sách đề tương ứng" -->
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        <UiSelect id="test_level" v-model="level" label="Chọn cấp độ" placeholder="Tất cả cấp độ" :options="gradeLevels" @change="onLevelChange" />
                        <div>
                            <UiSelect id="assigned_test_id" v-model="testId" name="assigned_test_id" label="Danh sách đề tương ứng" :options="levelTests" />
                            <p v-show="levelEmpty" class="mt-xs font-caption text-caption text-danger">
                                Chưa có đề cho cấp độ này.
                                <Link v-if="can('placement_test.create')" :href="route('placement-tests.create')" class="underline">Tạo đề</Link>
                            </p>
                        </div>
                        <UiDate name="appointment_date" label="Ngày hẹn làm test" required :min="today" :value="tomorrow" />
                        <UiInput type="time" name="appointment_time" label="Giờ hẹn" required value="09:00" />
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-sm">
                        <UiButton v-if="can('entrance_test.grade')" variant="secondary" icon="edit_note" @click="emit('open', 'score')">Nhập điểm trực tiếp</UiButton>
                        <UiButton type="submit" icon="send">Gửi link test online</UiButton>
                    </div>
                </UiForm>
                <p v-else class="font-body-small text-body-small text-on-surface-variant">Chưa hẹn test. Học vụ / Quản lý cơ sở gửi link test cho khách.</p>
            </template>
            <template v-else-if="!test.hasResult && test.hasScheduled">
                <div class="flex items-start justify-between gap-md rounded-lg border border-secondary/30 bg-info-container p-md">
                    <div class="flex items-start gap-sm">
                        <span class="material-symbols-outlined mt-0.5 text-secondary">schedule_send</span>
                        <div>
                            <p class="font-body-semibold text-body-semibold text-on-surface">Đã gửi link — chờ khách làm bài</p>
                            <p class="font-caption text-caption italic text-info">Hẹn lúc {{ customer.appointment_at ?? '—' }} · {{ customer.assigned_test?.title ?? 'Chưa gán đề' }}{{ customer.assigned_test?.duration_minutes ? ' · ' + customer.assigned_test.duration_minutes + ' phút' : '' }}</p>
                        </div>
                    </div>
                    <UiButton v-if="can('entrance_test.grade')" variant="secondary" size="sm" icon="edit" @click="emit('open', 'score')">Nhập điểm ngay</UiButton>
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">Cấp độ: <strong class="text-on-surface">{{ customer.assigned_test_level ?? 'Chưa chọn cấp độ' }}</strong></p>
                <div v-if="portalTestLink" class="flex flex-wrap gap-sm">
                    <UiButton variant="secondary" size="sm" icon="refresh" @click="copyLink(`Đã tạo và sao chép link mới (hiệu lực ${linkTtlDays} ngày) — gửi lại cho khách qua Zalo/SMS.`)">Gửi lại link</UiButton>
                    <UiButton variant="secondary" size="sm" icon="open_in_new" :href="portalTestLink" target="_blank">Mở cổng test</UiButton>
                    <UiButton variant="secondary" size="sm" icon="content_copy" @click="copyLink('Đã sao chép đường dẫn bài test.')">Sao chép link test</UiButton>
                    <p class="w-full font-caption text-caption italic text-on-surface-variant">Link riêng của khách, hiệu lực {{ linkTtlDays }} ngày kể từ lúc mở trang này.</p>
                </div>
                <UiAlert v-else type="warning">Khách chưa được gán đề test đang hoạt động nên chưa thể tạo link làm bài.</UiAlert>
            </template>
            <template v-else>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Lịch hẹn: {{ customer.appointment_at ?? 'Làm bài không qua lịch hẹn' }}{{ test.submissionTest ? ' · Đề: ' + test.submissionTest : '' }}
                </p>
                <UiButton v-if="can('entrance_test.send') && ['consulting', 'test_scheduled', 'tested'].includes(customer.stage)" variant="ghost" size="sm" icon="event_repeat" @click="emit('open', 'scheduleTest')">Hẹn test lại</UiButton>
            </template>
        </section>

        <!-- Kết quả & Đánh giá (thang điểm khối lớp — A6 Q2) -->
        <template v-if="!test.hasResult">
            <div :class="collapsedRow">
                <span class="flex items-center gap-sm"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">assignment_turned_in</span>Kết quả &amp; Đánh giá</span>
                <span class="font-caption text-caption">Chưa có kết quả test đầu vào</span>
            </div>
            <!-- Bài nộp qua link công khai chưa tự khớp được khách (SĐT gõ khác...): Học vụ gắn tay -->
            <div v-if="unlinkedSubmissions.length" class="space-y-sm rounded-lg border border-warning/40 bg-warning-container/40 p-md" data-testid="unlinked-submissions">
                <p class="font-body-small text-body-small font-semibold text-on-surface">Có bài test chưa gắn với khách nào, trùng SĐT hoặc tên của khách này:</p>
                <div v-for="candidate in unlinkedSubmissions" :key="candidate.id" class="flex flex-wrap items-center justify-between gap-sm rounded-lg bg-surface-container-lowest px-md py-sm font-body-small text-body-small">
                    <div class="min-w-0">
                        <div class="font-semibold text-on-surface">{{ candidate.name }} · {{ candidate.phone }}</div>
                        <div class="text-on-surface-variant">{{ candidate.test ?? 'Đề đã xoá' }} · nộp {{ candidate.created_at }} · {{ candidate.status }}</div>
                    </div>
                    <div class="flex items-center gap-xs">
                        <UiButton v-if="can('placement_test.grade')" variant="ghost" size="sm" icon="visibility" :href="route('placement-tests.results.show', candidate.id)">Xem bài</UiButton>
                        <UiForm :action="route('crm.customers.link-submission', customer.id)" method="post">
                            <input type="hidden" name="submission_id" :value="candidate.id" />
                            <UiButton type="submit" variant="secondary" size="sm" icon="link">Gắn vào khách này</UiButton>
                        </UiForm>
                    </div>
                </div>
                <p v-if="$page.props.errors?.submission_id" class="text-error">{{ $page.props.errors.submission_id }}</p>
            </div>
        </template>
        <section v-else class="space-y-md border-t border-surface-container-highest pt-lg">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-tertiary">assignment_turned_in</span>Kết quả &amp; Đánh giá
                    <span v-if="rubric && !rubric.legacy && rubric.has_rubric" class="inline-flex items-center gap-xs rounded-full bg-secondary/10 px-sm py-0.5 font-caption text-caption font-bold text-secondary"><span class="material-symbols-outlined text-[14px]">auto_awesome</span>Thang điểm tự động</span>
                </h4>
                <UiButton v-if="test.scorecardUrl" variant="secondary" size="sm" icon="picture_as_pdf" :href="test.scorecardUrl" target="_blank">Tải kết quả (PDF)</UiButton>
            </div>
            <RubricResult :rubric="rubric" :result="result" :skills="skills" :scorecard-url="test.scorecardUrl" :has-submission="!!test.submissionId" :no-rubric-notice="noRubricNotice" />
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <div class="flex flex-wrap items-center gap-sm">
                    <template v-if="test.submissionId">
                        <UiButton variant="secondary" size="sm" icon="description" :href="test.scorecardUrl" target="_blank">Bảng điểm Scorecard</UiButton>
                        <UiButton v-if="can('placement_test.grade')" variant="secondary" size="sm" icon="assignment_turned_in" :href="route('placement-tests.results.show', test.submissionId)">Chi tiết bài làm</UiButton>
                    </template>
                    <UiButton
                        v-if="can('entrance_test.grade') && ['consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'].includes(customer.stage)"
                        variant="secondary"
                        size="sm"
                        icon="edit_note"
                        @click="emit('open', 'score')"
                    >{{ customer.stage === 'test_scheduled' ? 'Nhập điểm lần test lại' : 'Sửa điểm' }}</UiButton>
                </div>
                <Link :href="route('placement-tests.rubric-guide')" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                    Thang điểm &amp; hướng dẫn nhận xét<span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                </Link>
            </div>
        </section>

        <!-- Gửi kết quả & Phản hồi phụ huynh -->
        <div v-if="!test.hasResult && !hasResultLogs" :class="collapsedRow">
            <span class="flex items-center gap-sm"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">forward_to_inbox</span>Gửi kết quả &amp; Phản hồi</span>
            <span class="font-caption text-caption">Mở sau khi có kết quả test</span>
        </div>
        <section v-else class="space-y-md border-t border-surface-container-highest pt-lg">
            <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary">forward_to_inbox</span>Gửi kết quả &amp; Phản hồi</h4>
            <div v-for="log in resultLogs" :key="log.id" class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small">
                <p class="whitespace-pre-line text-on-surface">{{ log.content }}</p>
                <p class="font-caption text-caption text-on-surface-variant">{{ log.user ?? 'Hệ thống' }} · {{ log.created_at }}</p>
            </div>
            <template v-if="can('lead.update')">
                <UiForm
                    v-if="test.hasResult && customer.stage !== 'lost'"
                    :action="route('crm.customers.notes.store', customer.id)"
                    method="post"
                    class="grid grid-cols-1 gap-md sm:grid-cols-3"
                    reset-on-success
                    @input="resultDirty = true"
                    @change="resultDirty = true"
                    @success="resultDirty = false"
                >
                    <input type="hidden" name="type" value="result" />
                    <UiInput type="datetime-local" name="sent_at" label="Ngày gửi KQ phụ huynh" required :value="nowInput" :max="nowInput" />
                    <div class="sm:col-span-2">
                        <UiTextarea name="content" label="Phản hồi của phụ huynh" :rows="2" placeholder="Nhập ý kiến phản hồi của phụ huynh..." />
                    </div>
                    <div class="flex justify-end sm:col-span-3">
                        <!-- Nút lưu trong khối để kiểu phụ, chỉ tô cam khi form đang được sửa -->
                        <UiButton type="submit" variant="secondary" size="sm" icon="forward_to_inbox" :class="resultDirty ? dirtySave : ''">Lưu gửi kết quả</UiButton>
                    </div>
                </UiForm>
                <p v-else-if="!hasResultLogs" class="font-body-small text-body-small text-on-surface-variant">Chưa có kết quả test để gửi phụ huynh.</p>
            </template>
        </section>

        <!-- Học thử: đặt / hủy buổi + nhận xét của GV ngay trong khối (A6 Q1: lưu theo khách) -->
        <div v-if="!trialBookings.length && !canBookTrial" :class="collapsedRow">
            <span class="flex items-center gap-sm"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">school</span>Học thử</span>
            <span class="font-caption text-caption">{{ trialNote }}</span>
        </div>
        <section v-else class="space-y-md border-t border-surface-container-highest pt-lg">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-secondary">school</span>Học thử
                </h4>
                <div v-if="canBookTrial" class="flex items-center gap-sm">
                    <span class="font-caption text-caption text-on-surface-variant">Đã học thử {{ trial.used }}/{{ trial.max }} buổi</span>
                    <UiButton variant="secondary" size="sm" icon="event_available" @click="emit('open', 'trial')">{{ trial.used > 0 ? 'Xếp buổi học thử ' + (trial.used + 1) : 'Xếp học thử' }}</UiButton>
                </div>
                <span v-else-if="trial.exhausted" class="font-caption text-caption font-semibold text-on-surface-variant">Đã học thử đủ {{ trial.max }} buổi · khóa xếp học thử</span>
            </div>
            <div v-for="booking in trialBookings" :key="booking.id" class="rounded-lg border border-surface-container-highest bg-surface-container-low p-md font-body-small text-body-small">
                <div class="flex flex-wrap items-center justify-between gap-sm">
                    <span class="font-semibold text-on-surface">Học thử · {{ booking.class }} · {{ booking.date }} {{ booking.time }}</span>
                    <UiBadge :color="bookingTone(booking.status)">{{ booking.status_label }}</UiBadge>
                </div>
                <p class="mt-xs font-label text-label uppercase text-on-surface-variant">Nhận xét học thử</p>
                <template v-if="booking.has_feedback">
                    <p class="text-on-surface">{{ booking.feedback }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">{{ booking.feedback_by }} · {{ booking.feedback_at }}</p>
                </template>
                <p v-else class="italic text-on-surface-variant">Chưa có nhận xét từ buổi học thử.</p>
                <UiForm v-if="booking.status === 'scheduled' && stageControls.canCancelTrial" :action="route('crm.customers.trial-bookings.cancel', [customer.id, booking.id])" method="post" class="mt-sm flex gap-xs">
                    <div class="flex-1"><UiInput :id="'cancel_reason_' + booking.id" name="reason" required placeholder="Lý do hủy" aria-label="Lý do hủy" class="py-1 font-body-small text-body-small" /></div>
                    <UiButton type="submit" variant="danger-text" size="sm">Hủy buổi</UiButton>
                </UiForm>
            </div>
            <div v-if="!trialBookings.length" class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small">
                <p class="font-label text-label uppercase text-on-surface-variant">Nhận xét học thử</p>
                <p class="italic text-on-surface-variant">Chưa có nhận xét từ buổi học thử.</p>
            </div>
        </section>
    </div>
</template>
