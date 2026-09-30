<script setup>
/**
 * Trang hoàn thành bài test (công khai). Thí sinh không xem điểm sau khi nộp:
 * Học vụ duyệt kết quả rồi trung tâm gửi cho phụ huynh.
 */
import { Head } from '@inertiajs/vue3';
import BareLayout from '@/Layouts/BareLayout.vue';

defineOptions({ layout: BareLayout });

defineProps({
    testTitle: { type: String, required: true },
    candidateName: { type: String, default: null },
});
</script>

<template>
    <Head title="Hoàn thành bài test" />
    <div class="flex min-h-screen items-center justify-center bg-surface-container-low p-4 font-sans text-on-surface antialiased">
        <!-- Khung max-w-md bọc ngoài: app.css ép mọi <main> max-width: 100% -->
        <div class="w-full max-w-md">
            <main class="w-full space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-8 text-center shadow-xl" data-testid="placement-test-done">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-primary-container/15 text-primary-container">
                    <span class="material-symbols-outlined text-5xl">celebration</span>
                </div>
                <h1 class="text-2xl font-extrabold tracking-tight text-on-surface">Chúc mừng con đã hoàn thiện bài test!</h1>
                <p v-if="candidateName" class="text-sm font-semibold text-on-surface">{{ candidateName }}</p>
                <p class="text-sm leading-relaxed text-on-surface-variant">Bài làm đã được gửi tới MEnglish. Thầy cô sẽ chấm và liên hệ gửi kết quả cùng lộ trình học phù hợp cho phụ huynh trong thời gian sớm nhất.</p>
                <p class="text-xs text-on-surface-variant">{{ testTitle }}</p>
            </main>
        </div>
    </div>
</template>
