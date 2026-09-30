<script setup>
/**
 * Bài nộp của lớp (góc nhìn giáo viên): chọn lớp dạy, lọc theo loại bài, chấm bài phát âm / đánh dấu đã xem.
 */
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Bài nộp của lớp' } });

const props = defineProps({
    currentClass: { type: Object, default: null },
    classes: { type: Array, default: () => [] },
    activeTab: { type: String, default: 'video' },
    submissions: { type: Array, default: () => [] },
});

const TYPES = {
    video: { label: 'Quay video', icon: 'videocam' },
    vocabulary: { label: 'Viết từ vựng', icon: 'edit_document' },
    workbook: { label: 'Workbook', icon: 'menu_book' },
    extra_book: { label: 'Sách bổ trợ', icon: 'library_books' },
    bgd_book: { label: 'Sách bộ giáo dục', icon: 'import_contacts' },
    quiz: { label: 'Quiz', icon: 'quiz' },
    pronunciation: { label: 'Phát âm', icon: 'mic' },
};

const inputClass = 'rounded-lg border-outline-variant bg-surface-container-lowest text-xs py-1.5 text-on-surface focus:border-primary-container focus:ring-primary-container/50';

function changeClass(event) {
    const value = event?.target ? event.target.value : event;
    router.visit(route('portal.teacher.submissions') + '/' + value);
}

const initials = (name) => Array.from(name ?? 'HV').slice(0, 2).join('');
const filled = (v) => v !== null && v !== undefined && String(v).trim() !== '';
</script>

<template>
    <UiPageHeader title="Bài nộp của lớp" icon="video_library" :back="route('syllabus.teacher-view')">
        <!-- Cổng học viên chỉ mở cho người xem được hồ sơ học viên (tránh nút dẫn tới 403). -->
        <template v-if="can('student.view')" #actions>
            <UiButton variant="secondary" icon="upload_file" :href="route('portal.student.homework')">Xem giao diện Học sinh nộp bài</UiButton>
        </template>
    </UiPageHeader>

    <div class="mx-auto max-w-5xl space-y-6">
        <!-- Chọn lớp -->
        <div class="flex flex-col justify-between gap-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm sm:flex-row sm:items-center">
            <div>
                <h2 class="text-xl font-bold text-on-surface">Bài nộp của lớp</h2>
                <p class="mt-0.5 text-xs text-on-surface-variant">Lớp: <strong class="text-on-surface">{{ props.currentClass?.name ?? 'Chưa có lớp' }}</strong></p>
            </div>

            <UiSelect inline-label="Chọn lớp dạy:" :value="props.currentClass?.id ?? ''" @change="changeClass">
                <option v-for="c in classes" :key="c.id" :value="c.id" :selected="props.currentClass && props.currentClass.id === c.id">{{ c.name }} ({{ c.code }})</option>
            </UiSelect>
        </div>

        <!-- Lọc theo loại bài -->
        <div class="flex flex-wrap gap-2">
            <Link
                v-for="(v, k) in TYPES"
                :key="k"
                :href="route('portal.teacher.submissions', { classId: props.currentClass?.id ?? null, type: k })"
                :class="['flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold transition', activeTab === k ? 'bg-primary-container text-white shadow-2xs' : 'border border-surface-container-highest bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low']"
            >
                <span class="material-symbols-outlined text-[16px]">{{ v.icon }}</span>
                <span>{{ v.label }}</span>
            </Link>
        </div>

        <!-- Danh sách đã nộp -->
        <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low px-5 py-4">
                <h3 class="flex items-center gap-2 text-sm font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">check_circle</span>
                    Danh sách đã nộp bài ({{ submissions.length }})
                </h3>
            </div>

            <div class="divide-y divide-surface-container-highest">
                <div v-for="sub in submissions" :key="sub.id" class="flex flex-col justify-between gap-4 p-4 transition hover:bg-primary-container/10 md:flex-row md:items-center">
                    <div class="flex flex-1 items-center gap-3">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-primary-container/20 bg-primary-container/10 text-sm font-bold text-primary">
                            {{ initials(sub.student_name) }}
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface">{{ sub.student_name ?? 'Học viên' }}</h4>
                            <p class="mt-0.5 flex items-center gap-1 font-mono text-xs text-on-surface-subtle">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                nộp lúc {{ sub.submitted_at }}
                            </p>
                            <p v-if="sub.notes" class="mt-1 text-xs italic text-on-surface-variant">"{{ sub.notes }}"</p>
                        </div>
                    </div>

                    <!-- Bài phát âm: nghe trực tiếp bản ghi của học viên -->
                    <div v-if="sub.audio_path" class="flex-1 text-xs">
                        <span class="block max-w-[220px] truncate font-bold text-on-surface">{{ sub.unit_title ?? 'Bản ghi âm' }}</span>
                        <audio controls preload="none" class="mt-1 h-8 w-full max-w-[260px]" :src="sub.audio_path"></audio>
                    </div>
                    <div v-else class="flex flex-1 items-center gap-3">
                        <div class="relative flex h-12 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-inverse-surface text-white shadow-2xs">
                            <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                        </div>
                        <div class="text-xs">
                            <span class="block max-w-[180px] truncate font-bold text-on-surface">
                                <a v-if="sub.attachment_path" :href="sub.attachment_path" target="_blank" rel="noopener" class="hover:underline">{{ sub.attachment_name ?? 'Tệp đính kèm' }}</a>
                                <template v-else>Không có tệp đính kèm</template>
                            </span>
                            <span class="text-xs font-bold uppercase text-primary">{{ sub.homework_label ?? 'Bài nộp' }}</span>
                        </div>
                    </div>

                    <!-- Trạng thái & thao tác -->
                    <div class="flex items-center gap-3">
                        <UiBadge v-if="sub.status === 'reviewed'" color="success" pill :dot="false">
                            <span class="material-symbols-outlined mr-0.5 text-[14px]">done_all</span>
                            {{ filled(sub.score) ? 'Đã chấm: ' + sub.score : 'Đã xem' }}
                        </UiBadge>
                        <UiForm v-else-if="activeTab === 'pronunciation'" :action="route('portal.teacher.submissions.mark', { id: sub.id })" method="post" class="flex items-center gap-2">
                            <input type="text" name="score" required maxlength="20" placeholder="Điểm /100" :class="['w-24', inputClass]" />
                            <input type="text" name="feedback" maxlength="1000" placeholder="Nhận xét" :class="['w-40', inputClass]" />
                            <UiButton type="submit" size="sm">Chấm</UiButton>
                        </UiForm>
                        <template v-else>
                            <UiBadge color="secondary" pill>Đã nộp</UiBadge>
                            <UiForm :action="route('portal.teacher.submissions.mark', { id: sub.id })" method="post" class="flex items-center gap-2">
                                <input type="text" name="score" maxlength="20" placeholder="Điểm (tùy chọn)" :class="['w-24', inputClass]" />
                                <input type="text" name="feedback" maxlength="1000" placeholder="Nhận xét" :class="['w-36', inputClass]" />
                                <UiButton type="submit" variant="secondary" size="sm" icon="visibility">
                                    <span>Đánh dấu đã xem</span>
                                </UiButton>
                            </UiForm>
                        </template>
                    </div>
                </div>

                <UiEmptyState v-if="!submissions.length" icon="inbox" :title="props.currentClass ? 'Chưa có học viên nào nộp bài loại này.' : 'Bạn chưa được phân công lớp nào.'" />
            </div>
        </div>
    </div>
</template>
