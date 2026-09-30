<script setup>
/**
 * Học tập của tôi — Nộp bài tập (MH #3): 6 hạng mục bài tập (nộp / sửa / hủy nộp), nhận xét buổi học, bảng điểm, lộ trình.
 * Nhân sự xem hộ thì quay về danh sách học viên; học viên quay về trang chủ cổng. Trên điện thoại dùng thanh điều hướng đáy.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';

defineOptions({ layout: { title: 'Học tập & Nộp bài tập', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    submissionsByType: { type: [Object, Array], default: () => ({}) },
    completedCount: { type: Number, default: 0 },
    remarks: { type: Array, default: () => [] },
    miniTests: { type: Array, default: () => [] },
    bigTestResults: { type: Array, default: () => [] },
    latestHomework: { type: Object, default: null },
    homeworkUrgent: { type: Boolean, default: false },
});

const CATEGORIES = {
    video: { title: 'Quay video bài học', icon: 'videocam', color: 'text-primary', btnText: 'Tải lên video', isQuiz: false },
    vocabulary: { title: 'Viết từ vựng & Chụp ảnh', icon: 'edit_document', color: 'text-secondary', btnText: 'Tải lên bài viết', isQuiz: false },
    workbook: { title: 'Làm Workbook bài tập', icon: 'menu_book', color: 'text-secondary', btnText: 'Tải lên Workbook', isQuiz: false },
    extra_book: { title: 'Sách bổ trợ', icon: 'library_books', color: 'text-tertiary', btnText: 'Tải lên bài làm', isQuiz: false },
    bgd_book: { title: 'Sách Bộ Giáo dục', icon: 'import_contacts', color: 'text-accent', btnText: 'Tải lên bài làm', isQuiz: false },
    quiz: { title: 'Làm Quiz trực tuyến', icon: 'quiz', color: 'text-warning', btnText: 'Nộp kết quả Quiz', isQuiz: true },
};
const REMARK_FIELDS = {
    monsters: ['Monsters', 'hotel_class', 'text-warning'],
    grammar: ['Ngữ pháp', 'psychology', 'text-secondary'],
    attitude: ['Tinh thần', 'mood', 'text-tertiary'],
    result: ['Kết quả', 'checklist', 'text-accent'],
};

// Chỉ tô cam mục chưa nộp đầu tiên khi bài GV giao sắp/đã tới hạn; còn lại là nút phụ.
const cards = computed(() => {
    let urgentAssigned = false;
    return Object.entries(CATEGORIES).map(([key, cat]) => {
        const sub = props.submissionsByType?.[key] ?? null;
        const urgent = !sub && props.homeworkUrgent && !urgentAssigned;
        if (urgent) urgentAssigned = true;
        return { key, cat, sub, urgent };
    });
});
const params = computed(() => ({ studentId: props.student?.id ?? null }));
const backUrl = computed(() => (can('student.view') ? route('students.index') : route('portal.student.home', params.value)));
const studentOptions = computed(() => props.students.map((s) => ({ value: s.id, label: `${s.name} (${s.class_name ?? 'Chưa xếp lớp'})` })));
const filled = (value) => value !== null && value !== undefined && String(value).trim() !== '';

const uploadOpen = ref(false);
const uploadType = ref('video');
const uploadTitle = ref('Quay video bài học');
const editOpen = ref(false);
const editId = ref(null);
const editNotes = ref('');

function openUpload(type, title) {
    uploadType.value = type;
    uploadTitle.value = title;
    uploadOpen.value = true;
}

function openEdit(id, notes) {
    editId.value = id;
    editNotes.value = notes ?? '';
    editOpen.value = true;
}

function switchStudent(event) {
    router.visit(route('portal.student.homework', { studentId: event.target.value }));
}
</script>

<template>
    <PortalPageHeader title="Học tập & Nộp bài tập" icon="upload_file" :back="backUrl">
        <template v-if="can('homework.grade')" #actions>
            <UiButton variant="secondary" icon="video_library" :href="route('portal.teacher.submissions')">Cổng GV: Xem bài nộp của lớp</UiButton>
        </template>
    </PortalPageHeader>

    <div class="mx-auto max-w-4xl space-y-6">
        <!-- Đổi học viên (phụ huynh nhiều con / nhân sự xem hộ) -->
        <div class="flex flex-col justify-between gap-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm sm:flex-row sm:items-center">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-container/10 font-bold text-primary">
                    <span class="material-symbols-outlined">account_circle</span>
                </div>
                <div>
                    <span class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Tài khoản học viên:</span>
                    <strong class="text-sm text-on-surface">{{ student?.name ?? '—' }}</strong>
                    <span class="font-mono text-xs text-on-surface-variant"> (<UiCode :value="student?.code" />)</span>
                </div>
            </div>

            <UiSelect inline-label="Đổi học viên:" :options="studentOptions" :value="student?.id" @change="switchStudent" />
        </div>

        <!-- Khung điện thoại -->
        <div class="mx-auto max-w-[420px] overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-8 shadow-xl md:max-w-none md:shadow-sm">
            <div class="relative flex h-[140px] w-full flex-col justify-end bg-gradient-to-br from-primary-container to-primary-container/60 p-6 text-white">
                <div class="absolute right-3 top-3 rounded-full bg-surface-container-lowest/20 px-2.5 py-1 text-xs font-bold uppercase tracking-wider backdrop-blur-xs">MENGLISH LMS</div>
                <h2 class="text-2xl font-bold tracking-tight text-white drop-shadow-sm">Học tập của tôi</h2>
                <p class="mt-0.5 text-xs text-white/80">Lớp: {{ student?.class_name ?? 'Chưa xếp lớp' }}</p>
            </div>

            <!-- Nộp bài tập / Luyện phát âm -->
            <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
                <Link :href="route('portal.student.homework', params)" class="flex items-center gap-1.5 border-b-2 border-primary-container px-4 py-2 text-xs font-bold text-primary">
                    <span class="material-symbols-outlined text-[16px]">assignment</span>
                    <span>Nộp bài tập</span>
                </Link>
                <Link :href="route('portal.student.pronunciation', params)" class="flex items-center gap-1.5 border-b-2 border-transparent px-4 py-2 text-xs font-semibold text-on-surface-variant transition hover:text-on-surface">
                    <span class="material-symbols-outlined text-[16px]">mic</span>
                    <span>Luyện phát âm AI</span>
                </Link>
            </div>

            <div class="mt-4 flex flex-col gap-6 px-4 pb-20 md:px-6 md:pb-4">
                <!-- 1. BÀI TẬP VỀ NHÀ — học viên vào trang để nộp bài nên đặt lên đầu -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">assignment</span>
                        <h2 class="text-base font-bold text-on-surface">Bài tập về nhà</h2>
                    </div>

                    <!-- Bài tập giáo viên giao gần nhất -->
                    <UiAlert v-if="latestHomework" type="error" :title="latestHomework.title">
                        <p v-if="latestHomework.description" class="text-xs leading-normal">{{ latestHomework.description }}</p>
                        <p class="mt-1 text-xs">{{ latestHomework.class_name }} · Hạn nộp: {{ latestHomework.due_date ?? 'Không giới hạn' }}</p>
                    </UiAlert>

                    <div class="mt-1 flex items-center justify-between">
                        <p class="text-xs font-bold text-on-surface">Đã hoàn thành: {{ completedCount }}/6 hạng mục</p>
                        <div class="h-2 w-1/3 overflow-hidden rounded-full bg-surface-container">
                            <div class="h-full rounded-full bg-tertiary transition-all duration-300" :style="{ width: Math.round((completedCount / 6) * 100) + '%' }"></div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <div v-for="{ key, cat, sub, urgent } in cards" :key="key" class="flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span :class="['material-symbols-outlined text-lg', cat.color]">{{ cat.icon }}</span>
                                    <h4 class="text-xs font-bold text-on-surface">{{ cat.title }}</h4>
                                </div>
                                <UiBadge v-if="sub" color="success" pill :dot="false"><span class="material-symbols-outlined text-[12px]">check_circle</span> Đã nộp</UiBadge>
                                <UiBadge v-else color="error" pill>Chưa nộp</UiBadge>
                            </div>

                            <template v-if="sub">
                                <div class="space-y-1 rounded-xl border border-surface-container-highest bg-surface-container-low p-2.5 text-xs">
                                    <div class="flex items-center justify-between text-xs text-on-surface-variant">
                                        <span>Nộp lúc: <strong class="font-mono text-on-surface-variant">{{ sub.submitted_at }}</strong></span>
                                        <UiBadge v-if="sub.status === 'reviewed'" color="success" :dot="false">{{ filled(sub.score) ? 'Đã chấm: ' + sub.score : 'Giáo viên đã xem' }}</UiBadge>
                                        <span v-else class="font-medium text-warning">Chờ giáo viên chấm</span>
                                    </div>

                                    <div v-if="sub.attachment_path" class="pt-1">
                                        <a :href="sub.attachment_path" target="_blank" class="inline-flex items-center gap-1 text-xs font-bold text-primary hover:underline">
                                            <span class="material-symbols-outlined text-[14px]">attachment</span>
                                            <span>{{ sub.attachment_name ?? 'Xem tệp đính kèm' }}</span>
                                        </a>
                                    </div>

                                    <p v-if="sub.notes" class="text-xs italic text-on-surface-variant">"{{ sub.notes }}"</p>

                                    <div v-if="sub.feedback" class="mt-1 rounded-lg border border-secondary/30 bg-secondary/10 p-2 text-xs text-secondary">
                                        <strong>Nhận xét của GV:</strong> {{ sub.feedback }}
                                    </div>
                                </div>

                                <!-- Sửa ghi chú / Nộp lại & Hủy nộp -->
                                <div class="flex items-center gap-2 pt-1">
                                    <UiButton variant="secondary" size="sm" icon="edit" class="flex-1" @click="openEdit(sub.id, sub.notes)">Sửa / Nộp lại</UiButton>
                                    <UiForm :action="route('portal.student.homework.destroy', sub.id)" method="delete" confirm="Hủy bài nộp này?" confirm-label="Hủy bài nộp" danger>
                                        <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Hủy nộp" aria-label="Hủy nộp" />
                                    </UiForm>
                                </div>
                            </template>
                            <div v-else class="flex flex-col gap-1.5 pt-1">
                                <UiButton :variant="urgent ? 'primary' : 'secondary'" icon="upload" class="w-full" @click="openUpload(key, cat.title)">{{ cat.btnText }}</UiButton>
                                <a v-if="cat.isQuiz" href="https://quizizz.com" target="_blank" class="mt-0.5 inline-flex items-center justify-center gap-1 text-xs font-bold text-secondary hover:underline">
                                    <span>Mở link Quiz trực tuyến</span>
                                    <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 2. NHẬN XÉT BUỔI HỌC -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">insights</span>
                        <h2 class="text-base font-bold text-on-surface">Nhận xét buổi học</h2>
                    </div>

                    <div v-for="(item, i) in remarks" :key="i" class="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                        <div class="flex items-start justify-between">
                            <p class="text-xs font-medium text-on-surface-subtle">Ngày {{ item.date }}</p>
                            <UiBadge color="success" pill>Đã nhận xét</UiBadge>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <template v-for="(field, fieldKey) in REMARK_FIELDS" :key="fieldKey">
                                <div v-if="filled(item.remark[fieldKey])" class="flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-2.5">
                                    <span :class="['material-symbols-outlined text-lg', field[2]]">{{ field[1] }}</span>
                                    <div>
                                        <p class="text-xs font-medium text-on-surface-subtle">{{ field[0] }}</p>
                                        <p class="text-xs font-bold text-on-surface">{{ item.remark[fieldKey] }}</p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div v-if="filled(item.remark.comment)" class="border-t border-surface-container-highest pt-3">
                            <p class="mb-1 flex items-center gap-1 text-xs font-bold text-on-surface-variant">
                                <span class="material-symbols-outlined text-[15px] text-primary">edit_note</span>
                                Nhận xét chi tiết từ giáo viên
                            </p>
                            <p class="rounded-xl border border-primary-container/30 bg-primary-container/10 p-2.5 text-xs leading-relaxed text-on-surface-variant">{{ item.remark.comment }}</p>
                        </div>
                    </div>
                    <UiEmptyState v-if="!remarks.length" class="rounded-2xl border border-dashed border-surface-container-highest bg-surface-container-low" icon="insights" title="Chưa có nhận xét buổi học nào." />
                </section>

                <!-- 3. BẢNG ĐIỂM -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">school</span>
                        <h2 class="text-base font-bold text-on-surface">Bảng điểm</h2>
                    </div>
                    <div class="flex flex-col gap-2">
                        <div v-for="mt in miniTests" :key="'mt' + mt.id" class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs">
                            <div>
                                <h4 class="text-xs font-bold text-on-surface">{{ mt.name }}</h4>
                                <p class="font-mono text-xs text-on-surface-subtle">Ngày thi: {{ mt.test_date }}</p>
                            </div>
                            <div class="font-mono text-lg font-black text-primary">{{ mt.score }}<span class="text-xs text-on-surface-subtle">/{{ mt.max_score }}</span></div>
                        </div>
                        <div v-for="bt in bigTestResults" :key="'bt' + bt.id" class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs">
                            <div>
                                <h4 class="text-xs font-bold text-on-surface">{{ bt.title }}</h4>
                                <p class="font-mono text-xs text-on-surface-subtle">Ngày thi: {{ bt.scheduled_at }}</p>
                            </div>
                            <UiBadge v-if="bt.is_absent" color="neutral" pill>Vắng thi</UiBadge>
                            <div v-else class="font-mono text-lg font-black text-primary">{{ bt.overall_score }}</div>
                        </div>
                        <UiEmptyState v-if="!miniTests.length && !bigTestResults.length" class="rounded-xl border border-dashed border-surface-container-highest bg-surface-container-low" icon="school" title="Chưa có điểm kiểm tra nào." />
                    </div>
                </section>

                <!-- 4. LỘ TRÌNH HỌC TẬP -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">map</span>
                        <h2 class="text-base font-bold text-on-surface">Lộ trình học tập</h2>
                    </div>
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-2xs">
                        <div class="flex flex-col items-center space-y-2 rounded-xl bg-surface-container-low p-4 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-container/10 text-primary">
                                <span class="material-symbols-outlined text-[28px]">trending_up</span>
                            </div>
                            <h3 class="text-xs font-bold text-on-surface">Lộ trình mục tiêu: IELTS 6.5</h3>
                            <p class="max-w-[280px] text-xs text-on-surface-variant">Đang học Chặng 2 (Intermediate). Đạt 65% thời lượng chương trình.</p>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-container-high">
                                <div class="h-full rounded-full bg-primary-container" style="width: 65%"></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <PortalBottomNav active-tab="learning" :student="student" :unread-count="unreadCount" />
        </div>

        <!-- Nộp bài tập -->
        <UiModal :show="uploadOpen" title="Nộp bài tập" max-width="md" @close="uploadOpen = false">
            <p class="mb-4 flex items-center gap-2 text-sm font-bold text-on-surface">
                <span class="material-symbols-outlined text-primary">cloud_upload</span>
                <span>{{ 'Nộp bài: ' + uploadTitle }}</span>
            </p>

            <UiForm id="homework-upload-form" :action="route('portal.student.homework.submit')" method="post" class="space-y-4 text-xs" reset-on-success @success="uploadOpen = false">
                <input type="hidden" name="student_id" :value="student?.id ?? 1" />
                <input type="hidden" name="homework_type" :value="uploadType" />

                <UiField label="Chọn tệp tin bài làm (Video / Ảnh / PDF)" name="attachment" for="homework-upload-attachment">
                    <div class="cursor-pointer rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-6 text-center transition hover:border-primary-container">
                        <span class="material-symbols-outlined mb-1 block text-[36px] text-on-surface-subtle">attach_file</span>
                        <span class="block text-xs text-on-surface-variant">Kéo thả hoặc bấm để chọn tệp tải lên</span>
                        <span class="mt-1 block text-xs text-on-surface-subtle">Hỗ trợ MP4, MOV, PNG, JPG, PDF (tối đa 50MB)</span>
                        <input id="homework-upload-attachment" type="file" name="attachment" class="mt-3 w-full text-center text-xs" />
                    </div>
                </UiField>

                <UiTextarea id="homework-upload-notes" name="notes" label="Ghi chú gửi thầy cô (Tùy chọn)" :rows="3" placeholder="Nhập ghi chú cho bài nộp..." />
            </UiForm>

            <template #footer>
                <UiButton variant="secondary" @click="uploadOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="homework-upload-form" icon="send"><span>Xác nhận nộp bài</span></UiButton>
            </template>
        </UiModal>

        <!-- Sửa / nộp lại bài -->
        <UiModal :show="editOpen" title="Chỉnh sửa / Cập nhật bài nộp" max-width="md" @close="editOpen = false">
            <UiForm id="homework-edit-form" :action="editId ? route('portal.student.homework.update', editId) : '#'" method="post" class="space-y-4 text-xs" reset-on-success @success="editOpen = false">
                <UiField label="Cập nhật lại tệp tin bài làm (Nếu muốn thay thế tệp cũ)" name="attachment" for="homework-edit-attachment" hint="Để trống nếu muốn giữ nguyên tệp tin đã tải lên trước đó.">
                    <input id="homework-edit-attachment" type="file" name="attachment" class="w-full rounded-lg border border-outline-variant bg-surface-container-low p-2 text-xs" />
                </UiField>

                <UiTextarea id="homework-edit-notes" v-model="editNotes" name="notes" label="Ghi chú gửi thầy cô" :rows="3" />
            </UiForm>

            <template #footer>
                <UiButton variant="secondary" @click="editOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="homework-edit-form" icon="save"><span>Lưu cập nhật</span></UiButton>
            </template>
        </UiModal>
    </div>
</template>
