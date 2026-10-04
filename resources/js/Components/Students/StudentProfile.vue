<script setup>
/**
 * Hồ sơ học sinh — dùng chung cho "Chi tiết hồ sơ học sinh" (mockup chi-tiet-ho-so-hoc-sinh-desktop) và
 * "Hồ sơ học sinh (phân quyền)" (mockup chi-tiet-ho-so-hoc-sinh-phan-quyen). Server chỉ gửi phần người xem có quyền
 * (StudentProfileController::profileProps):
 *   canEdit → form sửa; không có quyền: ô khóa + "Bạn không có quyền sửa thông tin này"
 *   canChangeStatus → menu "Đổi trạng thái"; không có quyền: nút khóa + "Quyền xem duy nhất"
 *   canViewAcademic → lộ trình buổi học, lớp học, chuyên cần, chăm sóc tháng đầu, lịch sử điểm danh
 *   canViewContact → SĐT, email, địa chỉ · canViewTuition → thông tin học phí
 * Không có trạng thái "Học thử" (A6 Q5: hồ sơ chỉ tồn tại sau khi chốt).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';

// Trang truyền nguyên $attrs (mọi prop hồ sơ); chỉ nhận prop khai báo dưới đây, không đổ thuộc tính lạ ra HTML.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    student: { type: Object, required: true },
    viewerRoleLabel: { type: String, default: 'Người dùng' },
    statusOptions: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    canChangeStatus: { type: Boolean, default: false },
    canViewAcademic: { type: Boolean, default: false },
    canViewContact: { type: Boolean, default: false },
    canViewTuition: { type: Boolean, default: false },
    canAssignClass: { type: Boolean, default: false },
    scopedView: { type: Boolean, default: false },
    sessions: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    attendanceStats: { type: Object, default: null },
    attendances: { type: Array, default: () => [] },
    linkableClasses: { type: Array, default: () => [] },
    care: { type: Object, default: null },
    tuition: { type: Object, default: null },
});

const cardClass = 'rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm';
const roadmapFilter = ref('all');
const showAll = ref(false);
const filterOptions = [
    { value: 'all', label: 'Tất cả buổi' },
    { value: 'done', label: 'Đã hoàn thành' },
    { value: 'upcoming', label: 'Sắp tới' },
    { value: 'cancelled', label: 'Đã hủy' },
];
const visibleSession = (session, idx) => (showAll.value || idx < 10) && (roadmapFilter.value === 'all' || roadmapFilter.value === session.state);
const otherStatuses = computed(() => props.statusOptions.filter((s) => s.value !== props.student.status));
const circle = 364.4;
const dashOffset = computed(() => circle - (circle * (props.attendanceStats?.rate ?? 0)) / 100);
const pad2 = (n) => String(n).padStart(2, '0');
const taskTone = (status) => (status === 'completed' ? 'text-tertiary' : status === 'overdue' ? 'text-error' : 'text-warning');
const receiptUrl = computed(() => route('tuition.receipts.create', { student_id: props.student.id }));
// Lịch sử thu học phí đầy đủ (mọi đợt nộp, còn nợ sau từng đợt) — modal Tuition/StudentPayments.
const paymentsUrl = computed(() => route('tuition.students.payments', props.student.id));
const openPayments = () => openRemoteModal(paymentsUrl.value, { size: '2xl' });
</script>

<template>
    <div class="space-y-lg">
        <!-- 1. Thông tin cơ bản -->
        <section class="grid grid-cols-1 gap-lg lg:grid-cols-12">
            <div :class="[cardClass, 'flex flex-col items-center gap-md p-lg text-center lg:col-span-4']">
                <div class="relative">
                    <UiAvatar :name="student.name" class="!h-28 !w-28 !text-h1 ring-4 ring-primary-fixed" />
                    <span class="absolute bottom-1 right-1 flex h-8 w-8 items-center justify-center rounded-full bg-primary-container text-white shadow" title="Học viên" aria-hidden="true">
                        <span class="material-symbols-outlined text-[18px]">school</span>
                    </span>
                </div>
                <div class="space-y-xs">
                    <h2 class="font-h2 text-h2 text-on-surface">{{ student.name }}</h2>
                    <p class="flex items-center justify-center gap-xs font-body-small text-body-small text-on-surface-variant">
                        <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">cake</span>
                        {{ student.dob_label ?? 'Chưa cập nhật ngày sinh' }}
                    </p>
                    <div v-if="canViewContact" data-section="contact" class="space-y-xs">
                        <p class="flex items-center justify-center gap-xs font-code text-code text-on-surface">
                            <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">call</span>{{ student.phone || '—' }}
                        </p>
                        <p v-if="student.email" class="font-caption text-caption text-on-surface-variant">{{ student.email }}</p>
                        <p v-if="scopedView" class="font-caption text-caption text-on-surface-variant">Cơ sở: {{ student.branch ?? '—' }}</p>
                    </div>
                    <p v-else class="font-caption text-caption italic text-on-surface-variant">Thông tin liên hệ ẩn theo phân quyền</p>
                </div>
                <div class="grid w-full grid-cols-2 gap-sm border-t border-surface-container pt-md">
                    <div class="rounded-lg bg-surface-container-low p-sm">
                        <p class="font-label text-label uppercase text-on-surface-variant">Mã học sinh</p>
                        <p class="font-code text-code font-semibold text-primary">{{ student.code }}</p>
                    </div>
                    <div class="rounded-lg bg-surface-container-low p-sm">
                        <p class="font-label text-label uppercase text-on-surface-variant">Ngày nhập học</p>
                        <p class="font-code text-code font-semibold text-on-surface">{{ student.created_at ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <div :class="[cardClass, 'p-lg lg:col-span-8']">
                <div class="mb-md flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">{{ canEdit ? 'Chỉnh sửa thông tin' : 'Thông tin học sinh' }}</h3>
                    <UiBadge color="success" pill>Quyền: {{ viewerRoleLabel }}</UiBadge>
                </div>
                <UiForm v-if="canEdit" :action="route('students.update', student.id)" method="put" class="space-y-md" data-testid="student-edit-form">
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        <UiInput name="name" label="Họ và tên" required :value="student.name" />
                        <UiInput name="phone" type="tel" label="Số điện thoại" required :value="student.phone" />
                        <UiInput name="email" type="email" label="Email liên hệ" :value="student.email" placeholder="hocvien@menglish.edu.vn" />
                        <UiInput name="target" label="Mục tiêu học tập" :value="student.target" placeholder="VD: IELTS 6.5, Cambridge Starters..." />
                        <UiInput name="parent_name" label="Họ tên phụ huynh" :value="student.parent_name" placeholder="VD: Nguyễn Thị Hoa" />
                        <UiInput name="parent_phone" type="tel" label="SĐT phụ huynh (nhận kết quả Zalo)" :value="student.parent_phone" placeholder="VD: 0987 654 321" />
                    </div>
                    <UiInput name="school" label="Trường học" :value="student.school" placeholder="VD: Trường THCS Đoàn Thị Điểm" />
                    <UiTextarea name="address" label="Địa chỉ liên hệ" rows="2" :value="student.address" placeholder="Nhập địa chỉ của học viên..." />
                    <UiTextarea name="notes" label="Ghi chú đặc biệt" rows="3" :value="student.notes" placeholder="Nhập ghi chú về học sinh (ví dụ: dị ứng, sở thích, mục tiêu học tập...)" />
                    <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                        <UiButton variant="secondary" :href="route('students.show', student.id)">Hủy</UiButton>
                        <UiButton type="submit">Lưu thay đổi</UiButton>
                    </div>
                </UiForm>
                <div v-else class="space-y-md">
                    <UiInput id="ro_school" label="Trường học" :value="student.school" disabled />
                    <template v-if="canViewContact">
                        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                            <UiInput id="ro_parent_name" label="Họ tên phụ huynh" :value="student.parent_name" disabled />
                            <UiInput id="ro_parent_phone" label="SĐT phụ huynh" :value="student.parent_phone" disabled />
                        </div>
                        <UiTextarea id="ro_address" label="Địa chỉ liên hệ" rows="2" :value="student.address" disabled />
                    </template>
                    <UiTextarea id="ro_notes" label="Ghi chú đặc biệt" rows="3" :value="student.notes" disabled />
                    <div class="grid grid-cols-2 gap-md">
                        <UiInput id="ro_target" label="Mục tiêu học tập" :value="student.target" disabled />
                        <UiInput id="ro_current_class" label="Lớp đang học" :value="student.current_class ?? 'Chưa xếp lớp'" disabled />
                    </div>
                    <div class="flex items-center justify-end gap-sm border-t border-surface-container pt-md">
                        <UiButton variant="secondary" :href="route('students.index')">Hủy</UiButton>
                        <div class="text-right">
                            <UiButton disabled>Lưu thay đổi</UiButton>
                            <p class="mt-xs font-caption text-caption text-error">Bạn không có quyền sửa thông tin này</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 2. Trạng thái vòng đời -->
        <section :class="[cardClass, 'flex flex-col gap-md p-md sm:flex-row sm:items-center sm:justify-between']">
            <div class="flex items-center gap-lg">
                <div>
                    <span class="font-label text-label uppercase text-on-surface-variant">Trạng thái hiện tại</span>
                    <div class="mt-xs"><UiBadge :color="student.status_color" pill>{{ student.status_label }}</UiBadge></div>
                </div>
                <div class="hidden h-8 w-px bg-outline-variant sm:block"></div>
                <div>
                    <span class="font-label text-label uppercase text-on-surface-variant">Thời gian cập nhật</span>
                    <p class="mt-xs font-code text-code text-on-surface">{{ student.updated_label }}</p>
                </div>
            </div>
            <div v-if="canChangeStatus" class="flex flex-wrap items-center gap-sm">
                <!-- Kết thúc bảo lưu: về Đang học (lớp đã khai giảng) / Chờ khai giảng, bỏ đóng băng học phí, báo Học vụ. -->
                <UiForm
                    v-if="student.status === 'deferred'"
                    :action="route('students.end-deferral', student.id)"
                    method="post"
                    confirm="Kết thúc bảo lưu cho học viên này? Học viên sẽ về Đang học (hoặc Chờ khai giảng nếu lớp chưa khai giảng) và công nợ, nhắc nợ chạy lại."
                    confirm-label="Kết thúc bảo lưu"
                >
                    <UiButton type="submit" variant="secondary" size="sm" icon="play_circle">
                        Kết thúc bảo lưu
                        <span v-if="student.deferred_until" class="text-on-surface-variant">(hạn {{ student.deferred_until }})</span>
                    </UiButton>
                </UiForm>
                <UiDropdown width="56">
                    <template #trigger="{ open }">
                        <UiButton variant="secondary" icon="sync" :aria-expanded="open ? 'true' : 'false'">
                            Đổi trạng thái <span class="material-symbols-outlined text-[18px]" aria-hidden="true">expand_more</span>
                        </UiButton>
                    </template>
                    <template #content>
                        <UiForm
                            v-for="option in otherStatuses"
                            :key="option.value"
                            :action="route('students.status.update', student.id)"
                            method="put"
                            :confirm="option.value === 'dropped' ? 'Chuyển sang Thôi học sẽ đưa học viên ra khỏi danh sách lớp đang học (vẫn giữ lịch sử). Tiếp tục?' : null"
                            :confirm-label="option.value === 'dropped' ? 'Chuyển sang Thôi học' : 'Đồng ý'"
                            :danger="option.value === 'dropped'"
                        >
                            <input type="hidden" name="status" :value="option.value" />
                            <button type="submit" role="menuitem" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small text-on-surface hover:bg-surface-container-low">
                                <UiBadge :color="option.color" class="!bg-transparent !px-0">{{ option.label }}</UiBadge>
                            </button>
                        </UiForm>
                    </template>
                </UiDropdown>
            </div>
            <div v-else class="text-right">
                <UiButton variant="secondary" icon="sync" disabled>Đổi trạng thái <span class="material-symbols-outlined text-[18px]" aria-hidden="true">lock</span></UiButton>
                <p class="mt-xs font-caption text-caption text-on-surface-variant">Quyền xem duy nhất</p>
            </div>
        </section>

        <!-- 3. Lộ trình học tập & Danh sách buổi học (buổi học thật + điểm danh của chính học viên) -->
        <UiDataTable v-if="canViewAcademic" id="roadmap" class="shadow-sm" data-section="roadmap" min-width="860px">
            <template #header>
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">auto_stories</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Lộ trình học tập &amp; Danh sách buổi học</h3>
                </div>
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">filter_list</span>
                    <UiSelect v-model="roadmapFilter" aria-label="Lọc buổi học" class="py-xs" :options="filterOptions" />
                    <UiButton v-if="!scopedView" variant="ghost" icon="download" :href="route('students.show', { id: student.id, export: 'roadmap' })" native title="Tải lộ trình (Excel)" aria-label="Tải lộ trình" />
                </div>
            </template>

            <UiEmptyState
                v-if="!sessions.length"
                icon="event_busy"
                title="Chưa có buổi học nào"
                :description="!classes.length ? 'Học viên chưa được xếp lớp nên chưa có lộ trình buổi học.' : 'Lớp của học viên chưa được sinh lịch buổi học.'"
            />
            <table v-else class="font-body-small text-body-small">
                <thead>
                    <tr>
                        <th>Ngày học</th>
                        <th>Thời gian</th>
                        <th>Nội dung bài học</th>
                        <th>Giáo viên</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Điểm danh</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(session, idx) in sessions" v-show="visibleSession(session, idx)" :key="session.id">
                        <td class="whitespace-nowrap font-semibold">{{ session.date }}</td>
                        <td class="whitespace-nowrap font-code">{{ session.time }}</td>
                        <td>
                            <span class="block font-semibold">{{ session.heading }}</span>
                            <span v-if="session.sub !== null" class="block text-on-surface-variant">{{ session.sub }}</span>
                            <span class="block font-caption text-caption text-on-surface-variant">
                                {{ session.class_name }}<template v-if="session.room"> · {{ session.room }}</template>
                                <template v-if="session.type === 'makeup'"> · <span class="font-semibold text-warning">Học bù</span></template>
                                <template v-else-if="session.type === 'support'"> · <span class="font-semibold text-secondary">Phụ đạo</span></template>
                            </span>
                        </td>
                        <td>
                            <span v-if="session.teacher" class="flex items-center gap-sm whitespace-nowrap"><UiAvatar :name="session.teacher" size="sm" />{{ session.teacher }}</span>
                            <span v-else class="text-on-surface-variant">Chưa gán GV</span>
                        </td>
                        <td class="whitespace-nowrap">
                            <span v-if="session.state === 'cancelled'" class="inline-flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">event_busy</span>Đã hủy</span>
                            <span v-else-if="session.state === 'done'" class="inline-flex items-center gap-xs font-semibold text-tertiary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>Đã hoàn thành</span>
                            <span v-else-if="session.is_next" class="inline-flex items-center gap-xs font-semibold text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>Sắp diễn ra</span>
                            <span v-else class="inline-flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">radio_button_unchecked</span>Chưa bắt đầu</span>
                        </td>
                        <td class="whitespace-nowrap text-right font-semibold">
                            <span v-if="session.attendance" :class="session.attendance.tone">{{ session.attendance.label }}</span>
                            <span v-else-if="session.state === 'cancelled'" class="text-on-surface-variant">—</span>
                            <span v-else-if="session.state === 'upcoming'" class="text-on-surface-variant">Sắp tới</span>
                            <span v-else class="text-warning">Chưa điểm danh</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template v-if="sessions.length > 10" #footer>
                <div class="p-sm text-center">
                    <button type="button" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline" @click="showAll = !showAll">
                        <span>{{ showAll ? 'Thu gọn' : `Xem toàn bộ ${sessions.length} buổi học` }}</span>
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ showAll ? 'expand_less' : 'expand_more' }}</span>
                    </button>
                </div>
            </template>
        </UiDataTable>

        <!-- 4. Hồ sơ tổng hợp: Lớp học · Chuyên cần · Học phí -->
        <div class="grid grid-cols-1 gap-lg lg:grid-cols-12">
            <template v-if="canViewAcademic">
                <div :class="[cardClass, 'flex flex-col gap-md p-md lg:col-span-4']" data-section="classes">
                    <div class="flex items-center gap-sm border-b border-surface-container pb-sm">
                        <span class="material-symbols-outlined text-secondary" aria-hidden="true">class</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Lớp học hiện tại</h3>
                    </div>
                    <div class="flex-1 space-y-sm">
                        <div v-for="cls in classes" :key="cls.id" class="space-y-sm rounded-lg border border-secondary-fixed bg-secondary-fixed/30 p-md">
                            <div>
                                <p class="font-body-medium text-body-medium font-semibold text-on-surface">
                                    {{ cls.name }}
                                    <span :class="['font-caption text-caption', cls.is_main ? 'text-secondary' : 'text-on-surface-variant']">({{ cls.is_main ? 'Lớp chính' : 'Liên kết' }})</span>
                                </p>
                                <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">location_on</span>{{ cls.branch ?? 'Chưa phân cơ sở' }}
                                </p>
                            </div>
                            <div class="grid grid-cols-2 gap-sm font-caption text-caption">
                                <div>
                                    <p class="font-label text-label uppercase text-on-surface-variant">Lịch học</p>
                                    <p class="text-on-surface">{{ cls.schedule_text || 'Chưa xếp lịch' }}</p>
                                    <p class="text-on-surface-variant">{{ cls.teacher ? 'GV: ' + cls.teacher : 'Chưa có GV' }}</p>
                                </div>
                                <div>
                                    <p class="font-label text-label uppercase text-on-surface-variant">Thời gian</p>
                                    <p class="font-code text-on-surface">{{ cls.period }}</p>
                                    <p v-if="cls.remaining" class="text-on-surface-variant">{{ cls.remaining }}</p>
                                </div>
                            </div>
                        </div>
                        <UiEmptyState v-if="!classes.length" icon="group_off" title="Chưa xếp lớp" description="Học viên chưa thuộc lớp nào đang hoạt động." />
                    </div>

                    <UiButton variant="secondary" class="w-full" href="#roadmap" native>Chi tiết lộ trình</UiButton>

                    <template v-if="!scopedView && canAssignClass">
                        <p v-if="student.dropped" class="font-caption text-caption text-error">Học viên đã thôi học, không liên kết thêm lớp.</p>
                        <p v-else-if="!linkableClasses.length" class="font-caption text-caption text-on-surface-variant">Không còn lớp phù hợp để liên kết thêm.</p>
                        <UiForm v-else :action="route('students.link-class', student.id)" method="post" class="space-y-sm border-t border-surface-container pt-md" data-testid="link-class-form">
                            <UiSelect id="link_class_id" label="Liên kết lớp khác" name="class_id" required placeholder="-- Chọn lớp --" :options="linkableClasses" />
                            <UiButton type="submit" variant="secondary" icon="add_link" class="w-full">Liên kết lớp</UiButton>
                        </UiForm>
                    </template>
                </div>

                <div :class="[cardClass, 'flex flex-col items-center gap-md p-md text-center lg:col-span-3']" data-section="academic">
                    <div class="flex w-full items-center gap-sm border-b border-surface-container pb-sm text-left">
                        <span class="material-symbols-outlined text-tertiary" aria-hidden="true">how_to_reg</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Chuyên cần</h3>
                    </div>
                    <UiEmptyState v-if="attendanceStats.rate === null" icon="fact_check" title="Chưa có điểm danh" description="Chưa có buổi nào được điểm danh cho học viên." />
                    <div v-else class="relative flex h-32 w-32 items-center justify-center">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 128 128" aria-hidden="true">
                            <circle class="text-surface-container-high" cx="64" cy="64" fill="transparent" r="58" stroke="currentColor" stroke-width="8"></circle>
                            <circle class="text-tertiary" cx="64" cy="64" fill="transparent" r="58" stroke="currentColor" :stroke-dasharray="circle" :stroke-dashoffset="dashOffset" stroke-width="10" stroke-linecap="round"></circle>
                        </svg>
                        <span class="absolute font-h2 text-h2 text-on-surface">{{ attendanceStats.rate }}%</span>
                    </div>
                    <div class="w-full space-y-xs font-body-small text-body-small">
                        <div class="flex justify-between"><span class="text-on-surface-variant">Tổng số buổi:</span><span class="font-code font-semibold">{{ attendanceStats.scheduled }}</span></div>
                        <div class="flex justify-between"><span class="text-on-surface-variant">Buổi đã điểm danh:</span><span class="font-code font-semibold">{{ attendanceStats.recorded }}</span></div>
                        <div class="flex justify-between"><span class="text-on-surface-variant">Có mặt / đi muộn:</span><span class="font-code font-semibold text-tertiary">{{ attendanceStats.present }}</span></div>
                        <div class="flex justify-between"><span class="text-on-surface-variant">Số buổi vắng:</span><span class="font-code font-semibold text-error">{{ pad2(attendanceStats.absent) }}</span></div>
                    </div>
                    <UiButton v-if="attendances.length" variant="secondary" class="w-full" href="#attendance-history" native>Lịch sử điểm danh</UiButton>
                </div>
            </template>
            <div v-else :class="[cardClass, 'flex items-center gap-md p-md lg:col-span-7']">
                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">lock</span>
                <p class="font-body-small text-body-small text-on-surface-variant">Lớp học, lộ trình và chuyên cần ẩn theo phân quyền (cần quyền xem điểm danh học viên).</p>
            </div>

            <div v-if="canViewTuition" :class="[cardClass, 'flex flex-col gap-md p-md lg:col-span-5']" data-section="tuition">
                <div class="flex items-center justify-between border-b border-surface-container pb-sm">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">payments</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Thông tin học phí</h3>
                    </div>
                    <a
                        v-if="can('tuition.create')"
                        :href="receiptUrl"
                        class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline"
                        @click.prevent="openRemoteModal(receiptUrl, { size: '4xl' })"
                    >
                        Thêm phiếu thu <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_circle</span>
                    </a>
                </div>
                <template v-if="tuition">
                    <button
                        type="button"
                        class="grid grid-cols-3 gap-sm rounded-lg bg-surface-container-low p-sm text-center font-caption text-caption transition-colors hover:bg-surface-container focus-visible:outline-2 focus-visible:outline-primary"
                        title="Xem toàn bộ lịch sử thu học phí"
                        @click="openPayments"
                    >
                        <div><p class="text-on-surface-variant">Tổng học phí</p><p class="font-code font-semibold text-on-surface">{{ formatMoney(tuition.final_amount) }}</p></div>
                        <div><p class="text-on-surface-variant">Đã thanh toán</p><p class="font-code font-semibold text-tertiary">{{ formatMoney(tuition.paid_amount) }}</p></div>
                        <div><p class="text-on-surface-variant">Công nợ</p><p class="font-code font-semibold text-error">{{ formatMoney(tuition.debt_amount) }}</p></div>
                    </button>
                    <div class="max-h-[220px] flex-1 space-y-sm overflow-y-auto pr-xs">
                        <button
                            v-for="receipt in tuition.receipts"
                            :key="receipt.id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-lg border border-outline-variant p-sm text-left transition-colors hover:border-primary hover:bg-surface-container-low focus-visible:outline-2 focus-visible:outline-primary"
                            title="Xem toàn bộ lịch sử thu học phí"
                            @click="openPayments"
                        >
                            <div class="flex items-center gap-sm">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">receipt_long</span></span>
                                <div>
                                    <p class="font-code text-code font-semibold">#{{ receipt.number }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ receipt.date }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-code text-code font-semibold">{{ formatMoney(receipt.amount) }}</p>
                                <span :class="['font-caption text-caption font-semibold', receipt.tone]">{{ receipt.label }}</span>
                            </div>
                        </button>
                        <p v-if="!tuition.receipts.length" class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Chưa có phiếu thu nào được ghi nhận cho học viên.</p>
                    </div>
                    <div class="flex items-center justify-between border-t border-surface-container pt-sm">
                        <div>
                            <p class="font-label text-label uppercase text-on-surface-variant">Tổng học phí đã nộp</p>
                            <p class="font-h3 text-h3 text-primary">{{ formatMoney(tuition.paid_amount) }}</p>
                        </div>
                        <UiButton variant="secondary" size="sm" icon="history" :href="paymentsUrl" modal="2xl">Xem toàn bộ lịch sử</UiButton>
                    </div>
                </template>
                <UiEmptyState v-else icon="receipt_long" title="Chưa có sổ học phí" description="Học viên chưa được lập sổ học phí." />
            </div>
        </div>

        <!-- Chăm sóc tháng đầu: 3 mốc gate hoa hồng A6 — Buổi 1, Buổi 4–5, Đủ 30 ngày -->
        <section v-if="canViewAcademic && care" :class="[cardClass, 'overflow-hidden']" data-section="first-month-care">
            <div class="flex flex-col justify-between gap-sm border-b border-surface-container p-md sm:flex-row sm:items-center">
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">volunteer_activism</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Chăm sóc tháng đầu</h3>
                    <UiBadge :color="care.completed >= 3 ? 'success' : 'warning'" pill>{{ care.completed }}/3 mốc</UiBadge>
                    <UiBadge v-if="care.overdue" color="error" pill>Quá hạn chăm sóc</UiBadge>
                </div>
                <div class="font-caption text-caption text-on-surface-variant">
                    <template v-if="care.closing">Ngày chốt: <strong class="text-on-surface">{{ care.closing }}</strong> · </template>
                    <template v-if="care.start">Bắt đầu học: <strong class="text-on-surface">{{ care.start }}</strong></template>
                    <template v-else>Chưa có buổi học/xếp lớp</template>
                    <template v-if="care.customer_id && can('lead.view')">
                        · <Link :href="route('crm.customers.show', care.customer_id)" class="font-semibold text-primary hover:underline">Checklist bên CRM</Link>
                    </template>
                </div>
            </div>
            <ul class="divide-y divide-surface-container">
                <li v-for="item in care.items" :key="item.key" class="flex flex-col justify-between gap-sm px-md py-sm font-body-small text-body-small sm:flex-row sm:items-center">
                    <div class="flex items-start gap-sm">
                        <span :class="['material-symbols-outlined text-[18px]', item.done ? 'text-tertiary' : 'text-outline-variant']" aria-hidden="true">{{ item.done ? 'check_circle' : 'radio_button_unchecked' }}</span>
                        <div>
                            <div class="flex flex-wrap items-center gap-xs font-semibold text-on-surface">
                                {{ item.label }}
                                <UiBadge v-if="item.overdue" color="error" :dot="false">Quá hạn chăm sóc</UiBadge>
                            </div>
                            <div class="font-caption text-caption text-on-surface-variant">
                                {{ item.due ? 'Hạn SLA: ' + item.due : item.waiting }}
                                <template v-if="item.crm_done_at"> · Đã đánh dấu bên CRM {{ item.crm_done_at }}</template>
                            </div>
                        </div>
                    </div>
                    <div class="font-caption text-caption sm:text-right">
                        <template v-if="item.task">
                            <div class="text-on-surface-variant">
                                Đã giao task cho <span class="font-semibold text-on-surface" :title="item.task.assignee_email">{{ item.task.assignee }}</span>
                                · <span :class="['font-semibold', taskTone(item.task.status)]">{{ item.task.status_label }}</span>
                            </div>
                            <div v-if="item.task.penalty_code" class="font-semibold text-error">
                                Quá SLA ·
                                <Link v-if="can('violation.view')" :href="route('penalties.index', { search: item.task.penalty_code })" class="hover:underline">biên bản {{ item.task.penalty_code }}</Link>
                                <template v-else>biên bản {{ item.task.penalty_code }}</template>
                            </div>
                        </template>
                        <span v-else class="text-on-surface-variant">Chưa giao task</span>
                    </div>
                </li>
            </ul>
        </section>

        <UiDataTable v-if="canViewAcademic && attendances.length" id="attendance-history" class="shadow-sm" min-width="560px">
            <template #header>
                <h3 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-tertiary" aria-hidden="true">fact_check</span>
                    Lịch sử điểm danh
                </h3>
            </template>
            <div class="custom-scrollbar max-h-[420px] overflow-y-auto">
                <table class="font-body-small text-body-small">
                    <thead>
                        <tr><th>Ngày</th><th>Lớp</th><th>Trạng thái</th><th>Ghi chú</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="att in attendances" :key="att.id">
                            <td class="font-code">{{ att.date }}</td>
                            <td class="font-semibold">{{ att.class_name }}</td>
                            <td :class="['font-semibold', att.tone]">{{ att.status_label }}</td>
                            <td class="text-on-surface-variant">{{ att.note || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </UiDataTable>
    </div>
</template>
