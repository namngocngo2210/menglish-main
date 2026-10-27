<script setup>
/**
 * Vài lần chạy gần nhất của một việc nặng chạy nền (App\Models\BackgroundTask::recentFor): trạng thái, thông báo kết quả,
 * lỗi / cảnh báo của lần mới nhất. Còn việc đang chờ / đang chạy thì tải lại prop `reloadOnly` mỗi 3 giây tới khi xong.
 */
import { computed, onBeforeUnmount, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    // Các prop Inertia tải lại khi đang chờ (mặc định chỉ danh sách việc); thêm dữ liệu trang cần cập nhật khi xong.
    reloadOnly: { type: Array, default: () => [] },
    propName: { type: String, default: 'backgroundTasks' },
});

const COLORS = { queued: 'neutral', running: 'info', done: 'success', failed: 'error' };
const report = computed(() => props.tasks[0]?.report ?? null);
const pending = computed(() => props.tasks.some((t) => t.pending));

let timer = null;
watch(
    pending,
    (on, was) => {
        if (on && !timer && typeof window !== 'undefined') {
            timer = window.setInterval(() => router.reload({ only: [props.propName] }), 3000);
        } else if (!on && timer) {
            window.clearInterval(timer);
            timer = null;
            // Vừa xong: tải lại dữ liệu trang (danh sách vừa import, bảng lương vừa tính...).
            if (was) router.reload(props.reloadOnly.length ? { only: [props.propName, ...props.reloadOnly] } : {});
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => timer && window.clearInterval(timer));
</script>

<template>
    <section v-if="tasks.length" class="mb-lg space-y-sm" data-background-tasks>
        <div
            v-for="task in tasks"
            :key="task.id"
            class="flex flex-wrap items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm"
            :data-background-task="task.status"
        >
            <span v-if="task.pending" class="material-symbols-outlined animate-spin text-[18px] text-primary" aria-hidden="true">progress_activity</span>
            <UiBadge :color="COLORS[task.status] ?? 'neutral'">{{ task.status_label }}</UiBadge>
            <span class="min-w-0 flex-1 truncate font-body-small text-body-small text-on-surface" :title="task.title">{{ task.title }}</span>
            <span class="font-caption text-caption text-on-surface-variant">{{ task.user }} · {{ task.created_at }}</span>
            <p v-if="task.message" class="w-full font-body-small text-body-small text-on-surface-variant">{{ task.message }}</p>
            <p v-if="task.stalled" class="w-full font-body-small text-body-small text-error" role="alert">
                Chờ quá 2 phút chưa chạy: tiến trình xử lý nền (queue) có thể chưa bật. Báo quản trị kiểm tra.
            </p>
        </div>
        <UiAlert v-if="report?.warnings?.length" type="warning" title="Cần kiểm tra">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in report.warnings" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="report?.errors?.length" type="error" title="Chưa xử lý được">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in report.errors" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
    </section>
</template>
