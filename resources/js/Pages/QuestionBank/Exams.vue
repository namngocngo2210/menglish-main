<script setup>
/**
 * Đề cố định (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2): mỗi khối một bảng mã đề (kỳ, số câu, thời gian, trạng thái,
 * số lượt / điểm TB để so giữa các mã đề), import file Excel + Word hoặc ZIP; kết quả import hiện từng đề.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Đề cố định' } });

defineProps({
    grades: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
    importReport: { type: Object, default: null },
});

const STATUS_COLORS = { draft: 'warning', published: 'success', hidden: 'neutral' };
const scoreText = (score) => (score === null ? '—' : Number(score).toLocaleString('vi-VN', { maximumFractionDigits: 2 }));

const importOpen = ref(false);
const fileNames = ref('');
function onPick(event) {
    fileNames.value = [...(event.target.files ?? [])].map((f) => f.name).join(', ');
}
function onImported() {
    importOpen.value = false;
    fileNames.value = '';
}
</script>

<template>
    <UiPageHeader title="Đề cố định" description="Nguyên bộ đề theo mã (vd. đề GK1 khối 6 mã 601). Học viên chỉ thấy đề đã mở của khối mình và chọn từng đề để làm.">
        <template v-if="canManage" #actions>
            <UiButton icon="upload_file" @click="importOpen = true">Import đề</UiButton>
        </template>
    </UiPageHeader>

    <UiTabs class="mb-lg">
        <UiTab :href="route('question-bank.index')">Câu hỏi</UiTab>
        <UiTab :href="route('question-bank.blueprints.index')">Khung đề</UiTab>
        <UiTab :href="route('question-bank.exams.index')" active>Đề cố định</UiTab>
        <UiTab :href="route('question-bank.analysis')">Phân tích câu hỏi</UiTab>
    </UiTabs>

    <section v-if="importReport" class="mb-lg space-y-sm" data-import-report>
        <UiAlert v-if="importReport.saved.length" type="success" title="Đã nhập (trạng thái Nháp — xem lại rồi bấm Mở cho học viên)">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in importReport.saved" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="importReport.kept.length" type="info" title="Giữ nguyên">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in importReport.kept" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="importReport.warnings.length" type="warning" title="Cần kiểm tra">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in importReport.warnings" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="importReport.errors.length" type="error" title="Không nhập được">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in importReport.errors" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
    </section>

    <section v-for="g in grades" :key="g.grade" class="mb-xl" :aria-label="`Khối ${g.grade}`" :data-grade="g.grade">
        <h2 class="mb-sm font-h3 text-h3 text-on-surface">Khối {{ g.grade }}</h2>
        <UiDataTable min-width="760px">
            <table>
                <thead>
                    <tr>
                        <th>Đề</th>
                        <th class="w-32">Kỳ</th>
                        <th class="w-28 text-right">Câu / phút</th>
                        <th class="w-28">Trạng thái</th>
                        <th class="w-36 text-right">Lượt làm</th>
                        <th class="w-24 text-right">Điểm TB</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="exam in g.exams" :key="exam.id" :data-exam="exam.code">
                        <td>
                            <Link :href="route('question-bank.exams.show', exam.id)" class="font-semibold text-primary hover:underline">{{ exam.label }}</Link>
                            <div class="font-caption text-caption text-on-surface-variant">{{ exam.title }}</div>
                            <UiBadge v-if="exam.warnings.length" color="warning" :dot="false" class="mt-xs">{{ exam.warnings.length }} cảnh báo</UiBadge>
                        </td>
                        <td>{{ exam.term_label }}</td>
                        <td class="text-right font-code">{{ exam.question_count }} / {{ exam.duration_minutes }}</td>
                        <td><UiBadge :color="STATUS_COLORS[exam.status] ?? 'neutral'">{{ exam.status_label }}</UiBadge></td>
                        <td class="text-right font-code">{{ exam.attempts }}<span class="text-on-surface-variant"> ({{ exam.students }} HV)</span></td>
                        <td class="text-right font-code">{{ scoreText(exam.avg_score) }}</td>
                        <td class="text-right">
                            <UiButton variant="ghost" icon="visibility" :href="route('question-bank.exams.show', exam.id)" :aria-label="`Xem ${exam.label}`" title="Xem đề" />
                        </td>
                    </tr>
                    <tr v-if="!g.exams.length">
                        <td colspan="7" class="text-center font-body-small text-body-small text-on-surface-variant">Chưa có đề cố định cho khối {{ g.grade }}.</td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </section>

    <UiModal v-if="canManage" :show="importOpen" title="Import đề cố định" max-width="lg" @close="importOpen = false">
        <UiForm :action="route('question-bank.exams.import')" method="post" class="space-y-md" reset-on-success @success="onImported">
            <template #default="{ errors, processing }">
                <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-lg text-center hover:border-primary-container">
                    <span class="material-symbols-outlined text-[32px] text-primary" aria-hidden="true">upload_file</span>
                    <span class="font-body-medium text-body-medium text-on-surface">Chọn file .zip, hoặc các file .xlsx + .docx (tối đa 50MB mỗi file)</span>
                    <input type="file" name="files[]" accept=".zip,.xlsx,.docx" multiple class="sr-only" required @change="onPick" />
                    <span v-if="fileNames" class="font-body-small text-body-small text-on-surface-variant">Đã chọn: {{ fileNames }}</span>
                </label>
                <p v-if="errors.files || errors['files.0']" class="font-body-small text-body-small text-error" role="alert">{{ errors.files || errors['files.0'] }}</p>
                <UiAlert type="info" title="Lưu ý">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li>File Excel đề kèm đáp án: mỗi sheet một mã đề, tiêu đề dạng "ĐỀ KIỂM TRA GIỮA HỌC KÌ I … TIẾNG ANH 6 – MÃ ĐỀ 601".</li>
                        <li>Kèm file Word của đề để giữ gạch chân câu Phát âm (Excel không lưu gạch chân).</li>
                        <li>Đề mới vào trạng thái <b>Nháp</b>. Đề đã mở / đã ẩn giữ nguyên khi import lại.</li>
                    </ul>
                </UiAlert>
                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" :disabled="processing" @click="importOpen = false">Hủy</UiButton>
                    <UiButton type="submit" icon="upload_file" :disabled="processing">{{ processing ? 'Đang import…' : 'Import' }}</UiButton>
                </div>
            </template>
        </UiForm>
    </UiModal>
</template>
