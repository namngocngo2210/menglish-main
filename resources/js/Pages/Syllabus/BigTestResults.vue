<script setup>
/**
 * Bảng điểm Big Test. Giáo viên (syllabus.update, không phải người duyệt) nhập điểm 4 kỹ năng / vắng thi / nhận xét / video
 * rồi "Lưu nháp" hoặc "Gửi duyệt"; người duyệt (big_test.approve) chỉ xem & duyệt, gửi phụ huynh — không nhập điểm.
 * Mockup 01_Web_Admin/07: xét duyệt kết quả từng học viên trong hộp thoại (?result=; đóng thì bỏ query).
 */
import { reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Kết quả Big Test' } });

const props = defineProps({
    test: { type: Object, default: null },
    allTests: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    selectedResult: { type: Object, default: null },
    userName: { type: String, default: '' },
    isApprover: { type: Boolean, default: false },
    canGradeRole: { type: Boolean, default: false },
    canGrade: { type: Boolean, default: false },
    backUrl: { type: String, default: null },
    resultDeadlineDays: { type: Number, default: 7 },
    missingPhoneLabel: { type: String, default: '' },
});

const skills = { listening_score: 'Nghe', reading_score: 'Đọc', writing_score: 'Viết', speaking_score: 'Nói' };
const detailSkills = { listening_score: 'Listening', reading_score: 'Reading', writing_score: 'Writing', speaking_score: 'Speaking' };
const statusColor = { approved: 'success', sent: 'info', pending_review: 'warning' };

// "Vắng thi" theo từng học viên: tích → khoá ô điểm của dòng đó.
const absent = reactive({});
function syncAbsent() {
    props.rows.forEach((r) => (absent[r.student_id] = !!r.result?.is_absent));
}
syncAbsent();
watch(() => props.rows, syncAbsent);

const locked = (row) => !props.canGrade || !!row.result?.locked;
const hasUnlocked = () => props.rows.some((r) => !r.result?.locked);

function pickTest(event) {
    if (event.target.value) router.visit(route('syllabus.big-tests.results') + '/' + event.target.value);
}
function print() {
    window.print();
}

const detailOpen = ref(!!props.selectedResult);
watch(() => props.selectedResult?.id, (id) => (detailOpen.value = !!id));
</script>

<template>
    <UiPageHeader
        :title="isApprover ? 'Duyệt kết quả Big Test & gửi phụ huynh' : 'Nhập điểm Big Test'"
        :description="isApprover ? 'Bảng điểm 4 kỹ năng, nhận xét, link video; Học thuật duyệt và gửi kết quả cho phụ huynh qua Zalo.' : 'Nhập điểm 4 kỹ năng, nhận xét, link video cho lớp mình dạy rồi gửi Học thuật duyệt.'"
        :back="backUrl"
    >
        <template #breadcrumbs>
            <span>Học thuật</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="font-semibold text-on-surface">Quản lý Big Test</span>
        </template>
        <template #actions>
            <template v-if="test && isApprover">
                <!-- Việc chính của người duyệt là duyệt; gửi phụ huynh là bước sau nên để nút phụ -->
                <UiForm :action="route('syllabus.big-tests.results.approve', test.id)" method="post">
                    <UiButton type="submit" icon="task_alt">Duyệt kết quả</UiButton>
                </UiForm>
                <UiForm :action="route('syllabus.big-tests.send-zalo', test.id)" method="post">
                    <UiButton type="submit" variant="secondary" icon="send" title="Gửi các kết quả đã duyệt cho phụ huynh qua Zalo">Gửi phụ huynh</UiButton>
                </UiForm>
            </template>
            <UiButton variant="secondary" icon="print" @click="print">In bảng điểm</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-5">
        <!-- 1. Chọn kỳ thi & thống kê nhanh -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
            <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs lg:col-span-2">
                <UiSelect id="big-test-selector" label="Chọn Kỳ Thi Big Test:" :value="test?.id ?? ''" class="font-semibold" @change="pickTest">
                    <option v-if="!allTests.length" value="">Chưa có kỳ thi Big Test nào</option>
                    <option v-for="t in allTests" :key="t.value" :value="t.value" :selected="test?.id === t.value">{{ t.label }}</option>
                </UiSelect>
                <div v-if="test" class="flex flex-wrap items-center gap-2 pt-1 font-mono text-xs text-on-surface-variant">
                    <span class="rounded-md bg-secondary/10 px-2 py-0.5 font-bold text-secondary">Lớp: {{ test.class_name }}</span>
                    <span class="rounded-md bg-surface-container px-2 py-0.5 text-on-surface-variant">Mã: {{ test.code }}</span>
                    <span class="rounded-md bg-surface-container px-2 py-0.5 text-on-surface-variant">Phòng: {{ test.room }}</span>
                    <span v-if="test.results_due" :class="['rounded-md px-2 py-0.5', test.results_overdue ? 'bg-error/10 font-bold text-error' : 'bg-warning/10 text-on-warning-container']" :title="`Hạn trả kết quả = ngày thi + ${resultDeadlineDays} ngày`">Hạn trả KQ: {{ test.results_due }}</span>
                    <span v-if="test.stage_badge" class="rounded-md bg-secondary/10 px-2 py-0.5 font-bold text-secondary" title="Duyệt và gửi đủ kết quả cho phụ huynh sẽ đóng chặng này và tự mở chặng kế tiếp">{{ test.stage_badge }}</span>
                </div>
            </div>

            <UiStatCard label="Điểm Trung Bình Cả Lớp" tone="secondary" icon="award_star" :hint="`Dựa trên ${stats.taken} học viên dự thi (${stats.absent} vắng)`">
                {{ stats.avg }} <span class="text-xs font-normal text-on-surface-subtle">/ 10</span>
            </UiStatCard>
            <UiStatCard label="Điểm Cao Nhất (Top Score)" tone="success" icon="military_tech" :hint="`Tổng số thí sinh: ${stats.total} học viên`">
                {{ stats.highest }} <span class="text-xs font-normal text-on-surface-subtle">/ 10</span>
            </UiStatCard>
        </div>

        <!-- 2. Bảng điểm -->
        <UiDataTable sticky="both">
            <template #header>
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Danh Sách Bảng Điểm Chi Tiết ({{ stats.total }} Học viên)</h2>
                    <p class="text-xs text-on-surface-variant">Kết quả khảo thí định kỳ được lưu trữ phục vụ xếp lớp và đánh giá năng lực</p>
                </div>
            </template>

            <!-- Đề chưa duyệt & phân phối thì server từ chối nhập điểm → không hiện form nhập.
                 Người duyệt chỉ xem & duyệt, không thấy form nhập điểm / Lưu nháp / Gửi duyệt của giáo viên. -->
            <div v-if="test && !test.is_distributed && canGradeRole" class="border-b border-surface-container bg-warning-container/40 px-md py-sm text-xs text-on-surface">Đề thi của đợt này chưa được duyệt và phân phối, chưa nhập điểm được.</div>

            <!-- Form gửi từng học viên nằm ngoài form nhập điểm (không lồng form); nút bấm tham chiếu qua thuộc tính form= -->
            <template v-if="test && isApprover">
                <template v-for="row in rows" :key="'send-' + row.student_id">
                    <UiForm v-if="row.result?.status === 'approved' && !row.result.parent_notified && !row.result.is_absent" :id="`send-ph-${row.result.id}`" :action="route('syllabus.big-tests.send-single-zalo', row.result.id)" method="post" class="hidden" />
                </template>
            </template>

            <component :is="canGrade ? 'UiForm' : 'div'" v-bind="canGrade ? { action: route('syllabus.big-tests.results.store', test.id), method: 'post' } : {}">
                <table class="min-w-[1100px] text-xs">
                    <thead>
                        <tr>
                            <th>Học viên &amp; Mã số</th>
                            <th>Trạng thái</th>
                            <th class="!px-xs text-center">Vắng thi</th>
                            <th class="!px-xs text-center">Listening</th>
                            <th class="!px-xs text-center">Reading</th>
                            <th class="!px-xs text-center">Writing</th>
                            <th class="!px-xs text-center">Speaking</th>
                            <th class="bg-primary-container/10 text-center !text-primary">Overall</th>
                            <th>Nhận xét &amp; video</th>
                            <th>Đã gửi PH</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="row.student_id">
                            <td>
                                <input type="hidden" :name="`results[${index}][student_id]`" :value="row.student_id" :disabled="locked(row)" />
                                <div class="font-bold text-on-surface">{{ row.name }}</div>
                                <div class="mt-0.5 max-w-[200px] truncate font-mono text-xs text-on-surface-subtle">Mã HV: <template v-if="shortCode(row.code) === row.code">{{ row.code }}</template><UiCode v-else :value="row.code" /></div>
                            </td>
                            <td class="whitespace-nowrap">
                                <UiBadge :color="statusColor[row.result?.status] ?? 'neutral'">{{ row.result?.status === 'draft' ? 'Nháp (GV chưa gửi duyệt)' : (row.result?.status_label ?? 'Chưa nhập') }}</UiBadge>
                                <Link v-if="row.result && row.result.status !== 'draft'" :href="route('syllabus.big-tests.results', { id: test.id, result: row.result.id })" class="mt-1 flex items-center gap-0.5 text-xs font-semibold text-primary hover:underline">
                                    <span class="material-symbols-outlined text-[14px]">rate_review</span>Xem &amp; duyệt
                                </Link>
                            </td>
                            <td class="!px-xs text-center">
                                <input
                                    v-model="absent[row.student_id]"
                                    type="checkbox"
                                    :name="`results[${index}][is_absent]`"
                                    value="1"
                                    :disabled="locked(row)"
                                    class="h-4 w-4 rounded border-outline-variant text-error focus:ring-error"
                                    title="Đánh dấu học viên vắng thi"
                                    :aria-label="`Vắng thi — ${row.name}`"
                                />
                            </td>
                            <td v-for="(skillLabel, skill) in skills" :key="skill" class="!px-xs text-center">
                                <input
                                    type="number"
                                    step=".1"
                                    min="0"
                                    max="10"
                                    :name="`results[${index}][${skill}]`"
                                    :value="row.result?.[skill] ?? ''"
                                    placeholder="—"
                                    :aria-label="`Điểm ${skillLabel} — ${row.name}`"
                                    :disabled="absent[row.student_id] || locked(row)"
                                    class="w-16 rounded border-surface-container-highest text-xs disabled:bg-surface-container-low"
                                />
                            </td>
                            <td class="bg-primary-container/10 text-center font-mono !text-base font-black !text-primary">
                                <span v-if="row.result?.is_absent" class="text-xs font-bold text-error">Vắng thi</span>
                                <template v-else>{{ row.result?.overall_score ?? '—' }}</template>
                            </td>
                            <td class="min-w-[180px] space-y-1">
                                <textarea :name="`results[${index}][progress_note]`" rows="2" :disabled="locked(row)" placeholder="Nhận xét tiến độ" :aria-label="`Nhận xét tiến độ — ${row.name}`" class="w-full rounded border-surface-container-highest text-xs disabled:bg-surface-container-low" :value="row.result?.progress_note ?? ''"></textarea>
                                <template v-if="locked(row)">
                                    <a v-if="row.result?.video_url" :href="row.result.video_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                                        <span class="material-symbols-outlined text-[14px]">video_library</span>Link video bài thi
                                    </a>
                                </template>
                                <input v-else type="url" :name="`results[${index}][video_url]`" :value="row.result?.video_url ?? ''" placeholder="Link video bài thi (https://...)" :aria-label="`Link video bài thi — ${row.name}`" class="w-full rounded border-surface-container-highest text-xs" />
                            </td>
                            <td class="whitespace-nowrap">
                                <span v-if="row.result?.parent_notified" class="inline-flex items-center gap-1 text-xs font-semibold text-tertiary">
                                    <span class="material-symbols-outlined text-[16px]">mark_email_read</span>{{ row.result.notified_at ?? 'Đã gửi' }}
                                </span>
                                <UiButton v-else-if="test && isApprover && row.result?.status === 'approved' && !row.result.is_absent" type="submit" :form="`send-ph-${row.result.id}`" variant="info" size="sm" icon="send">Gửi PH</UiButton>
                                <span v-else class="text-xs text-on-surface-subtle">{{ row.result?.is_absent ? 'Vắng thi' : 'Chưa gửi' }}</span>
                                <span v-if="row.result?.missing_phone" class="mt-1 block text-xs font-semibold text-error">{{ missingPhoneLabel }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="10">
                                <UiEmptyState icon="sentiment_neutral" title="Chưa có kết quả thi cho kỳ thi Big Test này." />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="canGrade && hasUnlocked()" class="flex items-center justify-between gap-3 border-t border-surface-container-highest p-4">
                    <span class="text-xs text-on-surface-variant">Học viên vắng: tích "Vắng thi" (không nhập điểm). Dòng để trống sẽ bỏ qua. "Lưu nháp" chưa gửi Học thuật (sửa tiếp được); "Gửi duyệt" cần đủ 4 kỹ năng. Điểm đã duyệt/đã gửi phụ huynh không thể sửa.</span>
                    <div class="flex items-center gap-2">
                        <UiButton type="submit" name="action" value="draft" variant="secondary" icon="draft">Lưu nháp</UiButton>
                        <UiButton type="submit" name="action" value="submit" icon="send">Gửi duyệt</UiButton>
                    </div>
                </div>
            </component>
        </UiDataTable>
    </div>

    <!-- Mockup 01_Web_Admin/07: xét duyệt kết quả từng học viên (thông tin, điểm chi tiết, video, nhận xét, tổng điểm, hạn trả KQ,
         người gửi / người duyệt). Mở khi URL có ?result=; đóng thì bỏ result khỏi thanh địa chỉ. -->
    <UiModal v-if="test && selectedResult" :show="detailOpen" :title="'Kết quả Big Test · ' + (selectedResult.student ?? 'Học viên')" max-width="4xl" :dismiss-url="route('syllabus.big-tests.results', test.id)" @close="detailOpen = false">
        <div class="grid grid-cols-1 gap-lg md:grid-cols-3">
            <div class="space-y-lg md:col-span-2">
                <section>
                    <h3 class="mb-sm font-label text-label uppercase text-on-surface-variant">Thông tin chung</h3>
                    <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                        <div>
                            <dt class="font-caption text-caption text-on-surface-variant">Học viên</dt>
                            <dd class="font-body-medium text-body-medium font-semibold text-on-surface">{{ selectedResult.student }}</dd>
                            <dd class="font-caption text-caption text-on-surface-variant">Mã HV: <template v-if="shortCode(selectedResult.student_code) === selectedResult.student_code">{{ selectedResult.student_code }}</template><UiCode v-else :value="selectedResult.student_code" /></dd>
                        </div>
                        <div>
                            <dt class="font-caption text-caption text-on-surface-variant">Lớp học</dt>
                            <dd class="font-body-medium text-body-medium text-on-surface">{{ test.class_name }}</dd>
                            <dd class="font-caption text-caption text-on-surface-variant">GV: {{ test.class_teacher ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-caption text-caption text-on-surface-variant">Chặng học</dt>
                            <dd><UiBadge color="secondary" :dot="false" class="uppercase">{{ test.stage_label }}</UiBadge></dd>
                        </div>
                    </dl>
                </section>

                <section>
                    <h3 class="mb-sm font-label text-label uppercase text-on-surface-variant">Điểm chi tiết</h3>
                    <div class="grid grid-cols-2 gap-md sm:grid-cols-4">
                        <div v-for="(label, field) in detailSkills" :key="field" class="flex items-center justify-between rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm">
                            <span class="font-body-small text-body-small text-on-surface-variant">{{ label }}</span>
                            <span class="font-h3 text-h3 text-on-surface">{{ selectedResult.is_absent ? '—' : (selectedResult[field] ?? '—') }}</span>
                        </div>
                    </div>
                    <p class="mb-xs mt-md flex items-center gap-xs font-label text-label text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">link</span>Link video bài thi</p>
                    <a v-if="selectedResult.video_url" :href="selectedResult.video_url" target="_blank" rel="noopener" class="flex items-center gap-sm rounded-lg border border-outline-variant px-md py-sm font-body-small text-body-small text-primary hover:underline">
                        <span class="flex-1 truncate">{{ selectedResult.video_url }}</span>
                        <span class="material-symbols-outlined" aria-hidden="true">video_library</span>
                    </a>
                    <p v-else class="font-body-small text-body-small text-on-surface-variant">Chưa có link video.</p>
                </section>

                <section>
                    <h3 class="mb-sm font-label text-label uppercase text-on-surface-variant">Nhận xét của giáo viên/HT</h3>
                    <div class="whitespace-pre-line rounded-lg bg-surface-container-low p-md font-body-base text-body-base italic text-on-surface">{{ selectedResult.progress_note || 'Chưa có nhận xét.' }}</div>
                </section>
            </div>

            <div class="space-y-lg">
                <section class="space-y-md rounded-lg border border-outline-variant p-md">
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <h3 class="whitespace-nowrap font-h3 text-h3 text-on-surface">Tổng điểm (Big Test)</h3>
                        <UiBadge v-if="test.days_left !== null" :color="test.days_left < 0 ? 'error' : 'warning'" :dot="false" pill title="Hạn trả kết quả">
                            <span class="material-symbols-outlined text-[14px]">timer</span>{{ test.days_left < 0 ? `Quá hạn ${Math.abs(test.days_left)} ngày` : `Còn ${test.days_left} ngày` }}
                        </UiBadge>
                    </div>
                    <div class="font-h1 text-h1 text-primary">{{ selectedResult.is_absent ? 'Vắng thi' : (selectedResult.overall_score ?? '—') }}</div>
                    <div>
                        <p class="font-caption text-caption text-on-surface-variant">Trạng thái dữ liệu</p>
                        <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ selectedResult.status_label }}</p>
                    </div>
                </section>

                <section class="space-y-md rounded-lg border border-outline-variant p-md">
                    <div>
                        <p class="mb-xs font-label text-label text-on-surface-variant">Người gửi kết quả</p>
                        <div class="flex items-center gap-sm">
                            <UiAvatar :name="selectedResult.grader ?? '?'" size="sm" />
                            <span class="font-body-medium text-body-medium text-on-surface">{{ selectedResult.grader ?? '—' }}</span>
                        </div>
                    </div>
                    <div>
                        <p class="mb-xs font-label text-label text-on-surface-variant">{{ selectedResult.approver ? 'Người duyệt' : 'Người duyệt (Hiện tại)' }}</p>
                        <div class="flex items-center gap-sm">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-secondary/10 text-secondary"><span class="material-symbols-outlined text-[18px]">shield_person</span></span>
                            <span class="font-body-medium text-body-medium text-on-surface">{{ selectedResult.approver ?? userName + ' (Bạn)' }}</span>
                        </div>
                    </div>
                </section>

                <UiAlert v-if="selectedResult.parent_notified" type="success" title="Đã gửi phụ huynh">
                    <p class="font-caption text-caption">{{ selectedResult.notified_at }} — kết quả đã ghi nhận vào hồ sơ học tập của học viên.</p>
                </UiAlert>
                <UiAlert v-else-if="selectedResult.status !== 'draft'" type="success" title="Hợp lệ">
                    <p class="font-caption text-caption">Thông tin sẽ được ghi nhận vào hệ thống học tập của học viên.</p>
                </UiAlert>
            </div>
        </div>

        <template v-if="isApprover && ['pending_review', 'approved'].includes(selectedResult.status) && !selectedResult.parent_notified" #footer>
            <UiForm :action="route('syllabus.big-tests.results.approve-send', selectedResult.id)" method="post">
                <UiButton type="submit" icon="send">{{ selectedResult.is_absent ? 'Duyệt (vắng thi)' : 'Duyệt & Gửi phụ huynh' }}</UiButton>
            </UiForm>
        </template>
    </UiModal>
</template>
