<script setup>
/**
 * Nhập danh sách lớp học viên từ hệ thống cũ — 3 bước: Tải file → Xem trước (lớp + học viên) → Kết quả.
 * File gồm sheet "Lớp" (tạo lớp, lịch học, sinh buổi học) và sheet "Học viên" (tạo hồ sơ, xếp lớp).
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import BackgroundTasks from '@/Components/BackgroundTasks/BackgroundTasks.vue';

defineOptions({ layout: { title: 'Nhập danh sách lớp' } });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    preview: { type: Object, default: null },
    result: { type: Object, default: null },
    // Lần nhập đang chạy nền (?task=): hiện trạng thái thay cho kết quả, tự tải lại tới khi xong.
    backgroundTasks: { type: Array, default: () => [] },
    classHeadings: { type: String, default: '' },
    studentHeadings: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const running = computed(() => props.backgroundTasks.length > 0);
const step = computed(() => (props.result || running.value ? 3 : props.preview ? 2 : 1));
const steps = [
    [1, 'Tải file'],
    [2, 'Xem trước'],
    [3, 'Kết quả'],
];
const actionLabels = { create: ['Tạo mới', 'info'], enroll: ['Xếp lớp', 'primary'], skip: ['Đã có', 'neutral'] };

const newClasses = computed(() => (props.preview ? props.preview.classes.filter((c) => !c.errors.length && !c.exists).length : 0));
const classErrors = computed(() => (props.preview ? props.preview.classes.filter((c) => c.errors.length).length : 0));
const classLinks = computed(() => (props.preview ? props.preview.classes.filter((c) => !c.errors.length && c.exists && (c.link_room || c.link_teacher)).length : 0));
const validStudents = computed(() => (props.preview ? props.preview.students.filter((s) => !s.errors.length && s.action !== 'skip').length : 0));
const studentErrors = computed(() => (props.preview ? props.preview.students.filter((s) => s.errors.length).length : 0));
const studentFilter = ref('all');
const shownStudents = computed(() => {
    if (!props.preview) return [];
    if (studentFilter.value === 'errors') return props.preview.students.filter((s) => s.errors.length);
    if (studentFilter.value === 'warnings') return props.preview.students.filter((s) => !s.errors.length && s.warnings.length);
    return props.preview.students;
});

const fileName = ref('');
const processing = ref(false);
function submit(event) {
    router.post(event.target.action, new FormData(event.target), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <div>
        <UiPageHeader title="Nhập danh sách lớp" icon="upload_file" description="Tạo lớp (giáo viên, phòng, lịch học, buổi học) và hồ sơ học viên từ danh sách lớp của hệ thống cũ. Chạy lại không tạo trùng.">
            <template #actions>
                <UiButton variant="secondary" icon="download" :href="route('classes.import.template')" native>Tải file mẫu (.xlsx)</UiButton>
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
                id="class-roster-import-form"
                method="POST"
                :action="route('classes.import.store')"
                enctype="multipart/form-data"
                class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg"
                @submit.prevent="submit"
            >
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiSelect name="branch_id" label="Cơ sở mặc định" :options="branches" placeholder="-- Lấy theo cột Cơ sở --" :error="errors.branch_id" hint="Dùng cho dòng không ghi cột Cơ sở." />
                    <UiInput name="sessions_from" type="date" label="Sinh buổi học từ ngày" :value="defaults.sessions_from" required :error="errors.sessions_from" hint="Không sinh buổi trong quá khứ." />
                    <UiInput name="sessions_to" type="date" label="Đến ngày" :value="defaults.sessions_to" required :error="errors.sessions_to" hint="Lớp có ngày kết thúc khóa thì sinh đến ngày đó." />
                </div>

                <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-xl text-center hover:border-primary-container">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-fixed text-primary">
                        <span class="material-symbols-outlined text-[32px]" aria-hidden="true">upload_file</span>
                    </span>
                    <span class="font-h3 text-h3 text-on-surface">Nhấn để chọn file danh sách lớp</span>
                    <span class="font-body-small text-body-small text-on-surface-variant">.xlsx / .xls (tối đa 20MB)</span>
                    <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" class="sr-only" @change="fileName = $event.target.files[0]?.name || ''" />
                    <span v-if="fileName" class="rounded-lg bg-tertiary-fixed/40 px-sm py-xs font-body-small text-body-small text-on-tertiary-fixed-variant">{{ 'Đã chọn: ' + fileName }}</span>
                </label>

                <UiAlert type="info" title="File gồm 2 sheet">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li><b>Lớp</b> (tùy chọn nếu lớp đã có): {{ classHeadings }}.</li>
                        <li><b>Học viên</b>: {{ studentHeadings }}.</li>
                        <li>Lớp đã có (trùng mã) giữ nguyên; học viên đã có (trùng mã) chỉ được xếp thêm vào lớp, không sửa hồ sơ.</li>
                        <li>Nhận cả file xuất gốc từng lớp của hệ thống cũ (cột "Lớp", "Điện thoại PH" ghi nhiều số).</li>
                    </ul>
                </UiAlert>

                <p v-if="errors.excel_file" class="font-body-small text-body-small text-error" role="alert">{{ errors.excel_file }}</p>

                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" :href="route('classes.index')">Hủy</UiButton>
                    <UiButton type="submit" icon="preview" :disabled="processing">Kiểm tra &amp; xem trước</UiButton>
                </div>
            </form>

            <!-- Bước 2: xem trước -->
            <template v-else-if="step === 2">
                <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
                    <UiStatCard label="Lớp tạo mới" :value="newClasses" tone="success" icon="co_present" />
                    <UiStatCard label="Lớp lỗi" :value="classErrors" tone="error" icon="error" />
                    <UiStatCard label="Học viên sẽ nhập" :value="validStudents" tone="success" icon="group_add" />
                    <UiStatCard label="Học viên lỗi (bỏ qua)" :value="studentErrors" tone="error" icon="person_off" />
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">File {{ preview.file_name }} · buổi học sinh từ {{ preview.sessions_from }} đến {{ preview.sessions_to }} (hoặc đến ngày kết thúc khóa của lớp).</p>

                <UiDataTable v-if="preview.classes.length" min-width="980px">
                    <template #header>
                        <h2 class="font-h3 text-h3">Lớp ({{ preview.classes.length }})</h2>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Dòng</th>
                                <th>Lớp</th>
                                <th>Cơ sở</th>
                                <th>Giáo viên</th>
                                <th>Lịch học</th>
                                <th class="text-right">Học viên</th>
                                <th>Kiểm tra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in preview.classes" :key="c.line" :class="{ '!bg-error-container': c.errors.length }">
                                <td class="font-code">{{ c.line }}</td>
                                <td>
                                    <div class="font-body-medium">{{ c.code }}</div>
                                    <UiBadge :color="c.exists ? 'neutral' : 'info'">{{ c.exists ? 'Đã có' : 'Tạo mới' }}</UiBadge>
                                </td>
                                <td>{{ c.branch ?? '—' }}</td>
                                <td>
                                    {{ c.teacher ?? '—' }}
                                    <span v-if="c.teacher && !c.teacher_matched" class="block font-caption text-caption text-warning">chưa khớp tài khoản</span>
                                </td>
                                <td class="font-caption text-caption">
                                    <div v-for="s in c.schedule" :key="s">{{ s }}</div>
                                    <span v-if="!c.schedule.length" class="text-on-surface-variant">Chưa có lịch</span>
                                </td>
                                <td class="text-right">{{ c.student_count }}</td>
                                <td>
                                    <UiBadge v-if="!c.errors.length && !c.warnings.length" color="success">Hợp lệ</UiBadge>
                                    <ul class="list-disc space-y-0.5 pl-md font-caption text-caption">
                                        <li v-for="(e, i) in c.errors" :key="'e' + i" class="text-error">{{ e }}</li>
                                        <li v-for="(w, i) in c.warnings" :key="'w' + i" class="text-warning">{{ w }}</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <UiDataTable min-width="980px">
                    <template #header>
                        <div class="flex flex-wrap items-center justify-between gap-sm">
                            <h2 class="font-h3 text-h3">Học viên ({{ preview.students.length }})</h2>
                            <div class="flex gap-xs">
                                <UiButton size="sm" :variant="studentFilter === 'all' ? 'primary' : 'secondary'" @click="studentFilter = 'all'">Tất cả</UiButton>
                                <UiButton size="sm" :variant="studentFilter === 'errors' ? 'primary' : 'secondary'" @click="studentFilter = 'errors'">Lỗi</UiButton>
                                <UiButton size="sm" :variant="studentFilter === 'warnings' ? 'primary' : 'secondary'" @click="studentFilter = 'warnings'">Cảnh báo</UiButton>
                            </div>
                        </div>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Dòng</th>
                                <th>Học viên</th>
                                <th>Lớp</th>
                                <th>SĐT</th>
                                <th>Phụ huynh</th>
                                <th>Kiểm tra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in shownStudents" :key="s.line" :class="{ '!bg-error-container': s.errors.length }">
                                <td class="font-code">{{ s.line }}</td>
                                <td>
                                    <div class="font-body-medium">{{ s.name || '—' }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">{{ s.code ?? 'Mã mới' }}</div>
                                    <UiBadge v-if="!s.errors.length" :color="actionLabels[s.action][1]">{{ actionLabels[s.action][0] }}</UiBadge>
                                </td>
                                <td>{{ s.class_code }}</td>
                                <td class="font-code">{{ s.phone ?? '—' }}</td>
                                <td class="font-caption text-caption">{{ s.parent ?? '—' }}</td>
                                <td>
                                    <UiBadge v-if="!s.errors.length && !s.warnings.length" color="success">Hợp lệ</UiBadge>
                                    <ul class="list-disc space-y-0.5 pl-md font-caption text-caption">
                                        <li v-for="(e, i) in s.errors" :key="'e' + i" class="text-error">{{ e }}</li>
                                        <li v-for="(w, i) in s.warnings" :key="'w' + i" class="text-warning">{{ w }}</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <form id="class-roster-import-confirm" method="POST" :action="route('classes.import.confirm')" class="flex flex-wrap items-center justify-between gap-sm" @submit.prevent="submit">
                    <input type="hidden" name="token" :value="preview.token" />
                    <p class="font-body-small text-body-small text-on-surface-variant">Dòng lỗi bị bỏ qua — sửa file rồi tải lên lại, dòng đã nhập sẽ không bị nhân đôi.</p>
                    <div class="flex gap-sm">
                        <UiButton variant="secondary" :href="route('classes.import')">Chọn file khác</UiButton>
                        <UiButton type="submit" icon="cloud_done" :disabled="(newClasses === 0 && validStudents === 0 && classLinks === 0) || processing">
                            Nhập {{ newClasses }} lớp, {{ validStudents }} học viên<template v-if="classLinks">, cập nhật {{ classLinks }} lớp đã có</template>
                        </UiButton>
                    </div>
                </form>
            </template>

            <!-- Bước 3: đang nhập ở nền -->
            <BackgroundTasks v-else-if="running" :tasks="backgroundTasks" />

            <!-- Bước 3: kết quả -->
            <div v-else class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                <h2 class="font-h3 text-h3">Kết quả nhập file {{ result.file_name }}</h2>
                <div class="grid grid-cols-2 gap-md lg:grid-cols-6">
                    <UiStatCard label="Lớp tạo mới" :value="result.classes" tone="success" />
                    <UiStatCard label="Buổi học đã sinh" :value="result.sessions" tone="primary" />
                    <UiStatCard label="Phòng học tạo mới" :value="result.rooms ?? 0" tone="primary" />
                    <UiStatCard label="Học viên tạo mới" :value="result.students" tone="success" />
                    <UiStatCard label="Lượt xếp lớp" :value="result.enrollments" tone="primary" />
                    <UiStatCard label="Dòng bỏ qua" :value="result.skipped" tone="warning" />
                </div>
                <UiAlert v-if="result.failed.length" type="error" title="Không ghi được">
                    <ul class="list-disc pl-md">
                        <li v-for="(failed, i) in result.failed" :key="i">{{ failed.line }}: {{ failed.error }}</li>
                    </ul>
                </UiAlert>
                <div class="flex flex-wrap justify-end gap-sm">
                    <UiButton variant="secondary" icon="upload_file" :href="route('classes.import')">Nhập file khác</UiButton>
                    <UiButton variant="secondary" icon="group" :href="route('students.index')">Danh sách học viên</UiButton>
                    <UiButton icon="co_present" :href="route('classes.index')">Danh sách lớp</UiButton>
                </div>
            </div>
        </div>
    </div>
</template>
