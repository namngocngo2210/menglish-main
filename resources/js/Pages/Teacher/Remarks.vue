<script setup>
/**
 * Nhận xét buổi học cho từng học sinh (mockup 03_Cong_Giao_Vien/05): theo từng BUỔI học, cột Monsters (Nhóm) / (Thưởng),
 * Thực hành ngữ pháp, Tinh thần học tập, Kết quả, Nhận xét chi tiết; học sinh vắng bị khóa; "Lưu nháp" chưa hiện cho học viên.
 */
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SessionTrialGuests from '@/Components/Teacher/SessionTrialGuests.vue';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhận xét buổi học' } });

const props = defineProps({
    classroom: { type: Object, required: true },
    session: { type: Object, default: null },
    recordState: { type: String, default: null },
    blockReason: { type: String, default: null },
    students: { type: Array, default: () => [] },
    recentSessions: { type: Array, default: () => [] },
    trialGuests: { type: Object, default: () => ({ scope: 'upcoming', items: [] }) },
});

const cell = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small focus:border-primary-container focus:ring-2 focus:ring-primary-container/50 disabled:cursor-not-allowed disabled:bg-surface-container-low';
const FIELDS = [
    ['monsters_group', 'Monsters (Nhóm)', 'e.g. +5'],
    ['monsters_bonus', 'Monsters (Thưởng)', 'e.g. +2'],
    ['grammar', 'Thực hành ngữ pháp', 'Tốt / Khá / Cần cố gắng'],
    ['attitude', 'Tinh thần học tập', 'Năng nổ, hăng hái'],
    ['result', 'Kết quả', 'Đạt mục tiêu bài học'],
];
// Màn nhỏ: mỗi học sinh một thẻ xếp dọc (như trang điểm danh); từ md trở lên: dạng bảng, cuộn ngang khi hẹp.
const grid = 'md:grid md:grid-cols-[200px_130px_110px_110px_160px_160px_160px_minmax(260px,1fr)] md:items-start md:gap-md';

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {}).find((v) => typeof v === 'string') ?? null);
const canSave = computed(() => props.session && !props.blockReason && props.students.length > 0);

function pickSession(event) {
    const value = event?.target ? event.target.value : event;
    router.get(route('teacher.remarks', props.classroom.id), { session: value });
}
</script>

<template>
    <div class="mx-auto max-w-7xl space-y-lg pb-24 md:pb-0">
        <UiForm id="remarks-form" :key="session?.id ?? 'none'" :action="route('teacher.remarks.store', classroom.id)" method="post" class="space-y-lg">
            <input v-if="session" type="hidden" name="class_session_id" :value="session.id" />

            <UiPageHeader title="Nhận xét buổi học cho từng học sinh" :back="route('teacher.home')" back-label="Về lịch dạy">
                <template #meta>
                    <div class="flex flex-wrap items-center gap-sm">
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">class</span>Lớp {{ classroom.name }}</span>
                        <template v-if="session">
                            <span class="h-1 w-1 rounded-full bg-outline-variant"></span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">event</span>{{ session.label }}</span>
                            <UiBadge v-if="recordState === 'draft'" color="warning">Bản nháp</UiBadge>
                            <UiBadge v-else-if="recordState" color="success">Đã lưu</UiBadge>
                        </template>
                    </div>
                </template>
                <template v-if="canSave" #actions>
                    <UiButton type="submit" name="action" value="draft" variant="secondary" icon="save">Lưu nháp</UiButton>
                    <UiButton type="submit" name="action" value="final" icon="check_circle">Lưu nhận xét</UiButton>
                </template>
            </UiPageHeader>

            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

            <SessionTrialGuests :guests="trialGuests" />

            <div v-if="!session" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <UiEmptyState v-if="recentSessions.length" icon="event_busy" title="Lớp không có buổi học trong ngày này" description="Chọn buổi cần nhận xét ở ô “Nhận xét buổi khác” bên dưới." />
                <UiEmptyState v-else icon="event_busy" title="Lớp chưa có buổi học nào" description="Lớp chưa được xếp thời khóa biểu. Liên hệ Học vụ để kiểm tra TKB của lớp." />
            </div>
            <UiAlert v-else-if="blockReason" type="warning">{{ blockReason }}</UiAlert>
            <div v-else-if="!students.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <UiEmptyState icon="group_off" title="Chưa có học viên" description="Buổi học này chưa có học viên nào trong danh sách lớp." />
            </div>
            <!-- Không đặt overflow-hidden ở màn nhỏ: sẽ làm hỏng thanh lưu sticky. -->
            <div v-else class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm md:overflow-hidden">
                <div class="custom-scrollbar md:overflow-x-auto">
                    <div class="md:min-w-[1320px]">
                        <div :class="['hidden border-b border-outline-variant bg-surface-container-low px-md py-3 font-label text-label uppercase tracking-wider text-on-surface-variant', grid]">
                            <span>Học sinh</span><span>Điểm danh</span>
                            <span v-for="[key, label] in FIELDS" :key="key">{{ label }}</span>
                            <span>Nhận xét chi tiết</span>
                        </div>
                        <div class="divide-y divide-surface-container">
                            <div v-for="student in students" :key="student.id" :class="['space-y-sm p-md md:space-y-0 md:px-md md:py-sm', grid, student.absent ? 'bg-surface-container-low/60' : '']" data-testid="remark-row">
                                <div class="flex items-center justify-between gap-sm md:contents">
                                    <div class="flex min-w-0 items-center gap-sm">
                                        <UiAvatar :name="student.name" size="sm" />
                                        <span class="font-body-medium text-body-medium font-semibold text-on-surface md:font-normal">{{ student.name }}</span>
                                    </div>
                                    <div class="shrink-0 whitespace-nowrap md:pt-xs">
                                        <span v-if="student.att_status === 'present'" class="inline-flex items-center gap-xs rounded bg-tertiary-fixed/40 px-sm py-[2px] font-caption text-caption font-semibold text-on-tertiary-fixed-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">check</span>Có mặt</span>
                                        <span v-else-if="student.att_status === 'late'" class="inline-flex items-center gap-xs rounded bg-warning-container px-sm py-[2px] font-caption text-caption font-semibold text-on-warning-container"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>Đi muộn</span>
                                        <span v-else-if="student.absent" class="inline-flex items-center gap-xs rounded bg-error-container px-sm py-[2px] font-caption text-caption font-semibold text-on-error-container"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">close</span>Vắng mặt</span>
                                        <span v-else class="inline-flex items-center gap-xs rounded bg-surface-container-high px-sm py-[2px] font-caption text-caption font-semibold text-on-surface-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">help</span>Chưa điểm danh</span>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-sm md:contents">
                                    <label v-for="([key, label, placeholder], index) in FIELDS" :key="key" :class="['block', index >= 2 ? 'col-span-2' : '']">
                                        <span class="mb-[2px] block font-caption text-caption text-on-surface-variant md:hidden">{{ label }}</span>
                                        <input
                                            type="text"
                                            :name="`remarks[${student.id}][${key}]`"
                                            :value="student.remark[key]"
                                            :placeholder="student.absent ? '-' : placeholder"
                                            :aria-label="`${label} — ${student.name}`"
                                            :disabled="student.absent"
                                            :class="cell"
                                        />
                                    </label>
                                </div>
                                <label class="block">
                                    <span class="mb-[2px] block font-caption text-caption text-on-surface-variant md:hidden">Nhận xét chi tiết</span>
                                    <textarea
                                        :name="`remarks[${student.id}][comment]`"
                                        rows="2"
                                        :aria-label="`Nhận xét chi tiết — ${student.name}`"
                                        :disabled="student.absent"
                                        :placeholder="student.absent ? 'Học sinh vắng mặt...' : 'Nhận xét chi tiết về quá trình học tập trong buổi học này...'"
                                        :class="cell"
                                        :value="student.remark.comment"
                                    ></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Điện thoại: thanh lưu bám ngay trên thanh điều hướng dưới để không phải cuộn hết danh sách. -->
                <div class="sticky bottom-[72px] z-20 flex flex-row justify-end gap-sm border-t border-outline-variant bg-surface-container-lowest p-md shadow-level-3 md:static md:shadow-none">
                    <UiButton type="submit" name="action" value="draft" variant="secondary" class="flex-1 md:flex-none">Lưu nháp</UiButton>
                    <UiButton type="submit" name="action" value="final" icon="save" class="flex-1 md:flex-none">Lưu nhận xét</UiButton>
                </div>
            </div>
        </UiForm>

        <!-- Chọn buổi khác -->
        <div v-if="recentSessions.length" class="flex flex-col gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md sm:flex-row sm:items-center">
            <label for="remark-session" class="shrink-0 font-label-caps text-label-caps uppercase text-on-surface-variant">Nhận xét buổi khác</label>
            <UiSelect id="remark-session" name="session" :value="session?.id ?? ''" class="flex-1" @change="pickSession">
                <!-- Chưa chọn buổi: ô chọn để trống thay vì hiện buổi đầu danh sách mà trang không mở. -->
                <option v-if="!session" value="" selected disabled>— Chọn buổi —</option>
                <option v-for="s in recentSessions" :key="s.id" :value="s.id" :selected="session && session.id === s.id" :disabled="s.disabled">{{ s.label }}</option>
            </UiSelect>
        </div>
    </div>

    <TeacherBottomNav />
</template>
