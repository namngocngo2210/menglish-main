<script setup>
/**
 * Quản lý đề test đầu vào (mockup quan-ly-de-dau-vao-crm/qu_n_l_test_u_v_o_danh_s_ch): tiêu đề + Tạo đề mới,
 * lọc Cấp độ / Trạng thái / Tìm kiếm (server), bảng đề (Sửa, Ẩn / Kích hoạt, Nhân bản, Xóa) và bảng bài làm & kết quả chấm.
 * A6 Q2: cấp độ = khối lớp theo "Thang điểm + hướng dẫn nhận xét", không dùng CEFR.
 */
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { currentQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Quản lý đề test đầu vào' } });

defineProps({
    tests: { type: Object, required: true },
    submissions: { type: Array, default: () => [] },
    selectedTest: { type: Object, default: null },
    stats: { type: Object, required: true },
    gradeGroups: { type: Array, default: () => [] },
});

const statusOptions = [
    { value: 'active', label: 'Hoạt động' },
    { value: 'hidden', label: 'Ẩn' },
];

/** Link số lượt làm: lọc bảng bài làm theo đề, giữ bộ lọc danh sách đề. */
function submissionsUrl(testId) {
    const query = currentQuery();
    const keep = Object.fromEntries(['search', 'grade_group', 'status'].filter((key) => query.has(key)).map((key) => [key, query.get(key)]));
    return route('placement-tests.index', { test_id: testId, ...keep }) + '#submissions';
}
</script>

<template>
    <UiPageHeader title="Quản lý đề test đầu vào" description="Quản lý và cập nhật các bộ đề đánh giá năng lực học sinh.">
        <template #actions>
            <UiButton variant="secondary" icon="rule" :href="route('placement-tests.rubric-guide')">Thang điểm &amp; hướng dẫn nhận xét</UiButton>
            <UiButton v-if="can('placement_test.create')" icon="add" :href="route('placement-tests.create')">Tạo đề mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="flex flex-col gap-lg">
        <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
            <UiStatCard label="Tổng số đề" :value="stats.total_tests" icon="quiz" tone="primary" />
            <UiStatCard label="Đang hoạt động" :value="stats.active_tests" icon="visibility" tone="success" :hint="`${stats.hidden_tests} đề đang ẩn`" />
            <UiStatCard label="Lượt làm bài" :value="stats.total_submissions" icon="assignment_turned_in" tone="secondary" />
            <UiStatCard label="Bài chờ chấm" :value="stats.pending_submissions" icon="pending_actions" tone="warning" hint="Viết / Nói do Học vụ chấm" />
        </div>

        <!-- Bộ lọc (server) -->
        <UiFilterBar :action="route('placement-tests.index')" placeholder="Nhập tên đề cần tìm..." class="!mb-0">
            <UiSelect name="grade_group" label="Cấp độ" placeholder="Tất cả cấp độ" :options="gradeGroups" />
            <UiSelect name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :options="statusOptions" />
        </UiFilterBar>

        <UiDataTable min-width="980px" sticky="both">
            <table>
                <thead>
                    <tr>
                        <th>Tên đề test</th>
                        <th>Loại đề</th>
                        <th>Cấp độ</th>
                        <th>Thời gian</th>
                        <th class="text-center">Lượt làm</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tests.data" :key="t.id">
                        <td>
                            <Link :href="route('placement-tests.show', t.id)" class="block font-body-medium text-body-medium text-on-surface hover:text-primary">{{ t.title }}</Link>
                            <span class="font-code text-caption text-on-surface-variant">ID: {{ t.code }}</span>
                            <span v-if="t.is_preset" class="ml-xs inline-flex items-center gap-0.5 rounded bg-surface-container-high px-1.5 font-caption text-caption text-on-surface-variant"><span class="material-symbols-outlined text-[12px]">lock</span>Đề chuẩn</span>
                        </td>
                        <td><code class="rounded bg-surface-container-low px-sm py-0.5 font-code text-caption text-on-surface-variant">{{ t.type }}</code></td>
                        <td class="whitespace-nowrap">
                            <UiBadge color="secondary" pill :dot="false">{{ t.grade_group_label }}</UiBadge>
                            <div v-if="t.target_level" class="mt-xs font-caption text-caption text-on-surface-variant">{{ t.target_level }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <span class="inline-flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">schedule</span>{{ t.duration_minutes }} phút</span>
                        </td>
                        <td class="text-center">
                            <Link :href="submissionsUrl(t.id)" :class="['font-code text-code', t.submissions_count > 0 ? 'text-tertiary hover:underline' : 'text-on-surface-variant']">{{ t.submissions_count }}</Link>
                        </td>
                        <td class="whitespace-nowrap">
                            <UiBadge v-if="t.is_active" color="success" pill>Hoạt động</UiBadge>
                            <UiBadge v-else color="neutral" pill>Ẩn</UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-xs">
                                <UiButton v-if="t.is_active" variant="ghost" size="sm" icon="play_circle" :href="route('portal.test.take', t.code)" target="_blank" title="Mở link làm bài" aria-label="Mở link làm bài" />
                                <UiButton variant="ghost" size="sm" icon="visibility" :href="route('placement-tests.show', t.id)" title="Xem đề & câu hỏi" aria-label="Xem đề" />
                                <template v-if="can('placement_test.update')">
                                    <UiButton v-if="!t.is_preset" variant="ghost" size="sm" icon="edit" :href="route('placement-tests.edit', t.id)" title="Chỉnh sửa" aria-label="Chỉnh sửa" />
                                    <UiForm :action="route('placement-tests.toggle-active', t.id)" method="post" class="inline">
                                        <UiButton type="submit" variant="ghost" size="sm" :icon="t.is_active ? 'visibility_off' : 'visibility'" :title="t.is_active ? 'Ẩn đề' : 'Kích hoạt'" :aria-label="t.is_active ? 'Ẩn đề' : 'Kích hoạt'" />
                                    </UiForm>
                                </template>
                                <UiForm v-if="can('placement_test.create')" :action="route('placement-tests.duplicate', t.id)" method="post" class="inline">
                                    <UiButton type="submit" variant="ghost" size="sm" icon="content_copy" title="Nhân bản đề" aria-label="Nhân bản đề" />
                                </UiForm>
                                <UiForm v-if="can('placement_test.delete') && !t.is_preset && t.submissions_count === 0" :action="route('placement-tests.destroy', t.id)" method="delete" class="inline" confirm="Xóa đề thi này?" confirm-label="Xóa" danger>
                                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa đề (chưa có bài làm)" aria-label="Xóa đề" />
                                </UiForm>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!tests.data.length">
                        <td colspan="7"><UiEmptyState icon="search_off" title="Không tìm thấy đề test" description="Thử đổi từ khóa hoặc bấm Làm mới để bỏ lọc." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="tests" unit="đề test" /></template>
        </UiDataTable>

        <!-- Bài làm gần đây (theo phạm vi khách CRM) — chấm theo thang điểm khối lớp -->
        <UiDataTable id="submissions" min-width="1080px" sticky="both" class="scroll-mt-6">
            <template #header>
                <div class="flex flex-wrap items-center gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Bài làm &amp; kết quả chấm</h2>
                    <UiBadge v-if="selectedTest" color="info">Đề: {{ selectedTest.title }} ({{ selectedTest.code }}) · {{ submissions.length }} thí sinh</UiBadge>
                    <span v-else class="font-body-small text-body-small text-on-surface-variant">{{ submissions.length }} bài nộp gần nhất</span>
                </div>
                <UiButton v-if="selectedTest" variant="ghost" size="sm" icon="close" :href="route('placement-tests.index') + '#submissions'">Bỏ lọc đề này</UiButton>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Thí sinh</th>
                        <th>Số điện thoại</th>
                        <th>Đề kiểm tra</th>
                        <th class="text-center">Nghe</th>
                        <th class="text-center">Đọc &amp; Viết</th>
                        <th class="text-center">Nói</th>
                        <th class="text-center">Tổng điểm</th>
                        <th>Lớp xếp</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sub in submissions" :key="sub.id">
                        <td class="whitespace-nowrap">
                            <div class="flex items-center gap-xs font-body-medium text-body-medium text-on-surface">
                                {{ sub.candidate_name }}
                                <Link v-if="sub.customer_id" :href="route('crm.customers.show', sub.customer_id)" class="rounded bg-secondary/10 px-1.5 font-caption text-caption text-secondary hover:underline" title="Mở hồ sơ khách">Khách CRM</Link>
                                <span v-if="sub.violation_count > 0 || sub.auto_submitted" class="rounded bg-error/10 px-1.5 font-caption text-caption text-error" title="Số lần thí sinh rời khỏi bài thi">Rời bài {{ sub.violation_count }} lần{{ sub.auto_submitted ? ' · tự nộp' : '' }}</span>
                            </div>
                            <div class="font-code text-caption text-on-surface-variant">{{ formatDate(sub.created_at, 'H:i d/m/Y') }}</div>
                        </td>
                        <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ sub.candidate_phone }}</td>
                        <td class="whitespace-nowrap">
                            <div class="text-on-surface">{{ sub.test_title }}</div>
                            <div class="font-code text-caption text-on-surface-variant">ID: {{ sub.test_code }}</div>
                        </td>
                        <td class="text-center font-code text-code">{{ sub.listening }}</td>
                        <td class="text-center font-code text-code">{{ sub.reading_writing }}</td>
                        <td class="text-center font-code text-code">{{ sub.speaking }}</td>
                        <td class="whitespace-nowrap text-center">
                            <UiBadge v-if="sub.is_pending" color="warning" pill>Chờ chấm</UiBadge>
                            <span v-else class="font-code text-code font-bold text-primary">{{ sub.total }}</span>
                        </td>
                        <td class="whitespace-nowrap font-body-medium text-body-medium text-primary">{{ sub.final_class }}</td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-xs">
                                <UiButton variant="secondary" size="sm" icon="description" :href="sub.scorecard_url" target="_blank">Phiếu điểm</UiButton>
                                <UiButton v-if="can('placement_test.grade')" variant="ghost" size="sm" icon="edit_note" :href="route('placement-tests.results.show', sub.id)">{{ sub.is_pending ? 'Chấm bài' : 'Chấm lại' }}</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!submissions.length">
                        <td colspan="9"><UiEmptyState icon="assignment_late" :title="selectedTest ? 'Chưa có thí sinh nào nộp bài cho đề này' : 'Chưa có bài thi nào được nộp'" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
</template>
