<script setup>
/**
 * Ô trạng thái đầu màn báo cáo ngày (thiết kế 27/10/2026), thay các dải thông báo xếp chồng: trạng thái nộp của ngày đang xem
 * (chưa nộp / quá hạn / đã nộp / cần bổ sung / đã kiểm tra) kèm việc làm tiếp, dãy 7 ngày gần nhất (bấm để mở ngày đó)
 * cùng các ngày còn thiếu, và thời điểm tính số liệu + nút "Cập nhật số liệu".
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { submitStatus, timeLeft } from '@/lib/dailyReport';

const props = defineProps({
    date: { type: String, required: true },
    deadline: { type: String, default: null },
    deadlineAt: { type: String, default: null },
    week: { type: Array, default: () => [] },
    missingDays: { type: Array, default: () => [] },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    submittedAt: { type: String, default: null },
    firstSubmittedAt: { type: String, default: null },
    late: { type: Boolean, default: false },
    review: { type: Object, default: null },
});
defineEmits(['refresh']);

const status = computed(() => submitStatus({ submittedAt: props.submittedAt, late: props.late, review: props.review, deadlineAt: props.deadlineAt }));
const left = computed(() => timeLeft(props.deadlineAt));
const missing = computed(() => props.missingDays.filter((d) => d.date !== props.date));
const DOT = { submitted: 'bg-tertiary', late: 'bg-warning', missing: 'bg-error', off: 'bg-surface-variant', open: 'border-2 border-primary bg-surface-container-lowest' };
const STATE = { submitted: 'Đã nộp', late: 'Nộp trễ', missing: 'Chưa nộp', off: 'Không phải nộp', open: 'Đang mở' };
</script>

<template>
    <section
        class="grid grid-cols-1 overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm lg:grid-cols-[minmax(220px,1fr)_minmax(320px,2fr)_minmax(200px,1fr)]"
        aria-label="Trạng thái báo cáo"
        data-daily-status
        :data-status="status.key"
    >
        <div class="flex flex-col gap-xs border-b border-surface-variant p-md lg:border-b-0 lg:border-r lg:p-lg">
            <UiBadge :color="status.color" class="self-start">{{ status.label }}</UiBadge>
            <template v-if="status.key === 'open'">
                <p class="font-h3 text-h3 text-on-surface">Hạn nộp {{ deadline }}</p>
                <p v-if="left" class="font-body-small text-body-small text-on-surface-variant">{{ left }}</p>
            </template>
            <template v-else-if="status.key === 'overdue'">
                <p class="font-h3 text-h3 text-error">Đã quá hạn {{ deadline }}</p>
                <p class="font-body-small text-body-small text-on-surface-variant">Vẫn nộp được, báo cáo sẽ ghi là nộp trễ.</p>
            </template>
            <template v-else-if="status.key === 'returned'">
                <p class="font-h3 text-h3 text-on-surface" data-report-returned>{{ review.by ?? 'Admin' }} yêu cầu bổ sung</p>
                <p v-if="review.note" class="whitespace-pre-line rounded-lg bg-surface-container-low px-sm py-xs font-body-small text-body-small text-on-surface">{{ review.note }}</p>
                <p class="font-body-small text-body-small text-on-surface-variant">Sửa báo cáo rồi bấm Nộp lại.</p>
            </template>
            <template v-else-if="status.key === 'reviewed'">
                <p class="font-h3 text-h3 text-on-surface" data-report-reviewed>{{ review.by ?? 'Admin' }} đã kiểm tra</p>
                <p v-if="review.at" class="font-body-small text-body-small text-on-surface-variant">Lúc {{ formatDate(review.at, 'H:i d/m/Y') }}</p>
            </template>
            <template v-else>
                <p class="font-h3 text-h3 text-on-surface" data-daily-submitted>Nộp lúc {{ formatDate(firstSubmittedAt ?? submittedAt, 'H:i d/m') }}</p>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    <template v-if="firstSubmittedAt && firstSubmittedAt !== submittedAt">Sửa lần cuối {{ formatDate(submittedAt, 'H:i d/m') }}. </template>
                    <template v-if="late">Nộp sau hạn {{ deadline }}.</template>
                </p>
            </template>
        </div>

        <div class="flex flex-col gap-sm border-b border-surface-variant p-md lg:border-b-0 lg:border-r lg:p-lg">
            <p class="font-label text-label uppercase text-on-surface-variant">7 ngày gần đây</p>
            <ol class="grid grid-cols-7 gap-xs" data-daily-week>
                <li v-for="d in week" :key="d.date">
                    <Link
                        :href="d.href"
                        :aria-current="d.date === date ? 'date' : undefined"
                        :aria-label="`${d.weekday} ${d.day}: ${STATE[d.status]}`"
                        :data-week-day="d.status"
                        :class="[
                            'flex min-h-[56px] flex-col items-center justify-center gap-0.5 rounded-lg border transition-colors hover:border-outline-variant',
                            d.date === date ? 'border-2 border-primary bg-primary-container/10' : d.status === 'missing' ? 'border-error/60 bg-error/5' : d.status === 'off' ? 'border-surface-variant bg-surface-container-low' : 'border-surface-variant bg-surface-container-lowest',
                        ]"
                    >
                        <span :class="['font-caption text-caption', d.status === 'missing' ? 'text-error' : 'text-on-surface-variant']">{{ d.weekday }}</span>
                        <span :class="['font-body-semibold text-body-semibold', d.status === 'off' ? 'text-on-surface-subtle' : d.status === 'missing' ? 'text-error' : 'text-on-surface']">{{ d.day.slice(0, 2) }}</span>
                        <span :class="['h-2 w-2 rounded-full', DOT[d.status]]" aria-hidden="true"></span>
                    </Link>
                </li>
            </ol>
            <p v-if="missing.length" class="font-body-small text-body-small text-error" data-daily-missing>
                <strong>Còn thiếu</strong>{{ ' ' }}
                <template v-for="(d, i) in missing.slice(0, 6)" :key="d.date">
                    <Link :href="d.href" class="font-semibold underline">{{ d.label }}</Link><template v-if="i < Math.min(missing.length, 6) - 1">, </template>
                </template><template v-if="missing.length > 6"> và {{ missing.length - 6 }} ngày khác</template>. Ngày không nộp tính là nộp trễ.
            </p>
            <p v-else class="font-body-small text-body-small text-on-surface-variant">Không còn ngày nào thiếu trong 14 ngày qua.</p>
        </div>

        <div class="flex flex-col justify-center gap-xs p-md lg:p-lg">
            <p class="font-label text-label uppercase text-on-surface-variant">Số liệu hệ thống</p>
            <p class="font-body-medium text-body-medium text-on-surface" data-computed-at>Tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m/Y') : '—' }}</p>
            <p v-if="submittedAt" class="font-body-small text-body-small text-on-surface-variant">
                {{ live ? 'Vừa tính lại, bấm Lưu để cập nhật báo cáo bằng số mới.' : 'Là số lúc nộp, sửa ghi chú không làm đổi số.' }}
            </p>
            <UiButton type="button" variant="secondary" size="sm" icon="refresh" class="self-start" data-refresh-metrics @click="$emit('refresh')">Cập nhật số liệu</UiButton>
        </div>
    </section>
</template>
