<script setup>
/**
 * Import 1 chặng từ file Sheet 1 — bước Xem trước (docs/kho-syllabus.md §3.3): cảnh báo, lỗi theo dòng, buổi sẽ thêm /
 * sửa / xóa. Chưa ghi gì vào hệ thống cho tới khi bấm Xác nhận.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Import chặng từ file' } });

const props = defineProps({
    curriculum: { type: Object, required: true },
    stage: { type: Object, default: null },
    token: { type: String, required: true },
    plan: { type: Object, required: true },
});

// Khóa nút khi đang gửi: bấm đúp không tạo 2 chặng.
const confirming = ref(false);
function confirmImport() {
    if (confirming.value) return;
    confirming.value = true;
    router.post(route('syllabus.stage-import.confirm', props.curriculum.id), { token: props.token }, { onFinish: () => (confirming.value = false) });
}
</script>

<template>
    <UiPageHeader title="Import chặng từ file" icon="upload_file" :back="route('syllabus.curriculums.show', { id: curriculum.id, tab: 'stages' })">
        <template #meta>{{ curriculum.title }} ({{ curriculum.code }}) · {{ stage ? `Cập nhật ${stage.label}` : `Thêm chặng mới: ${plan.stage_name}` }}</template>
    </UiPageHeader>

    <div class="space-y-md">
        <div class="flex flex-wrap gap-2">
            <UiBadge color="success">{{ plan.summary.create }} buổi thêm</UiBadge>
            <UiBadge color="info">{{ plan.summary.update }} buổi sửa</UiBadge>
            <UiBadge color="error">{{ plan.summary.delete }} buổi xóa</UiBadge>
            <UiBadge>{{ plan.summary.units }} unit mới</UiBadge>
        </div>

        <UiAlert v-for="w in plan.warnings" :key="w" type="warning">{{ w }}</UiAlert>

        <UiDataTable v-if="plan.errors.length" min-width="560px">
            <template #header>
                <h2 class="font-h3 text-h3 text-error">Lỗi cần sửa trong file ({{ plan.errors.length }})</h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Dòng</th>
                        <th>Lỗi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(e, i) in plan.errors" :key="i">
                        <td class="whitespace-nowrap">{{ e.line || '—' }}</td>
                        <td>{{ e.message }}</td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <UiDataTable min-width="640px">
            <template #header>
                <h2 class="font-h3 text-h3 text-on-surface">Buổi học trong file</h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Dòng</th>
                        <th>Unit</th>
                        <th>Buổi</th>
                        <th>Loại</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in plan.lessons" :key="l.line">
                        <td>{{ l.line }}</td>
                        <td>{{ l.unit_number || '—' }}</td>
                        <td>{{ l.title }}</td>
                        <td>{{ l.type }}</td>
                        <td><UiBadge :color="l.action === 'Thêm' ? 'success' : 'info'">{{ l.action }}</UiBadge></td>
                    </tr>
                    <tr v-if="!plan.lessons.length">
                        <td colspan="5"><UiEmptyState icon="description" title="File chưa có buổi nào" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <UiAlert v-if="plan.deletions.length" type="warning" title="Buổi sẽ bị xóa (file ít buổi hơn chặng hiện tại)">
            <ul class="list-disc pl-5">
                <li v-for="d in plan.deletions" :key="d">{{ d }}</li>
            </ul>
        </UiAlert>

        <div class="flex flex-wrap justify-end gap-2">
            <UiButton variant="secondary" icon="upload_file" :href="route('syllabus.curriculums.show', { id: curriculum.id, tab: 'stages' })">Tải file khác</UiButton>
            <UiButton icon="check_circle" :disabled="!plan.ok || confirming" @click="confirmImport">Xác nhận import</UiButton>
        </div>
    </div>
</template>
