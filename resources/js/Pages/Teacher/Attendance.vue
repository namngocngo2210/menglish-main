<script setup>
/**
 * Điểm danh theo buổi (mockup 03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien): tiêu đề buổi + khung giờ / phòng / sĩ số, cửa sổ điểm danh (15 phút trước → 24h sau giờ học),
 * thống kê nhanh 4 trạng thái, quy tắc nghiệp vụ, danh sách lớp với trạng thái + ghi chú (bắt buộc khi nghỉ).
 * Mở từ Trang lớp (nút "Điểm danh" trên từng buổi) → ngăn bên phải: chạm 1 lần chọn trạng thái, ô lý do chỉ hiện khi nghỉ,
 * nút "Tất cả đúng giờ"; lưu xong ngăn đóng, bảng buổi học tải lại.
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SessionTrialGuests from '@/Components/Teacher/SessionTrialGuests.vue';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { useRemoteModal } from '@/Components/ui/modalContext';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Điểm danh' } });

const props = defineProps({
    classroom: { type: Object, required: true },
    session: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    blockReason: { type: String, default: null },
    // Ngoài khung điểm danh (15 phút trước → N giờ sau giờ bắt đầu (chỉ GV/TA; Học vụ record_any không bị giới hạn) → khóa nút lưu.
    windowReason: { type: String, default: null },
    onBehalf: { type: Boolean, default: false },
    onBehalfName: { type: String, default: null },
    recentSessions: { type: Array, default: () => [] },
    attendanceWindow: { type: String, default: null },
    rosterSize: { type: Number, default: 0 },
    trialGuests: { type: Object, default: () => ({ scope: 'upcoming', items: [] }) },
    // Khung điểm danh của GV (giờ, SLA gv.attendance_window trên trang Cấu hình SLA).
    windowHours: { type: Number, default: 24 },
    // Màn GV xem bài giảng của lớp (null khi lớp chưa có chặng mở / không có quyền xem giáo trình).
    syllabusUrl: { type: String, default: null },
});

const modal = useRemoteModal();

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
const allPresent = () => {
    for (const st of props.students) statuses.value[st.id] = 'present';
};
// Ngăn bên phải: nhãn ngắn + màu chữ khi được chọn.
const SHORT = { present: ['Đúng giờ', 'text-tertiary'], late: ['Muộn', 'text-warning'], excused: ['Có phép', 'text-secondary'], absent: ['Không phép', 'text-error'] };
const needsNote = (id) => ['absent', 'excused'].includes(statuses.value[id]);
const pad = (n) => String(n).padStart(2, '0');

const title = computed(() => 'Điểm danh — ' + props.classroom.name + (props.session ? ', ' + props.session.date : ''));

function pickSession(event) {
    const value = event?.target ? event.target.value : event;
    router.get(route('teacher.attendance', props.classroom.id), { session: value });
}
</script>

<template>
    <!-- Ngăn bên phải (mở từ Trang lớp) -->
    <UiForm v-if="modal" :action="route('teacher.attendance.store', props.classroom.id)" method="post" class="flex min-h-0 flex-1 flex-col" #default="{ processing }">
        <input v-if="session" type="hidden" name="class_session_id" :value="session.id" />
        <div class="shrink-0 space-y-sm border-b border-surface-container px-lg py-md">
            <div class="flex items-start justify-between gap-md">
                <div class="min-w-0">
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ classroom.name }}</p>
                    <h2 :id="modal.titleId" class="font-h3 text-h3 text-on-surface">Điểm danh{{ session ? ` · ${session.date}` : '' }}</h2>
                    <p v-if="session" class="font-body-small text-body-small text-on-surface-variant">{{ session.time }}{{ session.room_label ? ` · ${session.room_label}` : '' }}</p>
                </div>
                <button type="button" class="shrink-0 rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" aria-label="Đóng" data-modal-close @click="modal.close()">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
            </div>
            <UiAlert v-if="onBehalf && !windowReason && !blockReason" type="info">Bạn đang điểm danh thay {{ onBehalfName }}{{ session?.is_past ? ' (điểm danh bù)' : '' }}. Hệ thống ghi nhận bạn là người lưu.</UiAlert>
            <UiAlert v-if="windowReason" type="warning" data-testid="attendance-window-blocked">{{ windowReason }}</UiAlert>
            <UiAlert v-if="blockReason" type="warning">{{ blockReason }}</UiAlert>
            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>
        </div>

        <template v-if="session && !blockReason && students.length">
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-sm border-b border-surface-container px-lg py-sm">
                <span class="font-body-small text-body-small text-on-surface-variant">
                    <b class="text-on-surface">{{ students.length }}</b> học viên · <span class="font-semibold text-tertiary">{{ count('present') }} đúng giờ</span> ·
                    <span class="font-semibold text-warning">{{ count('late') }} muộn</span> · <span class="font-semibold text-secondary">{{ count('excused') }} có phép</span> · {{ count('absent') }} không phép
                </span>
                <UiButton variant="secondary" size="sm" :disabled="!!windowReason" @click="allPresent">Tất cả đúng giờ</UiButton>
            </div>
            <ul class="min-h-0 flex-1 divide-y divide-surface-container overflow-y-auto">
                <li v-for="student in students" :key="student.id" class="px-lg py-sm" :data-student="student.id">
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <div class="min-w-0">
                            <div class="font-semibold text-on-surface">{{ student.name }}</div>
                            <div class="font-code text-xs text-on-surface-subtle">
                                {{ student.code }}<template v-if="student.joined_on"> · vào lớp từ {{ student.joined_on }}</template>
                            </div>
                        </div>
                        <input type="hidden" :name="`status[${student.id}]`" :value="statuses[student.id] ?? ''" />
                        <div role="radiogroup" :aria-label="`Trạng thái ${student.name}`" class="inline-flex gap-0.5 rounded-xl bg-surface-container-low p-[3px]">
                            <button
                                v-for="(meta, value) in SHORT"
                                :key="value"
                                type="button"
                                role="radio"
                                :aria-checked="statuses[student.id] === value ? 'true' : 'false'"
                                :disabled="!!windowReason"
                                :class="['h-8 rounded-lg px-sm text-xs font-semibold transition-colors', statuses[student.id] === value ? ['bg-surface-container-lowest shadow-sm', meta[1]] : 'text-on-surface-variant hover:text-on-surface']"
                                @click="statuses[student.id] = value"
                            >
                                {{ meta[0] }}
                            </button>
                        </div>
                    </div>
                    <div v-if="needsNote(student.id)" class="mt-sm">
                        <input
                            type="text"
                            :name="`note[${student.id}]`"
                            maxlength="500"
                            :value="student.note"
                            placeholder="Lý do nghỉ (bắt buộc)"
                            required
                            :aria-label="`Lý do nghỉ của ${student.name}`"
                            :class="['h-10 w-full rounded-lg border px-sm font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/50', errors['note.' + student.id] ? 'border-error' : 'border-outline-variant']"
                        />
                        <p v-if="errors['note.' + student.id]" class="mt-xs text-xs text-error">{{ errors['note.' + student.id] }}</p>
                    </div>
                    <input v-else type="hidden" :name="`note[${student.id}]`" :value="student.note ?? ''" />
                </li>
            </ul>
        </template>
        <div v-else class="min-h-0 flex-1">
            <UiEmptyState v-if="!session" icon="event_busy" title="Không tìm thấy buổi học này." />
            <UiEmptyState v-else-if="!students.length" icon="group_off" title="Buổi học này chưa có học viên nào trong danh sách lớp." />
        </div>

        <div class="flex shrink-0 flex-wrap items-center justify-between gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
            <span class="text-xs text-on-surface-variant">Học viên vắng tự vào danh sách bổ trợ.</span>
            <div class="flex gap-sm">
                <UiButton variant="secondary" @click="modal.close()">Hủy</UiButton>
                <UiButton v-if="session && !blockReason && students.length" type="submit" icon="save" :disabled="!!windowReason || processing">Lưu điểm danh</UiButton>
            </div>
        </div>
    </UiForm>

    <div v-else class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0">
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
            <template v-if="syllabusUrl" #actions>
                <UiButton variant="secondary" icon="menu_book" :href="syllabusUrl">Mở giáo trình</UiButton>
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
            <UiAlert v-if="windowReason" type="warning" data-testid="attendance-window-blocked">{{ windowReason }}</UiAlert>
            <!-- Gộp cửa sổ điểm danh (15 phút trước → 24h sau giờ học), "điểm danh bù" và quy định thành một dòng; quy định mở khi cần. -->
            <details :class="['group rounded-lg border', attendanceWindow === 'closed' ? 'border-warning/30 bg-warning-container' : 'border-tertiary/30 bg-tertiary-fixed/20']" data-testid="attendance-window">
                <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-sm gap-y-xs px-md py-sm [&::-webkit-details-marker]:hidden">
                    <span class="relative flex h-3 w-3 shrink-0">
                        <span v-if="attendanceWindow !== 'closed'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-tertiary-container opacity-60"></span>
                        <span :class="['relative inline-flex h-3 w-3 rounded-full', attendanceWindow === 'closed' ? 'bg-warning' : 'bg-tertiary-container']"></span>
                    </span>
                    <span class="font-body-medium text-body-medium text-on-surface">{{ windowReason ? 'Ngoài khung điểm danh — chưa / không thể điểm danh' : (attendanceWindow === 'closed' ? `Ngoài cửa sổ ${windowHours}h — Học vụ điểm danh bù` : 'Đang trong cửa sổ điểm danh') }}</span>
                    <span class="font-caption text-caption text-on-surface-variant">
                        Quy định: từ 15 phút trước giờ học tới {{ windowHours }} giờ sau{{ attendanceWindow === 'closed' ? ' · Học vụ sẽ rà soát' : '' }}{{ session.is_past ? ' · Điểm danh bù cho buổi đã qua ngày ' + session.date : '' }}
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
                        <li>Giáo viên điểm danh từ 15 phút trước giờ bắt đầu buổi tới {{ windowHours }}h sau giờ bắt đầu (cập nhật lại được nhiều lần); ngoài khung này chỉ Học vụ điểm danh bù. Không điểm danh không làm mất công dạy.</li>
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
                                <template v-if="student.joined_on"> · Vào lớp từ {{ student.joined_on }}</template>
                                <template v-if="student.recorder_name"> · Lưu bởi {{ student.recorder_name }}</template>
                            </div>
                        </div>
                        <select
                            v-model="statuses[student.id]"
                            :name="`status[${student.id}]`"
                            :aria-label="`Trạng thái điểm danh ${student.name}`"
                            :class="['w-full rounded-lg border py-sm pl-md pr-xl font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/50', OPTIONS[statuses[student.id]]?.tone ?? 'border-outline-variant']"
                        >
                            <option v-if="student.joined_on" value="">— Chưa vào lớp —</option>
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
                    <UiButton type="submit" icon="save" class="w-full sm:w-auto" :disabled="!!windowReason" :title="windowReason || undefined">Lưu điểm danh</UiButton>
                </div>
            </UiForm>
        </template>
    </div>

    <TeacherBottomNav v-if="!modal" />
</template>
