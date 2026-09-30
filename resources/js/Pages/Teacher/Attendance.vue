<script setup>
/**
 * Điểm danh theo buổi (mockup 03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien): tiêu đề buổi + khung giờ / phòng / sĩ số, cửa sổ ±24h,
 * thống kê nhanh 4 trạng thái, quy tắc nghiệp vụ, danh sách lớp với trạng thái + ghi chú (bắt buộc khi nghỉ).
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SessionTrialGuests from '@/Components/Teacher/SessionTrialGuests.vue';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Điểm danh' } });

const props = defineProps({
    classroom: { type: Object, required: true },
    session: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    blockReason: { type: String, default: null },
    onBehalf: { type: Boolean, default: false },
    onBehalfName: { type: String, default: null },
    recentSessions: { type: Array, default: () => [] },
    attendanceWindow: { type: String, default: null },
    rosterSize: { type: Number, default: 0 },
    trialGuests: { type: Object, default: () => ({ scope: 'upcoming', items: [] }) },
});

const OPTIONS = {
    present: { label: 'Đúng giờ', tone: 'border-outline-variant' },
    late: { label: 'Muộn', tone: 'border-warning/30 bg-warning-container' },
    excused: { label: 'Nghỉ có phép', tone: 'border-secondary/30 bg-secondary/10' },
    absent: { label: 'Nghỉ không phép', tone: 'border-error/30 bg-error/10' },
};

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const firstError = computed(() => errors.value.note || Object.values(errors.value).find((v) => typeof v === 'string') || null);

const statuses = ref({});
const loadStatuses = () => {
    statuses.value = Object.fromEntries(props.students.map((st) => [st.id, st.status]));
};
loadStatuses();
watch(() => props.session?.id, loadStatuses);

const count = (v) => Object.values(statuses.value).filter((s) => s === v).length;
const needsNote = (id) => ['absent', 'excused'].includes(statuses.value[id]);
const pad = (n) => String(n).padStart(2, '0');

const title = computed(() => 'Điểm danh — ' + props.classroom.name + (props.session ? ', ' + props.session.date : ''));

function pickSession(event) {
    const value = event?.target ? event.target.value : event;
    router.get(route('teacher.attendance', props.classroom.id), { session: value });
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0">
        <!-- Tiêu đề buổi học -->
        <UiPageHeader :title="title" :back="route('teacher.home')" back-label="Về lịch dạy">
            <template v-if="session" #meta>
                <div class="flex flex-wrap items-center gap-x-md gap-y-xs">
                    <span class="inline-flex items-center gap-xs rounded-md bg-surface-container-high px-sm py-[2px] font-medium text-on-surface">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>
                        Khung giờ: {{ session.time }}
                    </span>
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">meeting_room</span>{{ session.room_label ?? 'Chưa có phòng' }} · {{ props.classroom.branch_name ?? 'Chưa gán chi nhánh' }}</span>
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">groups</span>Sĩ số lớp: <strong class="text-on-surface">{{ rosterSize }} học sinh</strong></span>
                    <UiBadge v-if="session.type === 'makeup'" color="warning">Buổi học bù</UiBadge>
                    <UiBadge v-if="session.type === 'support'" color="secondary">Buổi phụ đạo</UiBadge>
                </div>
            </template>
        </UiPageHeader>

        <section v-if="session && !blockReason && students.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
            <div class="grid grid-cols-2 gap-sm sm:grid-cols-4">
                <div class="rounded-lg bg-tertiary-fixed/20 p-sm"><div class="font-caption text-caption text-on-surface-variant">Đúng giờ</div><div class="font-h3 text-h3 text-tertiary">{{ count('present') }}</div></div>
                <div class="rounded-lg bg-warning-container p-sm"><div class="font-caption text-caption text-on-surface-variant">Đi muộn</div><div class="font-h3 text-h3 text-warning">{{ count('late') }}</div></div>
                <div class="rounded-lg bg-secondary/10 p-sm"><div class="font-caption text-caption text-on-surface-variant">Nghỉ có phép</div><div class="font-h3 text-h3 text-secondary">{{ count('excused') }}</div></div>
                <div class="rounded-lg bg-error/10 p-sm"><div class="font-caption text-caption text-on-surface-variant">Nghỉ không phép</div><div class="font-h3 text-h3 text-error">{{ count('absent') }}</div></div>
            </div>
        </section>

        <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

        <!-- Chọn buổi -->
        <div v-if="recentSessions.length" class="flex flex-col gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md sm:flex-row sm:items-center">
            <label for="session-picker" class="shrink-0 font-label-caps text-label-caps uppercase text-on-surface-variant">Buổi điểm danh</label>
            <UiSelect id="session-picker" name="session" :value="session?.id ?? ''" class="flex-1" @change="pickSession">
                <!-- Chưa chọn buổi: ô chọn để trống thay vì hiện buổi đầu danh sách mà trang không mở. -->
                <option v-if="!session" value="" selected disabled>— Chọn buổi —</option>
                <option v-for="s in recentSessions" :key="s.id" :value="s.id" :selected="session && session.id === s.id" :disabled="s.disabled">{{ s.label }}</option>
            </UiSelect>
        </div>

        <SessionTrialGuests :guests="trialGuests" />

        <div v-if="!session" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
            <UiEmptyState v-if="recentSessions.length" icon="event_busy" title="Lớp không có buổi học trong ngày này" description="Chọn buổi cần điểm danh bù ở ô “Chọn buổi” phía trên." />
            <UiEmptyState v-else icon="event_busy" title="Lớp chưa có buổi học nào" description="Lớp chưa được xếp thời khóa biểu. Liên hệ Học vụ để kiểm tra TKB của lớp." />
        </div>
        <UiAlert v-else-if="blockReason" type="warning">{{ blockReason }}</UiAlert>
        <div v-else-if="!students.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
            <UiEmptyState icon="group_off" title="Chưa có học viên" description="Buổi học này chưa có học viên nào trong danh sách lớp." />
        </div>
        <template v-else>
            <UiAlert v-if="onBehalf" type="info" title="Điểm danh thay giáo viên">
                Bạn đang điểm danh thay {{ onBehalfName }}. Hệ thống ghi nhận bạn là người lưu điểm danh.
            </UiAlert>
            <!-- Gộp cửa sổ ±24h, "điểm danh bù" và quy định thành một dòng; quy định mở khi cần. -->
            <details :class="['group rounded-lg border', attendanceWindow === 'closed' ? 'border-warning/30 bg-warning-container' : 'border-tertiary/30 bg-tertiary-fixed/20']" data-testid="attendance-window">
                <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-sm gap-y-xs px-md py-sm [&::-webkit-details-marker]:hidden">
                    <span class="relative flex h-3 w-3 shrink-0">
                        <span v-if="attendanceWindow !== 'closed'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-tertiary-container opacity-60"></span>
                        <span :class="['relative inline-flex h-3 w-3 rounded-full', attendanceWindow === 'closed' ? 'bg-warning' : 'bg-tertiary-container']"></span>
                    </span>
                    <span class="font-body-medium text-body-medium text-on-surface">{{ attendanceWindow === 'closed' ? 'Ngoài cửa sổ 24h — điểm danh bù' : 'Đang trong cửa sổ điểm danh' }}</span>
                    <span class="font-caption text-caption text-on-surface-variant">
                        Quy định: Buổi học ±24 giờ{{ attendanceWindow === 'closed' ? ' · Học vụ sẽ rà soát' : '' }}{{ session.is_past ? ' · Điểm danh bù cho buổi đã qua ngày ' + session.date : '' }}
                    </span>
                    <span class="ml-auto inline-flex items-center gap-xs font-caption text-caption font-semibold text-primary">
                        Xem quy định<span class="material-symbols-outlined text-[16px] transition-transform group-open:rotate-180" aria-hidden="true">expand_more</span>
                    </span>
                </summary>
                <div class="border-t border-outline-variant/60 px-md py-sm font-body-small text-body-small text-on-surface-variant">
                    <p class="mb-xs font-semibold text-on-surface">Quy tắc nghiệp vụ điểm danh dành cho Giáo viên:</p>
                    <ul class="list-disc space-y-xs pl-md">
                        <li>Người điểm danh được ghi nhận tự động theo tài khoản đang đăng nhập (GV chính/GVNN/Trợ giảng).</li>
                        <li>Khi chọn <strong>"Nghỉ có phép"</strong> hoặc <strong>"Nghỉ không phép"</strong>, ô <strong>Ghi chú là bắt buộc</strong> để lưu trữ lý do vắng học của học viên.</li>
                        <li>Trong cửa sổ ±24h, giáo viên có thể cập nhật lại nhiều lần; ngoài cửa sổ vẫn điểm danh bù được, Học vụ sẽ rà soát.</li>
                    </ul>
                </div>
            </details>

            <UiForm :action="route('teacher.attendance.store', props.classroom.id)" method="post" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                <input type="hidden" name="class_session_id" :value="session.id" />
                <div class="flex flex-col justify-between gap-sm border-b border-surface-container p-md sm:flex-row sm:items-center">
                    <div>
                        <h2 class="font-h3 text-h3 text-on-surface">Danh sách học sinh trong lớp (Roster)</h2>
                        <p class="font-caption text-caption text-on-surface-variant">Dữ liệu nguồn xếp lớp chính thức · Vui lòng kiểm tra và xác nhận đúng từng học sinh</p>
                    </div>
                    <span class="inline-flex items-center gap-xs rounded-full bg-tertiary-fixed/30 px-sm py-[2px] font-caption text-caption font-semibold text-tertiary">
                        <span class="h-2 w-2 rounded-full bg-tertiary"></span> {{ students.length }}/{{ students.length }} học sinh đã gán trạng thái
                    </span>
                </div>

                <div class="hidden grid-cols-[48px_1.2fr_1fr_1.4fr] gap-md bg-surface-container-low px-md py-sm font-label-caps text-label-caps uppercase text-on-surface-variant md:grid">
                    <span>STT</span><span>Học sinh</span><span>Trạng thái điểm danh <span class="text-error">*</span></span><span>Ghi chú</span>
                </div>
                <div class="divide-y divide-surface-container">
                    <div
                        v-for="(student, index) in students"
                        :key="student.id"
                        :class="['grid grid-cols-1 gap-sm px-md py-sm md:grid-cols-[48px_1.2fr_1fr_1.4fr] md:items-start md:gap-md', { 'bg-warning-container/30': statuses[student.id] === 'late', 'bg-secondary/5': statuses[student.id] === 'excused', 'bg-error/5': statuses[student.id] === 'absent' }]"
                    >
                        <span class="hidden font-code text-code text-on-surface-variant md:block">{{ pad(index + 1) }}</span>
                        <div class="min-w-0">
                            <div class="font-body-medium text-body-medium font-semibold text-on-surface">{{ student.name }}</div>
                            <div class="font-caption text-caption text-on-surface-variant">
                                <span class="font-code">{{ student.code }}</span>
                                <template v-if="student.linked"> · Học viên liên kết lớp</template>
                                <template v-if="student.recorder_name"> · Lưu bởi {{ student.recorder_name }}</template>
                            </div>
                        </div>
                        <select
                            v-model="statuses[student.id]"
                            :name="`status[${student.id}]`"
                            :aria-label="`Trạng thái điểm danh ${student.name}`"
                            :class="['w-full rounded-lg border py-sm pl-md pr-xl font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/50', OPTIONS[statuses[student.id]]?.tone ?? 'border-outline-variant']"
                        >
                            <option v-for="(opt, value) in OPTIONS" :key="value" :value="value">{{ opt.label }}</option>
                        </select>
                        <div>
                            <input
                                type="text"
                                :name="`note[${student.id}]`"
                                maxlength="500"
                                :value="student.note"
                                :placeholder="needsNote(student.id) ? 'Nhập lý do nghỉ học... *' : 'Ghi chú thêm (tùy chọn)...'"
                                :required="needsNote(student.id)"
                                :aria-label="`Ghi chú ${student.name}`"
                                :class="['w-full rounded-lg border px-md py-sm font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/50', errors['note.' + student.id] ? 'border-error' : 'border-outline-variant']"
                            />
                            <p v-if="errors['note.' + student.id]" class="mt-xs font-caption text-caption text-error">{{ errors['note.' + student.id] }}</p>
                            <p v-else-if="needsNote(student.id)" class="mt-xs font-caption text-caption text-error">* Cần ghi rõ lý do khi đánh dấu nghỉ</p>
                        </div>
                    </div>
                </div>

                <!-- Điện thoại: thanh lưu bám ngay trên thanh điều hướng dưới, không phải cuộn hết danh sách. -->
                <div class="sticky bottom-[72px] z-20 flex flex-col justify-between gap-sm border-t border-surface-container bg-surface-container-low p-md shadow-level-3 sm:flex-row sm:items-center md:static md:shadow-none">
                    <p class="hidden font-caption text-caption text-on-surface-variant sm:block">Phiếu điểm danh sẽ được ghi đè (upsert) cập nhật trực tiếp cho buổi học này. Học viên vắng tự vào danh sách bổ trợ.</p>
                    <UiButton type="submit" icon="save" class="w-full sm:w-auto">Lưu điểm danh</UiButton>
                </div>
            </UiForm>
        </template>
    </div>

    <TeacherBottomNav />
</template>
