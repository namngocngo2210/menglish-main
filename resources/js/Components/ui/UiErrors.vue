<script setup>
/**
 * Danh sách lỗi validate cùng kiểu lỗi của UiField — khi một control gom lỗi của nhiều khoá.
 *   <UiErrors :messages="[errors.date_from, errors.date_to]" />
 */
import { computed } from 'vue';

const props = defineProps({ messages: { type: [Array, String], default: () => [] } });
const list = computed(() => [props.messages].flat(2).filter(Boolean));
</script>

<template>
    <ul v-if="list.length" class="space-y-xs" role="alert">
        <li v-for="(message, i) in list" :key="i" class="flex items-center gap-xs font-caption text-caption text-error">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ message }}
        </li>
    </ul>
</template>
