<script setup>
/**
 * Thanh trên của cổng học viên (thay portal/partials/top-header): tiêu đề, học viên đang chọn, đổi học viên (phụ huynh
 * nhiều con / nhân sự xem hộ) và lối về App Shell. Đổi học viên giữ nguyên màn đang xem, chỉ đổi học viên trên URL.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useBackLink } from '@/lib/backLink';
import { route } from '@/lib/route';

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    title: { type: String, default: 'MENGLISH' },
    showBack: { type: Boolean, default: false },
    backUrl: { type: String, default: null },
});
const back = useBackLink(() => props.backUrl ?? route('portal.student.home', { studentId: props.student?.id }));

const options = computed(() => props.students.map((s) => ({ value: s.id, label: s.name })));

function switchStudent(event) {
    const id = event.target.value;
    const path = window.location.pathname
        .replace(/\/home(\/\d+)?$/, '/home/' + id)
        .replace(/\/student-homework(\/\d+)?$/, '/student-homework/' + id)
        .replace(/\/pronunciation(\/\d+)?$/, '/pronunciation/' + id)
        .replace(/\/notifications(\/\d+)?$/, '/notifications/' + id)
        .replace(/\/survey(\/\d+)?$/, '/survey/' + id)
        .replace(/\/feedback(\/\d+)?$/, '/feedback/' + id);
    // Đổi học viên = cùng màn, không thêm bước lịch sử (nút Quay lại không về màn của học viên trước).
    router.visit(path, { replace: true });
}
</script>

<template>
    <header class="sticky top-0 z-40 flex h-16 w-full items-center justify-between border-b border-surface-container-highest bg-surface-container-lowest/95 px-4 backdrop-blur-md dark:border-inverse-surface dark:bg-inverse-surface/95">
        <div class="flex items-center gap-2">
            <Link v-if="showBack" :href="back.href" data-back-link :aria-label="back.label" :title="back.label" class="rounded-full p-2 text-on-surface-variant transition hover:bg-surface-container dark:text-inverse-on-surface dark:hover:bg-inverse-surface">
                <span class="material-symbols-outlined text-[20px]">arrow_back</span>
            </Link>
            <div>
                <div class="text-xl font-black tracking-tight text-primary">{{ title }}</div>
                <p v-if="student" class="line-clamp-1 text-xs font-medium text-on-surface-variant">{{ student.name }} • {{ student.class_name ?? 'Chưa xếp lớp' }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <!-- Đổi học viên nhanh -->
            <UiSelect v-if="students.length > 1" class="!min-w-0 py-1 text-xs font-semibold" aria-label="Chọn học viên" :options="options" :value="student?.id" @change="switchStudent" />

            <Link :href="route('portal.app-shell', { student_id: student?.id })" title="Xem App Shell & Menu" class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-primary-container/10 text-primary transition-opacity hover:opacity-80 active:scale-95">
                <span class="material-symbols-outlined text-[22px]">account_circle</span>
            </Link>
        </div>
    </header>
</template>
