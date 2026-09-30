<script setup>
/**
 * Mockup 03_Cong_Giao_Vien/08: 3 tab Tài liệu / Tổng quan syllabus / Nội dung buổi học.
 * Tài liệu: bấm dòng → xem trong hộp thoại (?document=; đóng thì bỏ query). Buổi học: bấm dòng → hộp thoại nội dung buổi.
 */
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Xem tài liệu giáo trình' } });

const props = defineProps({
    documents: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    listUrl: { type: String, required: true },
    search: { type: String, default: '' },
    initialTab: { type: String, default: 'docs' },
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    userEmail: { type: String, default: '' },
    overviewCurriculum: { type: String, default: null },
    overviewStages: { type: Array, default: () => [] },
    stages: { type: Array, default: () => [] },
    assignment: { type: Object, default: null },
    position: { type: Object, default: null },
    stageUnits: { type: Array, default: () => [] },
});

const tabs = { docs: 'Tài liệu', overview: 'Tổng quan syllabus', lessons: 'Nội dung buổi học' };
const tab = ref(props.initialTab);

const viewerOpen = ref(!!props.selected);
watch(() => props.selected?.id, (id) => (viewerOpen.value = !!id));
const viewer = ref(null);
const fileUrl = computed(() => (props.selected ? route('syllabus.documents.file', props.selected.id) : ''));
function fullscreen() {
    viewer.value?.requestFullscreen?.();
}

const lesson = ref(null);
const lessonFields = { objectives: 'Mục tiêu', vocabulary_focus: 'Từ vựng', grammar_focus: 'Ngữ pháp', content: 'Hoạt động', homework_guide: 'Bài tập về nhà' };

const badgeColor = { open: 'primary', done: 'success', todo: 'neutral' };
const badgeLabel = { open: 'Đang học', done: 'Đã xong', todo: 'Chưa mở' };
const chipClass = {
    open: 'border-primary-container bg-primary-container/10 text-primary font-bold',
    done: 'border-tertiary/30 bg-tertiary/10 text-tertiary',
    todo: 'border-surface-container-highest text-on-surface-subtle',
};
const chipIcon = { open: 'play_circle', done: 'check_circle', todo: 'lock' };
</script>

<template>
    <UiPageHeader title="Xem tài liệu giáo trình" :back="route('syllabus.documents')">
        <template #breadcrumbs>
            <span class="material-symbols-outlined text-[16px]">menu_book</span>
            <span>Giáo trình &amp; Tài liệu</span>
            <template v-if="overviewCurriculum">
                <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                <span class="font-semibold text-on-surface">{{ overviewCurriculum }}</span>
            </template>
        </template>
        <template #actions>
            <GetForm :action="route('syllabus.teacher-view')" class="flex items-center gap-2">
                <input v-if="classId" type="hidden" name="class" :value="classId" />
                <UiInput name="q" icon="search" :value="search" placeholder="Tìm kiếm tài liệu..." />
                <UiButton type="submit" variant="secondary" icon="filter_list">Lọc</UiButton>
            </GetForm>
            <UiButton variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Đề xuất sửa</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <nav class="no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest" role="tablist">
            <button
                v-for="(label, key) in tabs"
                :key="key"
                type="button"
                role="tab"
                :aria-selected="tab === key"
                :class="['-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-md font-body-medium text-body-medium transition-colors', tab === key ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary']"
                @click="tab = key"
            >
                {{ label }}
            </button>
        </nav>

        <!-- Tab 1: Tài liệu -->
        <div v-show="tab === 'docs'">
            <UiDataTable min-width="760px">
                <template #header>
                    <h2 class="font-h3 text-h3 text-on-surface">Danh sách tài liệu</h2>
                    <UiBadge color="primary" :dot="false" pill>{{ documents.length }} tài liệu</UiBadge>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Tài liệu</th>
                            <th>Giáo trình · Chặng</th>
                            <th>Định dạng</th>
                            <th>Quyền</th>
                            <th>Đã xem</th>
                            <th class="text-right"><span class="sr-only">Thao tác</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="doc in documents" :key="doc.id" :data-href="doc.url" :class="['cursor-pointer', { 'bg-primary-fixed/30': selected?.id === doc.id }]">
                            <td>
                                <Link :href="doc.url" class="flex items-center gap-sm font-semibold text-on-surface hover:text-primary">
                                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">{{ doc.icon }}</span>
                                    <span class="truncate">{{ doc.title }}</span>
                                </Link>
                            </td>
                            <td class="text-on-surface-variant">{{ doc.place || '—' }}</td>
                            <td class="whitespace-nowrap font-code text-body-small uppercase">{{ doc.extension }} · {{ doc.size_human }}</td>
                            <td class="whitespace-nowrap">
                                <UiBadge v-if="doc.can_download" color="success">Có thể tải</UiBadge>
                                <UiBadge v-else color="error">Chỉ xem</UiBadge>
                            </td>
                            <td class="whitespace-nowrap">
                                <span v-if="doc.viewed" class="inline-flex items-center gap-xs text-tertiary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>Đã xem</span>
                                <span v-else class="text-on-surface-variant">Chưa xem</span>
                            </td>
                            <td class="text-right"><UiButton variant="secondary" size="sm" icon="visibility" :href="doc.url">Xem</UiButton></td>
                        </tr>
                        <tr v-if="!documents.length">
                            <td colspan="6"><UiEmptyState icon="folder_off" :title="search !== '' ? 'Không tìm thấy tài liệu phù hợp' : 'Chưa có tài liệu nào'" description="Học thuật chưa chia sẻ tài liệu nào cho vai trò của bạn." /></td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </div>

        <!-- Tab 2: Tổng quan syllabus — lộ trình các chặng của giáo trình -->
        <section v-show="tab === 'overview'" class="space-y-md">
            <h2 class="font-h2 text-h2 text-on-surface">Lộ trình tổng quát{{ overviewCurriculum ? ' — ' + overviewCurriculum : '' }}</h2>
            <UiEmptyState v-if="!overviewStages.length" icon="route" title="Chưa có lộ trình" description="Học thuật chưa soạn chặng cho giáo trình này." />
            <div v-else class="grid grid-cols-1 gap-md md:grid-cols-3">
                <article
                    v-for="s in overviewStages"
                    :key="s.id"
                    :class="['flex flex-col gap-sm rounded-xl border bg-surface-container-lowest p-lg shadow-sm', s.state === 'open' ? 'border-primary-container ring-1 ring-primary-container/20' : 'border-outline-variant']"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-h3 text-h3 text-on-surface">{{ s.label }}</h3>
                        <UiBadge v-if="assignment" :color="badgeColor[s.state]">{{ badgeLabel[s.state] }}</UiBadge>
                    </div>
                    <p class="flex-1 font-body-small text-body-small text-on-surface-variant">{{ s.summary }}</p>
                    <a v-if="s.overview_link" :href="s.overview_link" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-body-medium text-body-small text-primary hover:underline">Xem mục lục chặng <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
                    <button v-else type="button" class="inline-flex items-center gap-1 self-start font-body-medium text-body-small text-primary hover:underline" @click="tab = 'lessons'">Xem mục lục chặng <span class="material-symbols-outlined text-[16px]">arrow_forward</span></button>
                </article>
            </div>
        </section>

        <!-- Tab 3: Nội dung buổi học — chặng đang mở của lớp: Chặng → Unit → Buổi, buổi đang dạy -->
        <section v-show="tab === 'lessons'" class="space-y-4 rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="flex items-center gap-2 font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary">list_alt</span>
                    {{ assignment?.has_stage ? assignment.stage_label : 'Nội dung giảng dạy theo chặng' }}
                </h2>
                <GetForm v-if="classes.length" :action="route('syllabus.teacher-view')">
                    <input v-if="selected" type="hidden" name="document" :value="selected.id" />
                    <input type="hidden" name="tab" value="lessons" />
                    <UiSelect name="class" inline-label="Lớp" :value="classId" :options="classes" />
                </GetForm>
            </div>

            <UiEmptyState v-if="!assignment" icon="school" title="Chưa có lớp nào đang học chặng" description="Học thuật mở chặng cho lớp ở màn Giao chặng; nội dung buổi học sẽ hiện ở đây." />
            <template v-else>
                <ol class="flex flex-wrap gap-2 text-xs">
                    <li v-for="s in stages" :key="s.id" :class="['rounded-lg border px-2.5 py-1', chipClass[s.state]]">
                        <span class="material-symbols-outlined align-middle text-[13px]">{{ chipIcon[s.state] }}</span>
                        {{ s.label }}
                    </li>
                </ol>

                <div class="grid grid-cols-1 gap-3 text-xs md:grid-cols-3">
                    <div class="rounded-xl border border-surface-container-highest p-3">
                        <p class="text-xs font-bold uppercase text-on-surface-subtle">Chặng đang học</p>
                        <p class="font-bold text-on-surface">{{ assignment.stage_label }}</p>
                        <p class="text-on-surface-variant">{{ assignment.curriculum }} · mở {{ assignment.opened_at }}</p>
                    </div>
                    <div class="rounded-xl border border-surface-container-highest p-3">
                        <p class="text-xs font-bold uppercase text-on-surface-subtle">Tiến độ</p>
                        <p class="font-bold text-on-surface">Đã dạy {{ position.taught }} / {{ position.lessons }} buổi của chặng</p>
                        <p v-if="assignment.extra_sessions" class="text-warning">+{{ assignment.extra_sessions }} buổi giãn tiến độ đã duyệt</p>
                    </div>
                    <div class="rounded-xl border border-surface-container-highest p-3">
                        <p class="text-xs font-bold uppercase text-on-surface-subtle">Big Test cuối chặng</p>
                        <p class="font-bold text-secondary">{{ assignment.big_test_title }}</p>
                        <p class="text-on-surface-variant">Chặng đóng khi kết quả được duyệt và gửi phụ huynh.</p>
                    </div>
                </div>

                <UiAlert v-if="position.current" type="info" :title="`Buổi tiếp theo: Buổi ${position.current.session_no} — Unit ${position.current.unit_number ?? ''}`">{{ position.current.title }}</UiAlert>
                <UiAlert v-else-if="position.lessons" type="warning">Lớp đã dạy hết {{ position.lessons }} buổi của chặng{{ position.over ? ` (vượt ${position.over} buổi)` : '' }} — ôn tập và tổ chức Big Test cuối chặng, hoặc gửi yêu cầu giãn tiến độ.</UiAlert>

                <div class="space-y-3">
                    <div v-for="u in stageUnits" :key="u.id" class="overflow-hidden rounded-xl border border-outline-variant">
                        <p class="bg-surface-container-low px-4 py-2.5 font-body-medium text-body-medium font-semibold text-on-surface">Unit {{ u.unit_number }}: {{ u.title }}</p>
                        <div class="divide-y divide-surface-container-highest">
                            <button
                                v-for="l in u.lessons"
                                :key="l.id"
                                type="button"
                                :class="['flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-xs font-semibold text-on-surface hover:bg-surface-container-low', { 'bg-primary-container/10': position.current?.id === l.id }]"
                                @click="lesson = l.id"
                            >
                                <span>Buổi {{ l.session_no }}: {{ l.title }}
                                    <UiBadge v-if="position.current?.id === l.id" color="primary">Buổi tiếp theo</UiBadge>
                                </span>
                                <span class="material-symbols-outlined text-[18px] text-on-surface-subtle" aria-hidden="true">open_in_new</span>
                            </button>
                            <p v-if="!u.lessons.length" class="px-4 py-2.5 text-xs text-on-surface-subtle">Unit chưa có buổi học.</p>
                        </div>
                    </div>
                    <UiEmptyState v-if="!stageUnits.length" icon="description" title="Học thuật chưa soạn nội dung cho chặng này" />
                </div>
            </template>
        </section>
    </div>

    <!-- Xem tài liệu: mở sẵn khi URL có ?document=; đóng → bỏ document khỏi thanh địa chỉ. -->
    <UiModal v-if="selected" :show="viewerOpen" :title="selected.title" max-width="4xl" :dismiss-url="listUrl" @close="viewerOpen = false">
        <div class="space-y-md">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-caption text-caption text-on-surface-variant">{{ selected.meta }}</p>
                <div class="flex items-center gap-2">
                    <UiBadge v-if="!selected.can_download" color="warning" :dot="false" pill><span class="material-symbols-outlined text-[14px]">shield</span>Bảo mật nội dung</UiBadge>
                    <UiButton variant="ghost" size="sm" icon="fullscreen" title="Toàn màn hình" @click="fullscreen" />
                    <UiButton v-if="selected.can_download" variant="secondary" size="sm" icon="download" :href="route('syllabus.documents.file', { id: selected.id, download: 1 })" native>Tải về</UiButton>
                </div>
            </div>
            <div ref="viewer" class="relative flex min-h-[420px] items-center justify-center overflow-hidden rounded-lg bg-surface-container p-4">
                <iframe v-if="selected.kind === 'pdf'" :src="fileUrl + '#toolbar=0'" class="h-[65vh] w-full rounded-lg border border-surface-container-highest bg-surface-container-lowest" :title="selected.title"></iframe>
                <img v-else-if="selected.kind === 'image'" :src="fileUrl" :alt="selected.title" class="max-h-[65vh] rounded-lg border border-surface-container-highest bg-surface-container-lowest" />
                <audio v-else-if="selected.kind === 'audio'" controls controlsList="nodownload" :src="fileUrl" class="w-full max-w-xl"></audio>
                <video v-else-if="selected.kind === 'video'" controls controlsList="nodownload" :src="fileUrl" class="max-h-[65vh] w-full rounded-lg bg-black"></video>
                <UiEmptyState
                    v-else
                    :icon="selected.icon"
                    :title="`Định dạng ${String(selected.extension).toUpperCase()} không xem trực tuyến được`"
                    :description="selected.can_download ? 'Bấm Tải về để mở bằng phần mềm tương ứng.' : 'Tài liệu này không cho phép tải về; liên hệ Học thuật nếu cần bản xem được.'"
                />
                <!-- Watermark bảo mật: tên đăng nhập người xem, không chặn thao tác cuộn/xem. -->
                <div v-if="!selected.can_download" class="pointer-events-none absolute inset-0 flex select-none flex-col items-center justify-around opacity-[0.08]" aria-hidden="true">
                    <p class="-rotate-12 font-h1 text-h1 tracking-widest">MENGLISH INTERNAL ONLY</p>
                    <p class="-rotate-12 font-h2 text-h2 uppercase">{{ userEmail }}</p>
                    <p class="-rotate-12 font-h1 text-h1 tracking-widest">MENGLISH INTERNAL ONLY</p>
                </div>
            </div>
            <p class="flex items-center gap-1.5 font-body-small text-body-small text-on-surface-variant">
                <span :class="['material-symbols-outlined text-[16px]', selected.can_download ? 'text-tertiary' : 'text-warning']">info</span>
                {{ selected.can_download ? 'Tài liệu được phép tải về.' : 'Tài liệu này không hỗ trợ tải về để bảo mật nội dung theo chính sách của MENGLISH.' }}
            </p>
        </div>
        <template #footer>
            <span v-if="selected.viewed" class="inline-flex items-center gap-1 font-body-small text-body-small font-semibold text-tertiary"><span class="material-symbols-outlined text-[18px]">task_alt</span>Đã xem</span>
            <UiForm v-else :action="route('syllabus.documents.viewed', selected.id)" method="post">
                <input v-if="classId" type="hidden" name="class" :value="classId" />
                <UiButton type="submit" icon="done_all">Đánh dấu đã xem</UiButton>
            </UiForm>
        </template>
    </UiModal>

    <!-- Nội dung từng buổi học: bấm dòng buổi → hộp thoại (không bung chi tiết ngay trong danh sách). -->
    <template v-for="u in stageUnits" :key="'lessons-' + u.id">
        <UiModal v-for="l in u.lessons" :key="l.id" :show="lesson === l.id" :title="`Buổi ${l.session_no}: ${l.title}`" max-width="2xl" @close="lesson = null">
            <p class="mb-md font-caption text-caption text-on-surface-variant">Unit {{ u.unit_number }}: {{ u.title }}</p>
            <dl class="space-y-md font-body-small text-body-small">
                <div v-for="(label, field) in lessonFields" :key="field">
                    <dt class="font-label text-label uppercase text-on-surface-variant">{{ label }}:</dt>
                    <dd class="mt-xs whitespace-pre-line text-on-surface">{{ l[field] || '—' }}</dd>
                </div>
            </dl>
        </UiModal>
    </template>
</template>
