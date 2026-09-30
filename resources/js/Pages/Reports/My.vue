<script setup>
/** Báo cáo định kỳ của tôi: kỳ báo cáo theo chức danh (ngày / tuần / tháng — StaffReportController), lịch sử + modal nộp mới. */
import { computed, ref } from 'vue';

const props = defineProps({
    reports: { type: Object, required: true },
    label: { type: String, default: 'Báo cáo' },
    today: { type: String, default: null },
});

defineOptions({ layout: (props) => ({ title: `${props.label ?? 'Báo cáo'} của tôi` }) });

const creating = ref(false);
const lower = computed(() => props.label.toLocaleLowerCase('vi'));
</script>

<template>
    <UiPageHeader :title="`${label} của tôi`" icon="assignment">
        <template #meta>Nộp và theo dõi {{ lower }} theo vai trò của bạn</template>
        <template #actions>
            <UiButton icon="send" @click="creating = true">Nộp {{ lower }}</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div class="space-y-3">
            <h2 class="text-sm font-bold uppercase tracking-wider text-on-surface">Lịch sử đã nộp</h2>
            <div v-for="r in reports.data" :key="r.id" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-on-surface">{{ r.title }}</span>
                    <span class="text-xs text-on-surface-subtle">{{ formatDate(r.report_date) }}</span>
                </div>
                <p class="mt-2 whitespace-pre-line text-sm text-on-surface-variant">{{ r.content }}</p>
            </div>
            <div v-if="!reports.data.length" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <UiEmptyState icon="description" :title="`Bạn chưa nộp ${lower} nào.`" />
            </div>
            <UiPagination :paginator="reports" :options="[]" />
        </div>
    </div>

    <UiModal :show="creating" :title="`Nộp ${lower} mới`" max-width="xl" @close="creating = false">
        <UiForm id="new-report-form" :action="route('reports.my.store')" method="post" class="space-y-md" reset-on-success @success="creating = false">
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <UiInput name="title" label="Tiêu đề" required />
                </div>
                <UiDate name="report_date" label="Ngày báo cáo" :value="today" />
            </div>
            <UiTextarea name="content" label="Nội dung" :rows="6" required placeholder="Kết quả thực hiện, tồn đọng, kế hoạch..." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
            <UiButton type="submit" form="new-report-form" icon="send">Nộp báo cáo</UiButton>
        </template>
    </UiModal>
</template>
