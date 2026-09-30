<script setup>
/**
 * Thanh tab của workspace + nút hành động (như <x-ui.workspace-tabs>). Nguồn: shell.workspace (SidebarMenu::definition()).
 * AppLayout tự đặt ở đầu trang; trang muốn đặt chỗ khác (vd. header CRM) thì tắt ở layout
 * (defineOptions({ layout: { workspaceTabs: false } })) rồi tự đặt <WorkspaceTabs class="!mb-0 !border-b-0" />.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const ws = computed(() => page.props.shell?.workspace ?? null);
const visible = computed(() => ws.value && (ws.value.tabs.length > 1 || ws.value.buttons.length || ws.value.menus.length));
</script>

<template>
    <div v-if="visible" class="mb-lg border-b border-surface-container-highest" :data-workspace-tabs="ws.id">
        <div class="flex flex-col gap-sm md:flex-row md:items-end md:justify-between">
            <UiTabs v-if="ws.tabs.length > 1" class="-mb-px min-w-0 border-b-0" :aria-label="ws.label">
                <UiTab v-for="tab in ws.tabs" :key="tab.route" :href="tab.url" :active="tab.active">{{ tab.label }}</UiTab>
            </UiTabs>
            <span v-else></span>

            <div v-if="ws.buttons.length || ws.menus.length" class="flex shrink-0 flex-wrap items-center gap-sm pb-sm">
                <UiDropdown v-for="menu in ws.menus" :key="menu.label" align="right" width="64">
                    <template #trigger>
                        <UiButton size="sm" variant="secondary" aria-haspopup="menu">
                            {{ menu.label }}
                            <span class="material-symbols-outlined -mr-1 text-[18px]" aria-hidden="true">expand_more</span>
                        </UiButton>
                    </template>
                    <template #content>
                        <div class="py-xs" role="menu" :data-workspace-menu="menu.label">
                            <Link v-for="entry in menu.items" :key="entry.url" :href="entry.url" role="menuitem" :class="['flex items-center gap-sm px-md py-sm font-body-medium text-body-medium transition-colors hover:bg-surface-container-low hover:text-primary', entry.active ? 'text-primary' : 'text-on-surface']">
                                <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">{{ entry.icon }}</span>
                                {{ entry.label }}
                            </Link>
                        </div>
                    </template>
                </UiDropdown>
                <UiButton v-for="action in ws.buttons" :key="action.url" size="sm" :variant="action.variant" :icon="action.icon" :href="action.url" :modal="action.modal">{{ action.label }}</UiButton>
            </div>
        </div>
    </div>
</template>
