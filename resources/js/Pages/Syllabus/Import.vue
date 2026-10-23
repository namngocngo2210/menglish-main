<script setup>
/**
 * Import syllabus từ file mẫu (.zip / .xlsx) — 3 bước: Tải file → Kiểm tra (xem trước, chưa ghi DB) → Hoàn tất.
 * Bước ③: nút "Xác nhận import" ghi vào hệ thống rồi chuyển sang trang Giáo trình.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Import syllabus từ file' } });

const props = defineProps({
    canUpload: { type: Boolean, default: false },
    preview: { type: Object, default: null },
    errors: { type: Object, default: () => ({}) },
});

const step = computed(() => (props.preview ? 2 : 1));
const steps = [
    [1, 'Tải file'],
    [2, 'Kiểm tra'],
    [3, 'Hoàn tất'],
];

const fileName = ref('');
const processing = ref(false);
function submit(event) {
    router.post(event.target.action, new FormData(event.target), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}

function confirmImport() {
    router.post(route('syllabus.import.confirm'), { token: props.preview.token }, {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}

function formatSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}
</script>

<template>
    <div>
        <UiPageHeader title="Import syllabus từ file" icon="upload_file" description="Tạo giáo trình cùng chặng, unit, buổi học và tài liệu từ một file .zip (hoặc .xlsx). Kiểm tra trước, chưa ghi gì vào hệ thống." :back="route('syllabus.builder')">
            <template #actions>
                <UiButton variant="secondary" icon="download" :href="route('syllabus.import.template')" native>Tải file mẫu (.zip)</UiButton>
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <ol class="flex items-center justify-center gap-md rounded-xl bg-surface-container-low p-md">
                <li v-for="[n, label] in steps" :key="n" class="flex items-center gap-sm">
                    <span
                        :class="[
                            'flex h-8 w-8 items-center justify-center rounded-full border-2 font-body-medium',
                            n === step ? 'border-primary-container text-primary' : n < step ? 'border-tertiary bg-tertiary text-white' : 'border-outline-variant text-on-surface-variant',
                        ]"
                        >{{ n < step ? '✓' : n }}</span
                    >
                    <span :class="['font-body-medium', n === step ? 'text-primary' : 'text-on-surface-variant']">{{ label }}</span>
                    <span v-if="n < 3" class="hidden h-px w-16 bg-outline-variant sm:block" aria-hidden="true"></span>
                </li>
            </ol>

            <!-- Bước 1: tải file -->
            <form
                v-if="step === 1"
                id="syllabus-import-form"
                method="POST"
                :action="route('syllabus.import.preview')"
                enctype="multipart/form-data"
                class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg"
                @submit.prevent="submit"
            >
                <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-xl text-center hover:border-primary-container">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-fixed text-primary">
                        <span class="material-symbols-outlined text-[32px]" aria-hidden="true">upload_file</span>
                    </span>
                    <span class="font-h3 text-h3 text-on-surface">Nhấn để chọn file syllabus</span>
                    <span class="font-body-small text-body-small text-on-surface-variant">.zip / .xlsx (tối đa 100MB)</span>
                    <input type="file" name="file" accept=".zip,.xlsx" class="sr-only" @change="fileName = $event.target.files[0]?.name || ''" />
                    <span v-if="fileName" class="rounded-lg bg-tertiary-fixed/40 px-sm py-xs font-body-small text-body-small text-on-tertiary-fixed-variant">{{ 'Đã chọn: ' + fileName }}</span>
                </label>

                <fieldset v-if="canUpload" class="space-y-xs">
                    <legend class="font-body-medium text-body-medium">Quyền xem tài liệu trong file</legend>
                    <input type="hidden" name="visible_to_teachers" value="0" />
                    <UiCheckbox name="visible_to_teachers" value="1" :checked="true" label="Giáo viên xem được" />
                    <input type="hidden" name="visible_to_assistants" value="0" />
                    <UiCheckbox name="visible_to_assistants" value="1" label="Trợ giảng xem được" />
                    <input type="hidden" name="downloadable" value="0" />
                    <UiCheckbox name="downloadable" value="1" label="Cho tải về" />
                </fieldset>

                <UiAlert type="info" title="File gồm">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li>Sheet <b>Giáo trình</b>, <b>Chặng</b>, <b>Buổi</b> trong <code>syllabus.xlsx</code>.</li>
                        <li>Tài liệu (nếu có) đặt trong <code>tai-lieu/chang-N/</code>; file ở gốc <code>tai-lieu/</code> dùng chung cho cả giáo trình.</li>
                        <li>Tải file mẫu ở góc trên để xem đúng cấu trúc.</li>
                    </ul>
                </UiAlert>

                <p v-if="errors.file" class="font-body-small text-body-small text-error" role="alert">{{ errors.file }}</p>

                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" :href="route('syllabus.builder')">Hủy</UiButton>
                    <UiButton type="submit" icon="preview" :disabled="processing">Kiểm tra file</UiButton>
                </div>
            </form>

            <!-- Bước 2: kết quả kiểm tra -->
            <template v-else>
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                    <p v-if="preview.curriculum" class="font-h3 text-h3">
                        <span class="font-code">{{ preview.curriculum.code }}</span> · {{ preview.curriculum.title }}
                        <UiBadge color="neutral">{{ preview.curriculum.version }}</UiBadge>
                    </p>
                    <p class="font-body-small text-body-small text-on-surface-variant">File {{ preview.file_name }}</p>
                </div>

                <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
                    <UiStatCard label="Chặng" :value="preview.summary.stages" tone="primary" icon="flag" />
                    <UiStatCard label="Unit" :value="preview.summary.units" tone="primary" icon="menu_book" />
                    <UiStatCard label="Buổi" :value="preview.summary.lessons" tone="primary" icon="event_note" />
                    <UiStatCard label="Tài liệu" :value="preview.summary.documents" tone="primary" icon="description" />
                </div>

                <UiAlert v-if="preview.errors.length" type="error" :title="`${preview.errors.length} lỗi — phải sửa file trước khi import`">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li v-for="(e, i) in preview.errors" :key="i">{{ e }}</li>
                    </ul>
                </UiAlert>
                <UiAlert v-if="preview.warnings.length" type="warning" title="Cảnh báo (không chặn import)">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li v-for="(w, i) in preview.warnings" :key="i">{{ w }}</li>
                    </ul>
                </UiAlert>

                <section v-for="stage in preview.stages" :key="stage.position" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                    <h2 class="font-h3 text-h3">Chặng {{ stage.position }}: {{ stage.name }}</h2>
                    <div v-for="unit in stage.units" :key="unit.unit_number" class="space-y-xs">
                        <h3 class="font-body-medium text-body-medium">Unit {{ unit.unit_number }}: {{ unit.title }}</h3>
                        <ul class="list-disc pl-lg font-body-small text-body-small text-on-surface-variant">
                            <li v-for="lesson in unit.lessons" :key="lesson.session_no">Buổi {{ lesson.session_no }}: {{ lesson.title }}</li>
                        </ul>
                    </div>
                    <p v-if="!stage.units.length" class="font-body-small text-body-small text-on-surface-variant">Chưa có unit / buổi.</p>
                    <div v-if="stage.documents.length" class="border-t border-outline-variant pt-sm">
                        <h3 class="font-body-medium text-body-medium">Tài liệu ({{ stage.documents.length }})</h3>
                        <ul class="list-disc pl-lg font-body-small text-body-small text-on-surface-variant">
                            <li v-for="(d, i) in stage.documents" :key="i">{{ d.title }}.{{ d.extension }} · {{ formatSize(d.size) }}</li>
                        </ul>
                    </div>
                </section>

                <section v-if="preview.shared_documents.length" class="space-y-xs rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                    <h2 class="font-h3 text-h3">Tài liệu dùng chung ({{ preview.shared_documents.length }})</h2>
                    <ul class="list-disc pl-lg font-body-small text-body-small text-on-surface-variant">
                        <li v-for="(d, i) in preview.shared_documents" :key="i">{{ d.title }}.{{ d.extension }} · {{ formatSize(d.size) }}</li>
                    </ul>
                </section>

                <p v-if="errors.file" class="font-body-small text-body-small text-error" role="alert">{{ errors.file }}</p>

                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" icon="upload_file" :href="route('syllabus.import')">Tải file khác</UiButton>
                    <UiButton icon="check" :disabled="processing || preview.errors.length > 0" @click="confirmImport">{{ processing ? 'Đang import…' : 'Xác nhận import' }}</UiButton>
                </div>
            </template>
        </div>
    </div>
</template>
