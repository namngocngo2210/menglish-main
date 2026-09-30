<script setup>
/** Chi tiết đề test: số liệu đề, danh sách thí sinh đã thi (điểm, phiếu điểm, chấm bài) và toàn bộ câu hỏi + đáp án chuẩn. */
import { Link } from '@inertiajs/vue3';
import { toast } from '@/lib/toast';

defineOptions({ layout: (props) => ({ title: props.test.title }) });

const props = defineProps({
    test: { type: Object, required: true },
    takeUrl: { type: String, required: true },
    submissions: { type: Array, default: () => [] },
});

const skillClasses = {
    listening: 'bg-secondary/10 text-secondary border border-secondary/30',
    reading: 'bg-tertiary/10 text-tertiary border border-tertiary/30',
    grammar: 'bg-info/10 text-info border border-info/30',
    writing: 'bg-accent-container text-accent border border-accent/30',
    speaking: 'bg-error/10 text-error border border-error/30',
};
const ucfirst = (text) => (text ? text.charAt(0).toUpperCase() + text.slice(1) : text);
const basename = (url) => String(url).split(/[\\/]/).pop();

function copyLink() {
    navigator.clipboard.writeText(props.takeUrl);
    toast('Đã sao chép link làm bài thi.');
}
</script>

<template>
    <UiPageHeader :title="test.title" :back="route('placement-tests.index')">
        <template #badges>
            <span class="rounded-md bg-secondary/10 px-2 py-0.5 font-mono text-xs font-bold text-secondary">{{ test.code }}</span>
            <span v-if="test.is_preset" class="flex items-center gap-1 rounded-md border border-surface-container-highest bg-surface-container px-2 py-0.5 text-xs font-bold text-on-surface-variant">
                <span class="material-symbols-outlined text-[12px]">lock</span>
                <span>Đề mẫu hệ thống (Khóa sửa)</span>
            </span>
            <span v-else class="flex items-center gap-1 rounded-md border border-tertiary/30 bg-tertiary/10 px-2 py-0.5 text-xs font-bold text-tertiary">
                <span class="material-symbols-outlined text-[12px]">edit</span>
                <span>Đề tạo tay (Tùy biến)</span>
            </span>
        </template>
        <template #actions>
            <!-- Copy Portal Link -->
            <UiButton variant="secondary" size="sm" icon="content_copy" @click="copyLink">Sao chép Link</UiButton>
            <!-- Open Portal -->
            <UiButton size="sm" icon="open_in_new" :href="route('portal.test.take', test.code)" target="_blank">Cổng làm bài</UiButton>
            <UiButton v-if="!test.is_preset && can('placement_test.update')" variant="success" size="sm" icon="edit" :href="route('placement-tests.edit', test.id)">Sửa đề</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- Overview Stats Card -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <UiStatCard label="Cấp độ mục tiêu" :value="test.target_level" />
            <UiStatCard label="Thời lượng làm bài" :value="`${test.duration_minutes} phút`" tone="secondary" />
            <UiStatCard label="Tổng số câu hỏi" :value="`${test.questions_count} câu`" tone="primary" />
            <a href="#submissions-list" class="block rounded-xl transition hover:ring-2 hover:ring-tertiary/30">
                <UiStatCard label="Lượt thí sinh đã thi" :value="`${submissions.length} lượt`" tone="success" icon="arrow_downward" hint="(Bấm xem điểm)" />
            </a>
        </div>

        <!-- Candidate Submissions & Scores Table for this Test -->
        <UiDataTable id="submissions-list" class="scroll-mt-6">
            <template #header>
                <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                    <span class="material-symbols-outlined text-base text-tertiary">fact_check</span>
                    <span>Danh Sách Thí Sinh Đã Thi Bộ Đề Này ({{ submissions.length }})</span>
                </span>
                <span class="text-xs text-on-surface-subtle">Điểm số chi tiết 4 kỹ năng &amp; Khóa học xếp lớp</span>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Thí sinh</th>
                        <th>Số điện thoại</th>
                        <th class="text-center">Listening</th>
                        <th class="text-center">Reading</th>
                        <th class="text-center">Writing</th>
                        <th class="text-center">Speaking</th>
                        <th class="text-center">Overall (Band)</th>
                        <th>Khóa học đề xuất</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sub in submissions" :key="sub.id">
                        <td>
                            <div class="flex items-center gap-1.5 font-bold text-on-surface">
                                <span>{{ sub.candidate_name }}</span>
                                <Link v-if="sub.customer_id" :href="route('crm.customers.show', sub.customer_id)" class="py-0.2 inline-flex items-center gap-0.5 rounded bg-secondary/10 px-1.5 text-xs font-normal text-secondary hover:bg-secondary/20" title="Mở hồ sơ Lead trong CRM">
                                    <span>Lead CRM</span>
                                    <span class="material-symbols-outlined text-xs">open_in_new</span>
                                </Link>
                            </div>
                            <div class="mt-0.5 font-mono text-xs text-on-surface-subtle">
                                {{ sub.created_at ? formatDate(sub.created_at, 'H:i d/m/Y') : 'Vừa xong' }}
                            </div>
                        </td>
                        <td class="font-mono text-on-surface-variant">{{ sub.candidate_phone }}</td>
                        <td class="text-center font-mono font-bold text-secondary">{{ sub.listening_score }}</td>
                        <td class="text-center font-mono font-bold text-tertiary">{{ sub.reading_score }}</td>
                        <td class="text-center font-mono font-bold text-accent">{{ sub.writing_score }}</td>
                        <td class="text-center font-mono font-bold text-error">{{ sub.speaking_score }}</td>
                        <td class="text-center">
                            <UiBadge color="primary" pill :dot="false" class="font-mono font-black">{{ sub.is_pending ? 'Chờ chấm' : (sub.score_summary ?? '—') }}</UiBadge>
                        </td>
                        <td class="font-semibold text-primary">{{ sub.recommended_course }}</td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <UiButton variant="secondary" size="sm" icon="description" :href="sub.scorecard_url" target="_blank">Phiếu điểm</UiButton>
                                <UiButton v-if="can('placement_test.grade')" variant="ghost" size="sm" icon="edit_note" :href="route('placement-tests.results.show', sub.id)">{{ sub.is_pending ? 'Chấm bài' : 'Chấm lại' }}</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!submissions.length">
                        <td colspan="9"><UiEmptyState icon="assignment_late" title="Chưa có thí sinh nào nộp bài thi cho bộ đề này." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <!-- Question List View -->
        <div class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-2xs md:p-6">
            <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                <div>
                    <h2 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                        <span class="material-symbols-outlined text-base text-secondary">format_list_numbered</span>
                        <span>Chi Tiết Toàn Bộ Câu Hỏi &amp; Đáp Án Chuẩn (Answer Keys)</span>
                    </h2>
                    <p class="text-xs text-on-surface-variant">Giáo viên và Học vụ có thể xem trước nội dung, hình ảnh, audio và đáp án đối soát.</p>
                </div>
            </div>

            <div class="space-y-5">
                <div v-for="(q, idx) in test.questions" :key="idx" class="space-y-3 rounded-xl border border-surface-container-highest/90 bg-surface-container-low/40 p-4">
                    <!-- Question Header -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="rounded-md bg-inverse-surface px-2 py-0.5 font-mono text-xs font-bold text-white">Câu {{ idx + 1 }}</span>
                            <span :class="['rounded-md px-2 py-0.5 text-xs font-bold', skillClasses[q.skill] ?? 'bg-surface-container text-on-surface-variant']">{{ ucfirst(q.skill ?? 'General') }}</span>
                            <span class="font-mono text-xs text-on-surface-subtle">({{ q.type ?? 'multiple_choice' }})</span>
                        </div>
                        <span class="font-mono text-xs font-semibold text-on-surface-variant">{{ q.points ?? 1 }} điểm</span>
                    </div>

                    <!-- Question Title -->
                    <div class="text-xs font-bold leading-snug text-on-surface md:text-sm">{{ q.title ?? '' }}</div>

                    <!-- Passage if exists -->
                    <div v-if="q.passage" class="rounded-xl border border-tertiary/20 bg-tertiary/5 p-3 text-xs font-medium italic leading-relaxed text-on-surface">
                        <strong>Đoạn văn / Ngữ cảnh:</strong> "{{ q.passage }}"
                    </div>

                    <!-- Audio player if exists -->
                    <div v-if="q.audio_url" class="space-y-2 rounded-xl border border-secondary/30 bg-secondary/10 p-3 shadow-2xs">
                        <div class="flex items-center justify-between text-xs font-bold text-on-secondary-fixed">
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base text-secondary">volume_up</span>
                                <span>File Audio Listening: {{ basename(q.audio_url) }}</span>
                            </div>
                            <a :href="q.audio_url" target="_blank" class="flex items-center gap-0.5 text-xs font-normal text-secondary hover:underline">
                                <span>Tải file</span>
                                <span class="material-symbols-outlined text-[12px]">download</span>
                            </a>
                        </div>
                        <audio controls class="h-8 w-full" preload="none">
                            <source :src="q.audio_url" type="audio/mpeg" />
                            Trình duyệt không hỗ trợ audio.
                        </audio>
                    </div>

                    <!-- Illustration Image if exists -->
                    <div v-if="q.image_url" class="mx-auto my-2 max-w-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2">
                        <img :src="q.image_url" alt="Question illustration" class="mx-auto max-h-56 rounded-lg object-contain" />
                    </div>

                    <!-- Options / Choices -->
                    <div v-if="q.options?.length" :class="['grid grid-cols-1 gap-2 text-xs', q.options.length > 2 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2']">
                        <div
                            v-for="opt in q.options"
                            :key="opt.key"
                            :class="['flex flex-col justify-between rounded-xl border p-2.5', String(opt.key ?? '') === String(q.correct_answer ?? '') ? 'border-tertiary/30 bg-tertiary/10 ring-2 ring-tertiary/30' : 'border-surface-container-highest bg-surface-container-lowest']"
                        >
                            <div class="mb-1 flex items-center justify-between">
                                <div class="flex items-center gap-1.5">
                                    <span :class="['w-4.5 h-4.5 flex shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold', String(opt.key ?? '') === String(q.correct_answer ?? '') ? 'bg-tertiary text-white' : 'bg-surface-container text-on-surface-variant']">{{ opt.key }}</span>
                                    <span class="text-xs font-medium text-on-surface">{{ opt.text }}</span>
                                </div>
                                <span v-if="String(opt.key ?? '') === String(q.correct_answer ?? '')" class="py-0.2 flex items-center gap-0.5 rounded bg-tertiary px-1.5 text-xs font-bold text-white">
                                    <span class="material-symbols-outlined text-xs">check</span>
                                    <span>Đúng</span>
                                </span>
                            </div>
                            <img v-if="opt.image_url" :src="opt.image_url" :alt="opt.key" class="mx-auto mt-1.5 max-h-28 rounded-lg border border-surface-container-highest bg-surface-container-low object-contain p-1" />
                        </div>
                    </div>

                    <!-- Fill blank answer or Explanation -->
                    <div v-if="q.correct_answer" class="flex items-center justify-between rounded-xl border border-warning/30 bg-warning/5 p-2.5 text-xs">
                        <div>
                            <span class="font-bold text-on-warning-container">Đáp án chuẩn (Key):</span>
                            <span class="ml-1 font-mono text-xs font-black text-on-warning-container">{{ q.correct_answer }}</span>
                            <span v-if="q.explanation" class="ml-2 text-xs italic text-on-surface-variant">({{ q.explanation }})</span>
                        </div>
                    </div>

                    <!-- Speaking Cue Points -->
                    <div v-if="q.cue_points" class="whitespace-pre-line rounded-xl border border-error/30 bg-error/10 p-2.5 font-mono text-xs text-on-error-container"><strong>Gợi ý phỏng vấn Speaking:</strong>
{{ q.cue_points }}</div>
                </div>
                <UiEmptyState v-if="!test.questions.length" icon="quiz" title="Đề thi chưa có câu hỏi nào." />
            </div>
        </div>
    </div>
</template>
