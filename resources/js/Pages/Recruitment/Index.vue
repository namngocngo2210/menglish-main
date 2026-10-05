<script setup>
/**
 * Quản lý tuyển dụng & hồ sơ ứng viên: tab Hồ sơ CV (lọc trạng thái / cơ sở, cập nhật trạng thái qua modal)
 * và tab Tin tuyển dụng (đăng tin mới qua modal, đóng / mở lại tin).
 */
import { ref } from 'vue';

defineOptions({ layout: { title: 'Quản lý Tuyển dụng & Hồ sơ Ứng viên' } });

defineProps({
    tab: { type: String, default: 'candidates' },
    branches: { type: Array, default: () => [] },
    cvStatusOptions: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    jobs: { type: Array, default: () => [] },
    candidates: { type: Object, required: true },
});

const cvStatusColor = { pending: 'warning', reviewing: 'secondary', interviewed: 'info', accepted: 'success', rejected: 'error' };
const departments = ['Học thuật & Đào tạo', 'Học vụ & Vận hành', 'Tuyển sinh & CRM', 'Marketing & Sự kiện'].map((v) => ({ value: v, label: v }));
const employmentTypes = ['Full-time', 'Part-time', 'Thực tập'].map((v) => ({ value: v, label: v }));

// Cập nhật trạng thái hồ sơ: một modal cho ứng viên đang chọn (không bung popover trong bảng).
const editingCv = ref(null);
const showNewJob = ref(false);
const submitFilters = (event) => event.target.form?.requestSubmit();
</script>

<template>
    <UiPageHeader title="Quản lý Tuyển dụng & Hồ sơ Ứng viên">
        <template #actions>
            <UiButton variant="secondary" icon="open_in_new" :href="route('portal.recruitment')" target="_blank">Cổng nộp CV Online</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- 4 Metric Cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <UiStatCard label="Tin tuyển dụng đang mở" :value="`${stats.totalJobs} vị trí`" tone="secondary" icon="work" />
            <UiStatCard label="Tổng CV tiếp nhận" :value="`${stats.totalCvs} hồ sơ`" tone="primary" icon="description" />
            <UiStatCard label="Đã phỏng vấn" :value="`${stats.interviewedCount} ứng viên`" tone="secondary" icon="contact_phone" />
            <UiStatCard label="Trúng tuyển / Nhận việc" :value="`${stats.acceptedCount} nhân sự`" tone="success" icon="how_to_reg" />
        </div>

        <!-- Tabs & Filters -->
        <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-xs">
            <div class="flex flex-col justify-between gap-4 px-4 pt-2 sm:flex-row sm:items-center sm:px-5">
                <UiTabs class="flex-1">
                    <UiTab :href="route('recruitment.index', { tab: 'candidates' })" :active="tab === 'candidates'">1. Danh sách Hồ sơ CV ({{ stats.totalCvs }})</UiTab>
                    <UiTab :href="route('recruitment.index', { tab: 'jobs' })" :active="tab === 'jobs'">2. Tin Tuyển dụng ({{ jobs.length }})</UiTab>
                </UiTabs>

                <UiButton v-if="tab === 'jobs'" icon="add_circle" class="mb-2 self-start sm:self-auto" @click="showNewJob = true">Đăng tin tuyển dụng</UiButton>
            </div>

            <template v-if="tab === 'candidates'">
                <!-- Không có ô tìm kiếm; bỏ viền / bo góc của thanh lọc vì đã nằm trong khung tab. "Xóa lọc" giữ tab Hồ sơ CV -->
                <UiFilterBar
                    :action="route('recruitment.index')"
                    :search="false"
                    :reset-url="route('recruitment.index', { tab: 'candidates' })"
                    class="!mb-0 !rounded-none !border-x-0 !border-t-0 !bg-surface-container-low !shadow-none"
                >
                    <input type="hidden" name="tab" value="candidates" />
                    <UiSelect name="status" label="Trạng thái" :options="[{ value: 'all', label: 'Tất cả trạng thái' }, ...cvStatusOptions]" @change="submitFilters" />
                    <UiSelect name="branch_id" label="Cơ sở" placeholder="Tất cả cơ sở" :options="branches" @change="submitFilters" />
                </UiFilterBar>

                <!-- Table CVs -->
                <UiDataTable class="rounded-none border-0">
                    <table>
                        <thead>
                            <tr>
                                <th>Ứng viên</th>
                                <th>Vị trí &amp; Cơ sở</th>
                                <th>Hồ sơ CV / Portfolio</th>
                                <th>Trạng thái</th>
                                <th>Ghi chú tuyển dụng</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="can in candidates.data" :key="can.id">
                                <td>
                                    <span class="block text-sm font-bold text-on-surface">{{ can.full_name }}</span>
                                    <div class="mt-0.5 flex items-center gap-2 text-xs text-on-surface-variant">
                                        <span>{{ can.phone }}</span>
                                        <span>•</span>
                                        <span>{{ can.email }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="block font-semibold text-primary">{{ can.applying_position }}</span>
                                    <span class="text-xs text-on-surface-variant">{{ can.branch_name ?? 'Mọi chi nhánh' }}</span>
                                </td>
                                <td>
                                    <a v-if="can.cv_url" :href="can.cv_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-bold text-secondary hover:underline">
                                        <span class="material-symbols-outlined text-[16px]">attach_file</span>
                                        Xem file CV
                                    </a>
                                    <span v-else class="text-on-surface-subtle">Không đính kèm file</span>
                                    <a v-if="can.portfolio_url" :href="can.portfolio_url" target="_blank" rel="noopener noreferrer" class="mt-0.5 block text-xs font-medium text-secondary hover:underline">Link Video / Portfolio &rarr;</a>
                                </td>
                                <td>
                                    <UiBadge :color="cvStatusColor[can.status] ?? 'neutral'" pill>{{ can.status_label }}</UiBadge>
                                </td>
                                <td class="max-w-xs">
                                    <p class="line-clamp-2 whitespace-pre-line text-xs text-on-surface-variant">{{ can.notes || 'Chưa có ghi chú' }}</p>
                                </td>
                                <td class="text-right">
                                    <UiButton variant="secondary" size="sm" :aria-label="`Cập nhật hồ sơ ${can.full_name}`" @click="editingCv = can">Cập nhật</UiButton>
                                </td>
                            </tr>
                            <tr v-if="!candidates.data.length">
                                <td colspan="6"><UiEmptyState title="Chưa có hồ sơ ứng viên nào trong mục này." /></td>
                            </tr>
                        </tbody>
                    </table>
                    <template #footer>
                        <UiPagination :paginator="candidates" unit="hồ sơ" />
                    </template>
                </UiDataTable>
            </template>

            <!-- Table Jobs -->
            <UiDataTable v-if="tab === 'jobs'" class="rounded-none border-0">
                <table>
                    <thead>
                        <tr>
                            <th>Vị trí tuyển dụng</th>
                            <th>Bộ phận / Hình thức</th>
                            <th>Cơ sở / Địa điểm</th>
                            <th>Mức lương</th>
                            <th>Số CV nộp</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in jobs" :key="job.id">
                            <td class="text-sm font-bold">
                                {{ job.title }}
                                <span class="block text-xs font-normal text-on-surface-variant">Hạn nộp: {{ job.deadline ?? 'Không thời hạn' }}</span>
                            </td>
                            <td>
                                <span class="font-semibold">{{ job.department }}</span>
                                <span class="block text-xs text-on-surface-variant">{{ job.employment_type }}</span>
                            </td>
                            <td class="text-on-surface-variant">{{ job.branch_name ?? 'Toàn hệ thống' }}</td>
                            <td class="font-semibold text-tertiary">{{ job.salary_range || 'Thỏa thuận' }}</td>
                            <td>
                                <UiBadge color="secondary" pill :dot="false">{{ job.candidate_cvs_count }} CV</UiBadge>
                            </td>
                            <td>
                                <UiBadge v-if="job.is_active" color="success" pill>Đang mở nhận CV</UiBadge>
                                <UiBadge v-else color="neutral" pill>Đã đóng</UiBadge>
                            </td>
                            <td class="text-right">
                                <UiForm :action="route('recruitment.jobs.toggle', job.id)" method="post" class="inline">
                                    <UiButton type="submit" variant="secondary" size="sm">{{ job.is_active ? 'Đóng tin' : 'Mở lại' }}</UiButton>
                                </UiForm>
                            </td>
                        </tr>
                        <tr v-if="!jobs.length">
                            <td colspan="7"><UiEmptyState title="Chưa có tin tuyển dụng nào." /></td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </div>

        <!-- Cập nhật trạng thái hồ sơ ứng viên -->
        <UiModal :show="!!editingCv" :title="editingCv ? `Cập nhật hồ sơ · ${editingCv.full_name}` : ''" max-width="md" @close="editingCv = null">
            <UiForm v-if="editingCv" :id="`cv-status-form-${editingCv.id}`" :key="editingCv.id" :action="route('recruitment.cv.update-status', editingCv.id)" method="post" class="space-y-md" @success="editingCv = null">
                <UiSelect :id="`cv_status_${editingCv.id}`" name="status" label="Trạng thái mới" :value="editingCv.status" :options="cvStatusOptions" />
                <UiTextarea :id="`cv_notes_${editingCv.id}`" name="notes" label="Ghi chú" rows="3" placeholder="Ghi chú đánh giá, lịch hẹn PV..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="editingCv = null">Hủy</UiButton>
                <UiButton v-if="editingCv" type="submit" :form="`cv-status-form-${editingCv.id}`" icon="save">Lưu</UiButton>
            </template>
        </UiModal>

        <!-- Modal Đăng tin tuyển dụng -->
        <UiModal :show="showNewJob" title="Đăng tin tuyển dụng mới" max-width="xl" @close="showNewJob = false">
            <UiForm id="new-job-form" :action="route('recruitment.jobs.store')" method="post" class="space-y-3" reset-on-success @success="showNewJob = false">
                <UiInput name="title" label="Tiêu đề vị trí tuyển dụng" required placeholder="Ví dụ: Giáo viên Tiếng Anh Giao Tiếp Full-time" />

                <div class="grid grid-cols-2 gap-3">
                    <UiSelect name="department" label="Bộ phận / Khối" required :options="departments" />
                    <UiSelect name="employment_type" label="Hình thức làm việc" required :options="employmentTypes" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <UiSelect id="job_branch_id" name="branch_id" label="Cơ sở làm việc" placeholder="Toàn hệ thống" value="" :options="branches" />
                    <UiInput name="salary_range" label="Mức lương dự kiến" placeholder="Ví dụ: 12 - 18 triệu hoặc 300k/giờ" />
                </div>

                <UiTextarea name="description" label="Mô tả công việc" rows="3" required placeholder="Nêu chi tiết nhiệm vụ chính..." />

                <UiTextarea name="requirements" label="Yêu cầu ứng viên" rows="2" placeholder="IELTS 7.0+, phát âm chuẩn, nhiệt huyết..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="showNewJob = false">Hủy</UiButton>
                <UiButton type="submit" form="new-job-form">Đăng tin ngay</UiButton>
            </template>
        </UiModal>
    </div>
</template>
