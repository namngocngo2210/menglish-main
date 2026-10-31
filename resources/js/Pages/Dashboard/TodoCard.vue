<script setup>
import { computed, ref, useId, watch } from 'vue';
import { defaultTodoGroup, groupCareRows, groupTodoItems } from '@/lib/todoGroups';
import TodoRow from './TodoRow.vue';

const props = defineProps({ todo: { type: Object, required: true } });
const id = useId();
const groups = computed(() => groupTodoItems(props.todo.items));
const selected = ref(null);
const active = computed(() => selected.value ?? defaultTodoGroup(groups.value));
watch(groups, () => { selected.value = null; });

function navigateTabs(event, index) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? groups.value.length - 1
        : (index + (event.key === 'ArrowRight' ? 1 : -1) + groups.value.length) % groups.value.length;
    selected.value = groups.value[next].id;
    event.currentTarget.parentElement.children[next].focus();
}
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-todo>
        <div class="flex items-center gap-sm border-b border-surface-variant px-md py-sm">
            <h3 :id="`${id}-title`" class="font-h3 text-h3 text-on-surface">Cần bạn xử lý</h3>
            <span v-if="todo.total" class="rounded-full bg-error-container px-sm py-0.5 font-caption text-caption font-semibold text-error">{{ formatNumber(todo.total) }}</span>
        </div>
        <div v-if="groups.length > 1" role="tablist" aria-label="Nhóm việc cần xử lý" class="flex min-w-0 max-w-full gap-xs overflow-x-auto border-b border-surface-variant px-sm py-xs">
            <button
                v-for="(group, index) in groups" :id="`${id}-tab-${group.id}`" :key="group.id"
                type="button" role="tab" :aria-selected="active === group.id" :aria-controls="`${id}-panel-${group.id}`"
                :tabindex="active === group.id ? 0 : -1"
                :class="['inline-flex min-h-11 shrink-0 items-center justify-center gap-xs whitespace-nowrap rounded-lg px-sm font-body-small text-body-small font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container', active === group.id ? 'bg-primary-fixed text-primary' : 'text-on-surface-variant hover:bg-surface-container-low']"
                @click="selected = group.id" @keydown="navigateTabs($event, index)"
            >
                {{ group.label }} <span class="tabular-nums">{{ formatNumber(group.count) }}</span>
            </button>
        </div>
        <!-- Keep inactive panels in the SSR HTML for text assertions and accessibility. -->
        <div
            v-for="group in groups" :id="`${id}-panel-${group.id}`" :key="group.id"
            :role="groups.length > 1 ? 'tabpanel' : 'region'" :hidden="active !== group.id"
            :aria-labelledby="groups.length > 1 ? `${id}-tab-${group.id}` : `${id}-title`" tabindex="0"
            class="min-w-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container"
        >
            <template v-if="group.id === 'care'">
                <template v-for="(row, index) in groupCareRows(group.items)" :key="index">
                    <details v-if="row.care" :open="row.items.length <= 3" class="group/care border-b border-surface-variant/60 last:border-0">
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-sm px-md py-sm font-body-small text-body-small text-on-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-container [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0">
                                <span class="block font-semibold">Chăm sóc tháng đầu · {{ formatNumber(row.items.length) }}</span>
                                <span v-if="row.when" class="mt-xs flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>Hạn gần nhất: {{ row.when }}
                                </span>
                            </span>
                            <span class="material-symbols-outlined shrink-0 group-open/care:rotate-180" aria-hidden="true">expand_more</span>
                        </summary>
                        <TodoRow v-for="(item, itemIndex) in row.items" :key="itemIndex" :item="item" />
                    </details>
                    <TodoRow v-else :item="row.items[0]" />
                </template>
            </template>
            <template v-else>
                <TodoRow v-for="(item, index) in group.items" :key="index" :item="item" />
            </template>
        </div>
        <UiEmptyState v-if="!todo.items.length" icon="task_alt" title="Không có việc nào đang chờ bạn" />
    </section>
</template>
