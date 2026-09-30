<script setup>
/**
 * Thanh điều hướng đáy của cổng học viên trên điện thoại (thay portal/partials/bottom-nav):
 * Trang chủ · Học tập · Phát âm · Khảo sát · Thông báo (kèm số chưa đọc).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';

const props = defineProps({
    activeTab: { type: String, default: 'home' },
    student: { type: Object, default: null },
    unreadCount: { type: Number, default: 0 },
});

const items = computed(() => {
    const params = { studentId: props.student?.id ?? null };
    return [
        { key: 'home', href: route('portal.student.home', params), icon: 'home', label: 'Trang chủ', active: props.activeTab === 'home' },
        { key: 'learning', href: route('portal.student.homework', params), icon: 'menu_book', label: 'Học tập', active: ['learning', 'homework'].includes(props.activeTab) },
        // Phát âm: trước chỉ vào được qua dải tab trên đầu, bị ẩn trên điện thoại.
        { key: 'pronunciation', href: route('portal.student.pronunciation', params), icon: 'mic', label: 'Phát âm', active: props.activeTab === 'pronunciation' },
        { key: 'survey', href: route('portal.student.survey', params), icon: 'assignment', label: 'Khảo sát', active: ['survey', 'feedback'].includes(props.activeTab) },
        { key: 'notifications', href: route('portal.student.notifications', params), icon: 'notifications', label: 'Thông báo', active: props.activeTab === 'notifications' },
    ];
});
</script>

<template>
    <nav aria-label="Điều hướng cổng học viên" class="fixed bottom-0 left-0 right-0 z-50 mx-auto flex w-full max-w-[430px] items-center justify-around rounded-t-2xl border-t border-surface-container-highest bg-surface-container-lowest py-2 shadow-lg dark:border-inverse-surface dark:bg-inverse-surface md:hidden">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            :class="['flex flex-col items-center justify-center px-2 py-1 transition-transform duration-150 active:scale-90', item.active ? 'font-bold text-primary' : 'text-on-surface-variant hover:text-on-surface']"
        >
            <div v-if="item.key === 'notifications'" class="relative">
                <span class="material-symbols-outlined mb-0.5 text-[24px]" :style="{ fontVariationSettings: `'FILL' ${item.active ? 1 : 0}` }">{{ item.icon }}</span>
                <span v-if="unreadCount > 0" class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-error px-1 text-xs font-bold text-white shadow-xs" :aria-label="`${unreadCount} thông báo chưa đọc`">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </div>
            <span v-else class="material-symbols-outlined mb-0.5 text-[24px]" :style="{ fontVariationSettings: `'FILL' ${item.active ? 1 : 0}` }">{{ item.icon }}</span>
            <span class="text-xs tracking-wide">{{ item.label }}</span>
        </Link>
    </nav>
</template>
