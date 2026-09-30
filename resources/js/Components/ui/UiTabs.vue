<script setup>
/**
 * Thanh tab điều hướng (như <x-ui.tabs>) — tab là link (<UiTab>). Màn hẹp: cuộn ngang, mép phải mờ khi còn tab khuất.
 *   <UiTabs>
 *       <UiTab :href="route('crm.pipeline')" :active="routeIs('crm.pipeline')">Theo giai đoạn</UiTab>
 *       <UiTab :href="route('crm.lost-deals')" :active="routeIs('crm.lost-deals')" :count="lostCount">Khách không chốt</UiTab>
 *   </UiTabs>
 */
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const nav = ref(null);
const more = ref(false);
const check = () => {
    const el = nav.value;
    if (el) more.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
};
onMounted(async () => {
    nav.value?.querySelector('[aria-current=page]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
    await nextTick();
    check();
    window.addEventListener('resize', check, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('resize', check));
</script>

<template>
    <nav ref="nav" :class="['no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest', more ? '[mask-image:linear-gradient(to_right,#000_80%,transparent)]' : '']" @scroll.passive="check">
        <slot />
    </nav>
</template>
