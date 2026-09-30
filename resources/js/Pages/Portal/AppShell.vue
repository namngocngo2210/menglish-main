<script setup>
/**
 * Cổng Phụ huynh / Học sinh — App Shell (MH #1): lời chào, lưới chức năng chính (kèm số liệu thật), thông tin tài khoản.
 * Trên điện thoại: thanh điều hướng đáy là điều hướng chính, ẩn tiêu đề/nút quay lại và dải tab.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortalBottomNav from './PortalBottomNav.vue';
import { route } from '@/lib/route';
import PortalPageHeader from './PortalPageHeader.vue';

defineOptions({ layout: { title: 'Cổng Phụ huynh / Học sinh', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    pendingHomeworksCount: { type: Number, default: 0 },
    submittedHomeworksCount: { type: Number, default: 0 },
    unreadNotifsCount: { type: Number, default: 0 },
    pronunciationCount: { type: Number, default: 0 },
    surveyCount: { type: Number, default: 0 },
    hasFeedback: { type: Boolean, default: false },
    backUrl: { type: String, default: null },
});

const studentOptions = computed(() => props.students.map((s) => ({ value: s.id, label: s.name })));
const params = computed(() => ({ studentId: props.student?.id ?? null }));

function switchStudent(event) {
    router.visit(route('portal.app-shell') + '?student_id=' + event.target.value);
}
</script>

<template>
    <PortalPageHeader title="Cổng Phụ huynh / Học sinh" icon="smartphone" :back="backUrl">
        <template #actions>
            <UiButton icon="cottage" :href="route('portal.student.home', params)">Vào Trang chủ</UiButton>
        </template>
    </PortalPageHeader>

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-20 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <!-- Thanh trên -->
        <header class="sticky top-0 z-40 flex h-16 w-full items-center justify-between border-b border-surface-container-highest bg-background px-4 dark:border-inverse-surface dark:bg-inverse-surface">
            <div class="text-2xl font-bold tracking-tight text-primary">MENGLISH</div>
            <div class="flex items-center gap-2">
                <UiSelect v-if="students.length > 1" class="!min-w-0 py-1 text-xs font-semibold" aria-label="Chọn học viên" :options="studentOptions" :value="student?.id" @change="switchStudent" />
                <Link :href="route('portal.student.home', params)" class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full bg-primary-container/10 text-primary transition-opacity duration-100 hover:opacity-80 active:scale-95">
                    <span class="material-symbols-outlined text-2xl">account_circle</span>
                </Link>
            </div>
        </header>

        <!-- Nội dung: lối vào các chức năng -->
        <div class="flex flex-1 flex-col gap-4 overflow-y-auto bg-[#F7F8FA] p-4">
            <!-- Lời chào -->
            <div class="rounded-2xl bg-gradient-to-r from-primary-container to-primary-container p-4 text-white shadow-md">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="rounded-full bg-surface-container-lowest/20 px-2 py-0.5 text-xs font-bold uppercase tracking-wider">Cổng Học Sinh &amp; Phụ Huynh</span>
                        <h2 class="mt-1 text-lg font-bold">Xin chào, {{ student?.name ?? 'Học viên' }}</h2>
                        <p class="mt-0.5 text-xs text-white/90">Lớp: {{ student?.class_name ?? 'Chưa xếp lớp' }}</p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-surface-container-lowest/20 text-white backdrop-blur-xs">
                        <span class="material-symbols-outlined text-2xl">school</span>
                    </div>
                </div>
            </div>

            <!-- Lưới chức năng chính -->
            <div>
                <h3 class="mb-2.5 px-1 text-xs font-bold uppercase tracking-wider text-on-surface-variant">Các chức năng chính</h3>
                <div class="grid grid-cols-2 gap-3">
                    <!-- Trang chủ -->
                    <Link :href="route('portal.student.home', params)" class="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-container/10 text-primary transition-transform group-hover:scale-105">
                                <span class="material-symbols-outlined text-[22px]">home</span>
                            </div>
                            <UiBadge v-if="student?.has_tuition" color="success" :dot="false">{{ formatMoney(student.tuition_total) }}</UiBadge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface transition group-hover:text-primary">Trang chủ</h4>
                            <p class="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">Thông tin học sinh &amp; học phí</p>
                        </div>
                        <span class="mt-auto flex items-center gap-0.5 text-xs font-bold text-primary">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </Link>

                    <!-- Học tập & Nộp bài -->
                    <Link :href="route('portal.student.homework', params)" class="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary/10 text-secondary transition-transform group-hover:scale-105">
                                <span class="material-symbols-outlined text-[22px]">upload_file</span>
                            </div>
                            <UiBadge :color="submittedHomeworksCount == 6 ? 'success' : 'secondary'" :dot="false">{{ submittedHomeworksCount }}/6 nộp</UiBadge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface transition group-hover:text-secondary">Học tập &amp; Nộp bài</h4>
                            <p class="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">Video, từ vựng, workbook</p>
                        </div>
                        <span class="mt-auto flex items-center gap-0.5 text-xs font-bold text-secondary">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </Link>

                    <!-- Luyện phát âm AI -->
                    <Link :href="route('portal.student.pronunciation', params)" class="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-error/10 text-error transition-transform group-hover:scale-105">
                                <span class="material-symbols-outlined text-[22px]">mic</span>
                            </div>
                            <UiBadge v-if="pronunciationCount > 0" color="error" :dot="false">{{ pronunciationCount }} bài</UiBadge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface transition group-hover:text-error">Luyện phát âm AI</h4>
                            <p class="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">Thu âm &amp; chấm giọng đọc</p>
                        </div>
                        <span class="mt-auto flex items-center gap-0.5 text-xs font-bold text-error">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </Link>

                    <!-- Hộp thư thông báo -->
                    <Link :href="route('portal.student.notifications', params)" class="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <div class="relative flex h-10 w-10 items-center justify-center rounded-xl bg-warning-container text-warning transition-transform group-hover:scale-105">
                                <span class="material-symbols-outlined text-[22px]">notifications</span>
                                <span v-if="unreadNotifsCount > 0" class="absolute right-0 top-0 h-2.5 w-2.5 rounded-full border border-white bg-error"></span>
                            </div>
                            <UiBadge v-if="unreadNotifsCount > 0" color="error" :dot="false">{{ unreadNotifsCount }} mới</UiBadge>
                            <UiBadge v-else color="neutral" :dot="false">Đã đọc</UiBadge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface transition group-hover:text-warning">Hộp thư Thông báo</h4>
                            <p class="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">Học phí, sinh nhật, nghỉ lễ</p>
                        </div>
                        <span class="mt-auto flex items-center gap-0.5 text-xs font-bold text-warning">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </Link>

                    <!-- Khảo sát chất lượng -->
                    <Link :href="route('portal.student.survey', params)" class="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-tertiary/10 text-tertiary transition-transform group-hover:scale-105">
                                <span class="material-symbols-outlined text-[22px]">assignment</span>
                            </div>
                            <UiBadge v-if="surveyCount > 0" color="success" :dot="false">{{ surveyCount }} đã làm</UiBadge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface transition group-hover:text-tertiary">Khảo sát Đánh giá</h4>
                            <p class="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">Cơ sở vật chất &amp; giáo trình</p>
                        </div>
                        <span class="mt-auto flex items-center gap-0.5 text-xs font-bold text-tertiary">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </Link>

                    <!-- Phụ huynh gửi feedback chặng học -->
                    <Link :href="route('portal.student.feedback', params)" class="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-container text-accent transition-transform group-hover:scale-105">
                                <span class="material-symbols-outlined text-[22px]">rate_review</span>
                            </div>
                            <span v-if="hasFeedback" class="rounded-md border border-accent/30 bg-accent-container px-1.5 py-0.5 text-xs font-bold text-accent">Đã phản hồi</span>
                            <UiBadge v-else color="neutral" :dot="false">Chưa gửi</UiBadge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface transition group-hover:text-accent">Gửi Feedback chặng</h4>
                            <p class="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">Đánh giá chặng học 5 sao</p>
                        </div>
                        <span class="mt-auto flex items-center gap-0.5 text-xs font-bold text-accent">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </Link>
                </div>
            </div>

            <!-- Thông tin tài khoản -->
            <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <div class="flex items-center justify-between border-b pb-2.5">
                    <h4 class="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                        <span class="material-symbols-outlined text-[18px] text-primary">verified_user</span>
                        Thông tin tài khoản
                    </h4>
                    <UiBadge color="success" pill>{{ student?.status_label ?? '—' }}</UiBadge>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <span class="block text-xs font-bold uppercase text-on-surface-subtle">Mã học viên</span>
                        <span class="font-mono font-bold text-on-surface">{{ student?.code ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold uppercase text-on-surface-subtle">Số điện thoại</span>
                        <span class="font-mono text-on-surface">{{ student?.phone ?? '—' }}</span>
                    </div>
                </div>
                <!-- Đổi mật khẩu / email đăng nhập: trang Tài khoản (bản rút gọn cho học viên) -->
                <Link :href="route('profile.edit')" class="flex items-center justify-between rounded-xl border border-surface-container-highest px-3 py-2 text-xs font-bold text-primary transition hover:border-primary-container">
                    <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[18px]">lock</span>Tài khoản &amp; đổi mật khẩu</span>
                    <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                </Link>
            </div>
        </div>

        <PortalBottomNav active-tab="home" :student="student" :unread-count="unreadCount" />
    </div>
</template>
