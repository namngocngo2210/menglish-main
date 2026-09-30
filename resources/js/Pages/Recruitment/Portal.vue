<script setup>
/**
 * Cổng tuyển dụng công khai (không đăng nhập, không khung ứng dụng): danh sách vị trí đang tuyển + form nộp CV online.
 * "Ứng tuyển vị trí này" điền sẵn vị trí, tin tuyển dụng và cơ sở vào form.
 */
import { reactive } from 'vue';
import { Head } from '@inertiajs/vue3';
import BareLayout from '@/Layouts/BareLayout.vue';

defineOptions({ layout: BareLayout });

defineProps({
    jobs: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
});

const apply = reactive({ job_posting_id: '', applying_position: '', branch_id: '' });

function selectPosition(job) {
    apply.job_posting_id = String(job.id);
    apply.applying_position = job.title;
    if (job.branch_id) apply.branch_id = String(job.branch_id);
}

function onSuccess() {
    Object.assign(apply, { job_posting_id: '', applying_position: '', branch_id: '' });
}
</script>

<template>
    <Head title="Cơ hội Nghề nghiệp & Tuyển dụng" />
    <div class="flex min-h-screen flex-col justify-between bg-surface-container-low font-sans text-on-surface antialiased">
        <!-- Header -->
        <header class="sticky top-0 z-30 border-b border-surface-container-highest bg-surface-container-lowest shadow-xs">
            <div class="mx-auto flex h-20 max-w-6xl items-center justify-between px-4 sm:px-6">
                <div class="flex items-center gap-3">
                    <img src="/images/menglish-logo.png" alt="MENGLISH Logo" class="h-10 w-auto object-contain" />
                    <div class="border-l border-outline-variant pl-3">
                        <span class="block text-xs font-extrabold uppercase tracking-wider text-primary">Tuyển Dụng &amp; Nhân Sự</span>
                        <span class="text-xs font-medium text-on-surface-variant">Hệ thống Anh ngữ MENGLISH</span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <UiButton href="#apply-form" native>Nộp CV ngay</UiButton>
                </div>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="relative overflow-hidden bg-gradient-to-br from-inverse-surface via-secondary-hover to-inverse-surface px-4 py-16 text-white sm:px-6">
            <div class="relative z-10 mx-auto max-w-4xl space-y-4 text-center">
                <span class="inline-block rounded-full border border-primary-container/30 bg-primary-container/20 px-3 py-1 text-xs font-bold uppercase tracking-wider text-primary-container">MENGLISH Career Opportunities</span>
                <h1 class="text-3xl font-black leading-tight tracking-tight sm:text-4xl md:text-5xl">Gia nhập Đội ngũ Giáo dục Tiên phong tại <span class="text-primary">MENGLISH</span></h1>
                <p class="mx-auto max-w-2xl text-sm leading-relaxed text-white/80 sm:text-base">
                    Môi trường làm việc trẻ trung, năng động, lộ trình thăng tiến minh bạch cùng chế độ đãi ngộ hấp dẫn dành cho Giảng viên và Nhân sự Vận hành.
                </p>
            </div>
        </section>

        <!-- Main Content -->
        <main class="mx-auto w-full max-w-6xl flex-1 space-y-12 px-4 py-12 sm:px-6">
            <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-12">
                <!-- Left: Danh sách vị trí tuyển dụng (7 cols) -->
                <div class="space-y-6 lg:col-span-7">
                    <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                        <h2 class="flex items-center gap-2 text-xl font-bold text-on-surface">
                            <span class="material-symbols-outlined text-primary">work</span>
                            Các vị trí đang tuyển dụng ({{ jobs.length }})
                        </h2>
                        <span class="text-xs text-on-surface-variant">Cập nhật hôm nay</span>
                    </div>

                    <div class="space-y-4">
                        <div v-for="job in jobs" :key="job.id" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-xs transition hover:border-primary-container/30 hover:shadow-md">
                            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                                <div>
                                    <h3 class="text-base font-bold text-on-surface">{{ job.title }}</h3>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-on-surface-variant">
                                        <span class="font-semibold text-primary">{{ job.department }}</span>
                                        <span>•</span>
                                        <span>{{ job.employment_type }}</span>
                                        <span>•</span>
                                        <span>{{ job.branch_name ?? 'Toàn hệ thống' }}</span>
                                    </div>
                                </div>
                                <UiBadge color="success" pill :dot="false" class="self-start sm:self-auto">{{ job.salary_range || 'Mức lương thỏa thuận' }}</UiBadge>
                            </div>

                            <p class="line-clamp-3 whitespace-pre-line text-xs leading-relaxed text-on-surface-variant">{{ job.description }}</p>

                            <div v-if="job.requirements" class="rounded-xl bg-surface-container-low p-3 text-xs text-on-surface-variant">
                                <strong class="mb-1 block text-on-surface">Yêu cầu ứng viên:</strong>
                                <p class="whitespace-pre-line">{{ job.requirements }}</p>
                            </div>

                            <div class="flex items-center justify-between pt-2 text-xs text-on-surface-subtle">
                                <span>Hạn nộp: {{ job.deadline ?? 'Tuyển liên tục' }}</span>
                                <a href="#apply-form" class="font-bold text-primary hover:underline" @click="selectPosition(job)">Ứng tuyển vị trí này &rarr;</a>
                            </div>
                        </div>
                        <UiEmptyState
                            v-if="!jobs.length"
                            class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest"
                            icon="work_off"
                            title="Hiện tại chưa có tin tuyển dụng nào mở. Bạn vẫn có thể nộp hồ sơ tiềm năng bên cạnh!"
                        />
                    </div>
                </div>

                <!-- Right: Form Nộp Hồ sơ Trực Tuyến (5 cols) -->
                <div id="apply-form" class="sticky top-28 space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-md lg:col-span-5">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-bold text-on-surface">
                            <span class="material-symbols-outlined text-primary">send</span>
                            Nộp Hồ sơ Ứng tuyển Online
                        </h2>
                        <p class="mt-0.5 text-xs text-on-surface-variant">Điền thông tin và đính kèm CV, chúng tôi sẽ phản hồi trong vòng 24 - 48h</p>
                    </div>

                    <UiForm :action="route('portal.recruitment.submit')" method="post" class="space-y-4 text-xs" reset-on-success @success="onSuccess">
                        <input id="job_posting_id" type="hidden" name="job_posting_id" :value="apply.job_posting_id" />

                        <UiInput name="full_name" label="Họ và tên" required placeholder="Nguyễn Văn A" />

                        <div class="grid grid-cols-2 gap-3">
                            <UiInput type="email" name="email" label="Email" required placeholder="name@example.com" />
                            <UiInput name="phone" label="Số điện thoại" required placeholder="0987654321" />
                        </div>

                        <UiInput id="applying_position" v-model="apply.applying_position" name="applying_position" label="Vị trí ứng tuyển" required placeholder="Ví dụ: Giáo viên Tiếng Anh / Trợ giảng" />

                        <UiSelect id="branch_id" v-model="apply.branch_id" name="branch_id" label="Cơ sở mong muốn làm việc" placeholder="Toàn hệ thống / Linh hoạt" :options="branches" />

                        <UiField label="Đính kèm CV (PDF, DOC, DOCX tối đa 10MB)" name="cv_file" for="cv_file" required>
                            <input
                                id="cv_file"
                                type="file"
                                name="cv_file"
                                required
                                accept=".pdf,.doc,.docx"
                                class="w-full cursor-pointer rounded-lg border border-outline-variant bg-surface-container-low p-2 text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-primary-container/10 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-primary hover:file:bg-primary-container/20"
                            />
                        </UiField>

                        <UiInput type="url" name="portfolio_url" label="Link Video dạy thử / Portfolio / LinkedIn" placeholder="https://youtube.com/... hoặc https://linkedin.com/in/..." />

                        <UiTextarea name="cover_letter" label="Thư ngỏ / Giới thiệu bản thân" rows="3" placeholder="Chia sẻ thêm về kinh nghiệm giảng dạy hoặc lý do bạn chọn MENGLISH..." />

                        <UiButton type="submit" icon="send" class="w-full">Gửi Hồ sơ Ứng tuyển</UiButton>
                    </UiForm>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-inverse-surface bg-inverse-surface py-8 text-xs text-inverse-on-surface/70">
            <div class="mx-auto max-w-6xl space-y-2 px-4 text-center sm:px-6">
                <p class="font-bold text-inverse-on-surface">HỆ THỐNG ANH NGỮ MENGLISH — PHÒNG NHÂN SỰ &amp; ĐÀO TẠO</p>
                <p>Hotline Tuyển dụng: 0988.xxx.xxx • Email: tuyendung@menglish.edu.vn</p>
                <p class="text-inverse-on-surface/60">© 2026 MENGLISH. All rights reserved.</p>
            </div>
        </footer>
    </div>
</template>
