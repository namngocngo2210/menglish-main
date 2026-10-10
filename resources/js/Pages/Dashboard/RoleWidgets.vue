<script setup>
/** Dashboard theo vai trò (BPMN 22) — số liệu thật từ DashboardController: thẻ số liệu, hàng chờ, việc quá hạn / đề xuất + Big Test. */
import { Link } from '@inertiajs/vue3';
import { shortenCodesIn } from '@/lib/format';
import AgendaCard from './AgendaCard.vue';
import MyTasksCard from './MyTasksCard.vue';

defineProps({ dashboard: { type: Object, required: true } });
</script>

<template>
    <section class="space-y-md" :data-role-dashboard="dashboard.type">
        <div class="flex flex-wrap items-end justify-between gap-sm">
            <div>
                <h2 class="font-h2 text-h2 text-on-surface">{{ dashboard.title }}</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Phạm vi: {{ dashboard.scope }} · cập nhật {{ dashboard.updatedAt }}</p>
            </div>
        </div>

        <div :class="['grid grid-cols-1 gap-md sm:grid-cols-2', dashboard.stats.length >= 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-3']">
            <UiStatCard v-for="stat in dashboard.stats" :key="stat.label" :label="stat.label" :value="stat.value" :icon="stat.icon" :tone="stat.tone" :hint="stat.hint" />
        </div>

        <div v-if="dashboard.queues?.length" class="rounded-xl border border-surface-variant bg-surface-container-lowest">
            <div class="border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Hàng chờ cần xử lý</h3>
            </div>
            <div :class="['grid grid-cols-1 divide-y divide-surface-variant/60 sm:grid-cols-2 sm:divide-y-0', dashboard.queues.length >= 5 ? 'xl:grid-cols-5' : dashboard.queues.length >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3']" data-role-queues>
                <Link v-for="queue in dashboard.queues" :key="queue.label" :href="queue.href ?? '#'" class="flex items-center gap-sm px-md py-sm hover:bg-surface-container-low">
                    <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', queue.value > 0 ? 'bg-warning-container text-warning' : 'bg-surface-container-low text-on-surface-variant']">
                        <span class="material-symbols-outlined" aria-hidden="true">{{ queue.icon }}</span>
                    </span>
                    <span class="min-w-0">
                        <span :class="['block font-h3 text-h3', queue.value > 0 ? 'text-warning' : 'text-on-surface']">{{ formatNumber(queue.value, 0, '.', ',') }}</span>
                        <span class="block truncate font-body-small text-body-small text-on-surface-variant">{{ queue.label }}</span>
                        <span v-if="queue.hint" class="block truncate font-caption text-caption text-on-surface-variant">{{ queue.hint }}</span>
                    </span>
                </Link>
            </div>
        </div>

        <div v-if="['admin', 'manager'].includes(dashboard.type)" class="rounded-xl border border-surface-variant bg-surface-container-lowest">
            <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Việc quá hạn cần xử lý</h3>
                <Link :href="route('tasks.index', { tab: 'assigned', status: 'overdue' })" class="font-body-small text-body-small text-primary hover:underline">Xem danh sách</Link>
            </div>
            <div v-for="task in dashboard.overdueTasks" :key="task.id" class="flex items-center justify-between gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0">
                <div class="min-w-0">
                    <p class="truncate font-body-medium text-body-medium text-on-surface" :title="task.title">{{ shortenCodesIn(task.title) }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">{{ task.assignee ?? 'Chưa phân công' }}</p>
                </div>
                <span class="shrink-0 rounded-full bg-error-container px-sm py-xs font-caption text-caption text-error">Hạn {{ formatDate(task.due_date) || '—' }}</span>
            </div>
            <UiEmptyState v-if="!dashboard.overdueTasks.length" icon="task_alt" title="Không có việc quá hạn" />
        </div>

        <div v-if="dashboard.type === 'academic'" class="grid grid-cols-1 gap-md lg:grid-cols-2">
            <div class="rounded-xl border border-surface-variant bg-surface-container-lowest">
                <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Đề xuất chờ duyệt</h3>
                    <Link :href="route('syllabus.adjustment-requests')" class="font-body-small text-body-small text-primary hover:underline">Duyệt đề xuất</Link>
                </div>
                <div v-for="req in dashboard.pendingAdjustments" :key="req.id" class="border-b border-surface-variant/60 px-md py-sm last:border-0">
                    <p class="font-body-medium text-body-medium text-on-surface">{{ req.class ?? 'Lớp' }} — {{ req.teacher ?? 'Giáo viên' }}</p>
                    <p class="truncate font-caption text-caption text-on-surface-variant">{{ req.reason }}</p>
                </div>
                <UiEmptyState v-if="!dashboard.pendingAdjustments.length" icon="inbox" title="Không có đề xuất chờ duyệt" />
            </div>
            <div class="rounded-xl border border-surface-variant bg-surface-container-lowest">
                <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Big Test sắp tới</h3>
                    <Link :href="route('syllabus.big-tests.schedules')" class="font-body-small text-body-small text-primary hover:underline">Lịch Big Test</Link>
                </div>
                <div v-for="test in dashboard.upcomingBigTests" :key="test.id" class="flex items-center justify-between gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0">
                    <div class="min-w-0">
                        <p class="truncate font-body-medium text-body-medium text-on-surface">{{ test.title }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ test.class ?? '—' }}</p>
                    </div>
                    <span class="shrink-0 font-caption text-caption text-on-surface-variant">{{ formatDate(test.scheduled_at, 'H:i d/m') }}</span>
                </div>
                <UiEmptyState v-if="!dashboard.upcomingBigTests.length" icon="event_available" title="Không có Big Test trong 14 ngày tới" />
            </div>
        </div>

        <!-- Học thuật: thêm lịch hẹn 7 ngày tới (Big Test) + việc của tôi trong tuần -->
        <div v-if="dashboard.agenda" class="grid grid-cols-1 gap-md lg:grid-cols-3">
            <AgendaCard :agenda="dashboard.agenda" class="lg:col-span-2" />
            <MyTasksCard v-if="dashboard.myTasks" :tasks="dashboard.myTasks" />
        </div>
    </section>
</template>
