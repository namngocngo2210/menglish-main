<script setup>
/**
 * Thanh điều hướng dưới của Cổng Giáo viên trên điện thoại (mockup 03_Cong_Giao_Vien/16: Lịch dạy · Bảng công · Thông báo · Cá nhân).
 * Trang dùng thanh này cần chừa khoảng trống cuối trang (pb-24 md:pb-0).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import { route, routeIs } from '@/lib/route';

const items = computed(() => {
    const list = [
        { route: 'teacher.home', icon: 'calendar_month', label: 'Lịch dạy', active: routeIs('teacher.home', 'teacher.attendance', 'teacher.remarks', 'teacher.homework', 'teacher.scores') },
        { route: 'teacher.general-report', icon: 'history_edu', label: 'Bảng công', active: routeIs('teacher.general-report') },
    ];
    if (can('notification.view')) {
        list.push({ route: 'notifications.index', icon: 'notifications', label: 'Thông báo', active: routeIs('notifications.*') });
    }
    list.push({ route: 'profile.edit', icon: 'account_circle', label: 'Cá nhân', active: routeIs('profile.*') });

    return list.map((item) => ({ ...item, href: route(item.route) }));
});
</script>

<template>
    <nav class="fixed inset-x-0 bottom-0 z-40 rounded-t-xl border-t border-outline-variant bg-surface-container-lowest shadow-level-3 md:hidden" aria-label="Điều hướng cổng giáo viên" data-testid="teacher-bottom-nav">
        <ul class="mx-auto grid max-w-md gap-xs px-sm py-xs" :style="{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }">
            <li v-for="item in items" :key="item.route">
                <Link
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    :class="['flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs', item.active ? 'bg-primary-container text-white' : 'text-on-surface-variant active:bg-surface-variant']"
                >
                    <span class="material-symbols-outlined text-[22px]" aria-hidden="true">{{ item.icon }}</span>
                    <span class="text-xs font-semibold">{{ item.label }}</span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
