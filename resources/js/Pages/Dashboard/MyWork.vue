<script setup>
/**
 * "Việc của bạn" — Tổng quan cho vai trò không phải Admin / Quản lý (MyWorkBoard): số liệu tuần / tháng, lịch hẹn
 * 7 ngày tới, đầu việc cần xử lý, việc của tôi trong tuần.
 */
import { Link } from '@inertiajs/vue3';
import AgendaCard from './AgendaCard.vue';
import MyTasksCard from './MyTasksCard.vue';

defineProps({ dashboard: { type: Object, required: true } });
</script>

<template>
    <section class="space-y-md" data-role-dashboard="personal">
        <div>
            <h2 class="font-h2 text-h2 text-on-surface">{{ dashboard.title }}</h2>
            <p class="font-body-small text-body-small text-on-surface-variant">Phạm vi: {{ dashboard.scope }} · cập nhật {{ dashboard.updatedAt }}</p>
        </div>

        <div v-if="dashboard.stats.length" :class="['grid grid-cols-1 gap-md sm:grid-cols-2', dashboard.stats.length >= 5 ? 'lg:grid-cols-3 xl:grid-cols-5' : dashboard.stats.length === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3']">
            <component :is="stat.href ? Link : 'div'" v-for="stat in dashboard.stats" :key="stat.label" :href="stat.href ?? undefined" :class="stat.href ? 'rounded-xl transition hover:shadow-md' : ''">
                <UiStatCard :label="stat.label" :value="stat.value" :icon="stat.icon" :tone="stat.tone" :hint="stat.hint" class="h-full" />
            </component>
        </div>

        <!-- Không có nguồn lịch hẹn (vd Kế toán): Cần xử lý + Việc của tôi chia đôi chiều ngang -->
        <div :class="['grid grid-cols-1 gap-md', dashboard.agenda ? 'lg:grid-cols-3' : 'lg:grid-cols-2']">
            <AgendaCard v-if="dashboard.agenda" :agenda="dashboard.agenda" class="lg:col-span-2" />
            <div :class="dashboard.agenda ? 'space-y-md' : 'contents'">
                <section v-if="dashboard.queues.length" class="rounded-xl border border-surface-variant bg-surface-container-lowest" data-role-queues>
                    <div class="border-b border-surface-variant px-md py-sm">
                        <h3 class="font-h3 text-h3 text-on-surface">Cần xử lý</h3>
                    </div>
                    <Link v-for="queue in dashboard.queues" :key="queue.label" :href="queue.href" class="flex items-center gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0 hover:bg-surface-container-low">
                        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-full', queue.value > 0 ? 'bg-warning-container text-warning' : 'bg-surface-container-low text-on-surface-variant']">
                            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ queue.icon }}</span>
                        </span>
                        <span class="min-w-0 flex-1 truncate font-body-medium text-body-medium text-on-surface">{{ queue.label }}</span>
                        <span :class="['shrink-0 font-h3 text-h3 tabular-nums', queue.value > 0 ? 'text-warning' : 'text-on-surface-variant']">{{ formatNumber(queue.value) }}</span>
                    </Link>
                </section>
                <MyTasksCard :tasks="dashboard.myTasks" />
            </div>
        </div>
    </section>
</template>
