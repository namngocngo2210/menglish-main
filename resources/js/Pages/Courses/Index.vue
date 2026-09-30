<script setup>
/**
 * Quản lý Khóa học & Bảng giá học phí: thống kê, lọc, bảng giá; Thêm / Sửa giá mở hộp thoại ngay trên trang,
 * bấm nhãn trạng thái để bật / tắt mở bán, Xóa khi khóa chưa có lớp.
 */
import { ref } from 'vue';

defineOptions({ layout: { title: 'Quản lý Khóa học & Bảng giá học phí' } });

defineProps({
    courses: { type: Object, required: true },
    levels: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
});

const creating = ref(false);
const editing = ref(null);
</script>

<template>
    <UiPageHeader title="Quản lý Khóa học & Bảng giá học phí" icon="price_change" description="Cấu hình giá niêm yết, số buổi học và liên kết khung trình độ chuẩn CEFR/IELTS">
        <template #actions>
            <UiButton icon="add_circle" @click="creating = true">Thêm khóa học &amp; Giá mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-5">
        <!-- 1. Thẻ số liệu -->
        <div class="grid grid-cols-2 gap-3.5 md:grid-cols-4">
            <UiStatCard label="Tổng số khóa học" :value="formatNumber(stats.total_courses)" tone="primary" icon="school" />
            <UiStatCard label="Đang mở tuyển sinh" :value="formatNumber(stats.active_courses)" tone="success" icon="check_circle" />
            <UiStatCard label="Học phí trung bình" :value="formatMoney(stats.avg_tuition)" tone="secondary" icon="payments" />
            <UiStatCard label="Mức giá cao nhất" :value="formatMoney(stats.max_tuition)" tone="secondary" icon="workspace_premium" />
        </div>

        <!-- 2. Bộ lọc -->
        <UiFilterBar :action="route('courses.index')" placeholder="Nhập tên khóa học, mã code (IE-65, GT-B1)..." class="!mb-0">
            <UiSelect name="course_level_id" label="Khung trình độ" placeholder="Tất cả trình độ" :options="levels" />
            <UiSelect name="status" label="Trạng thái mở bán" placeholder="Tất cả trạng thái" :options="[{ value: 'active', label: 'Đang mở bán' }, { value: 'inactive', label: 'Tạm ngưng' }]" />
        </UiFilterBar>

        <!-- 3. Bảng giá -->
        <UiDataTable min-width="1040px" sticky="both">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="min-w-[90px]">Mã khóa</th>
                        <th class="min-w-[220px]">Tên khóa học &amp; Mục tiêu</th>
                        <th class="min-w-[220px]">Khung trình độ</th>
                        <th class="min-w-[150px] text-right">Giá học phí niêm yết</th>
                        <th class="min-w-[90px] text-center">Thời lượng</th>
                        <th class="min-w-[100px] text-center">Lớp đang chạy</th>
                        <th class="min-w-[120px] text-center">Trạng thái</th>
                        <th class="min-w-[130px] text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in courses.data" :key="c.id" class="group">
                        <td class="whitespace-nowrap font-mono font-bold">
                            <span class="inline-block rounded-lg border border-surface-container-highest/70 bg-surface-container px-2 py-1 text-on-surface">{{ c.code }}</span>
                        </td>
                        <td>
                            <div class="text-xs font-bold text-on-surface">{{ c.name }}</div>
                            <div v-if="c.description" class="mt-0.5 line-clamp-1 max-w-sm text-xs text-on-surface-variant">{{ c.description }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <div v-if="c.level" class="whitespace-nowrap">
                                <div class="flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="shrink-0 rounded-md border border-secondary/20 bg-secondary/10 px-2 py-0.5 font-mono text-xs font-bold text-secondary">{{ c.level.code }}</span>
                                    <span class="whitespace-nowrap text-xs font-semibold text-on-surface">{{ c.level.name }}</span>
                                </div>
                                <div v-if="c.level.target" class="mt-0.5 flex items-center gap-1 whitespace-nowrap text-xs font-medium text-on-surface-subtle">
                                    <span class="material-symbols-outlined text-[13px] text-on-surface-subtle">flag</span>
                                    <span>{{ c.level.target }}</span>
                                </div>
                            </div>
                            <span v-else class="whitespace-nowrap text-xs italic text-on-surface-subtle">Chưa gắn level</span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="font-mono text-sm font-extrabold text-primary">{{ formatMoney(c.tuition_fee) }}</div>
                            <span class="block text-xs text-on-surface-subtle">Giá trọn gói</span>
                        </td>
                        <td class="whitespace-nowrap text-center">
                            <span class="font-mono font-bold text-on-surface">{{ c.total_lessons }}</span>
                            <span class="block text-xs text-on-surface-subtle">buổi học</span>
                        </td>
                        <td class="whitespace-nowrap text-center font-mono font-semibold text-on-surface-variant">
                            <UiBadge :dot="false" pill>{{ c.classes_count }} lớp</UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-center">
                            <UiForm :action="route('courses.toggle', c.id)" method="patch" class="inline-block">
                                <button
                                    type="submit"
                                    :class="[
                                        'cursor-pointer rounded-full px-2.5 py-1 text-xs font-bold transition',
                                        c.is_active ? 'border border-tertiary/30 bg-tertiary/10 text-tertiary hover:bg-tertiary/20' : 'border border-surface-container-highest bg-surface-container text-on-surface-variant hover:bg-surface-container-high',
                                    ]"
                                    title="Bấm để chuyển trạng thái mở bán"
                                >
                                    <span class="inline-flex items-center gap-xs"><span :class="['h-2 w-2 rounded-full', c.is_active ? 'bg-tertiary' : 'bg-outline']" aria-hidden="true"></span>{{ c.is_active ? 'Đang mở bán' : 'Tạm ngưng' }}</span>
                                </button>
                            </UiForm>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                <UiButton variant="secondary" size="sm" icon="edit" title="Chỉnh sửa giá học phí & thông tin khóa học" @click="editing = c">Sửa giá</UiButton>
                                <UiForm v-if="c.classes_count === 0" :action="route('courses.destroy', c.id)" method="delete" :confirm="`Xóa khóa học ${c.name}?`" confirm-label="Xóa" danger>
                                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" />
                                </UiForm>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!courses.data.length">
                        <td colspan="8"><UiEmptyState icon="school" title="Chưa có khóa học nào khớp với điều kiện tìm kiếm." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="courses" /></template>
        </UiDataTable>

        <!-- 4. Thêm khóa học -->
        <UiModal :show="creating" title="Thêm Khóa Học & Thiết Lập Giá" max-width="lg" @close="creating = false">
            <UiForm id="createCourseForm" :action="route('courses.store')" method="post" class="space-y-4 text-xs" reset-on-success @success="creating = false">
                <div class="grid grid-cols-2 gap-3">
                    <UiInput id="create_code" label="Mã khóa học" value="Tự sinh khi lưu" disabled hint="Hệ thống cấp mã dạng CS0001" class="font-mono text-xs" />
                    <UiInput id="create_total_lessons" type="number" name="total_lessons" label="Số buổi học" required value="24" min="1" class="font-mono text-xs" />
                </div>

                <UiInput id="create_name" name="name" label="Tên khóa học" required placeholder="Ví dụ: IELTS 6.5 Intensive, Giao tiếp Pro B1" class="text-xs font-bold" />

                <div class="rounded-2xl border border-primary-container/25 bg-primary-container/5 p-3.5">
                    <label for="create_tuition_fee" class="mb-1 flex items-center justify-between font-bold text-primary">
                        <span>Giá học phí niêm yết (VNĐ) <span class="text-error">*</span></span>
                        <span class="text-xs font-normal text-primary">Học phí trọn gói</span>
                    </label>
                    <UiInput id="create_tuition_fee" type="number" name="tuition_fee" placeholder="12500000" min="0" step="10000" required suffix="VNĐ" class="font-mono text-sm font-black text-primary" />
                </div>

                <UiSelect id="create_course_level_id" name="course_level_id" label="Khung trình độ trực thuộc" class="text-xs" placeholder="-- Chọn khung trình độ (CEFR/IELTS) --" value="" :options="levels" />

                <UiTextarea id="create_description" name="description" label="Mô tả & Cam kết đầu ra" :rows="2" class="text-xs" placeholder="Cam kết band điểm, tài liệu độc quyền..." />

                <div class="flex items-center gap-2 pt-1">
                    <input id="create_is_active" type="checkbox" name="is_active" value="1" checked class="h-4 w-4 cursor-pointer rounded border-outline-variant text-primary focus:ring-primary-container" />
                    <label for="create_is_active" class="cursor-pointer font-bold text-on-surface-variant">Kích hoạt mở bán ngay sau khi tạo</label>
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
                <UiButton type="submit" form="createCourseForm">Lưu Khóa Học</UiButton>
            </template>
        </UiModal>

        <!-- 5. Sửa giá & thông tin khóa học -->
        <UiModal :show="!!editing" title="Chỉnh Sửa Giá & Thông Tin Khóa Học" max-width="lg" @close="editing = null">
            <UiForm v-if="editing" :key="editing.id" id="editCourseForm" :action="route('courses.update', editing.id)" method="put" class="space-y-4 text-xs" @success="editing = null">
                <div class="grid grid-cols-2 gap-3">
                    <UiInput id="edit_code" label="Mã khóa học" :value="editing.code || ''" disabled hint="Mã do hệ thống cấp, không sửa được" class="font-mono text-xs font-bold" />
                    <UiInput id="edit_total_lessons" type="number" name="total_lessons" label="Số buổi học" required :value="editing.total_lessons || 24" min="1" class="font-mono text-xs" />
                </div>

                <UiInput id="edit_name" name="name" label="Tên khóa học" required :value="editing.name || ''" class="text-xs font-bold" />

                <div class="rounded-2xl border border-primary-container/30 bg-primary-container/10 p-3.5">
                    <label for="edit_tuition_fee" class="mb-1 flex items-center justify-between font-bold text-primary">
                        <span>Giá học phí niêm yết (VNĐ) <span class="text-error">*</span></span>
                        <span class="text-xs font-normal text-primary">Chỉnh sửa giá mới</span>
                    </label>
                    <UiInput id="edit_tuition_fee" type="number" name="tuition_fee" min="0" step="10000" required suffix="VNĐ" :value="Math.round(editing.tuition_fee) || 0" class="font-mono text-base font-black text-primary" />
                </div>

                <UiSelect id="edit_course_level_id" name="course_level_id" label="Khung trình độ trực thuộc" class="text-xs" placeholder="-- Chưa gắn khung trình độ --" :value="editing.course_level_id ?? ''" :options="levels" />

                <UiTextarea id="edit_description" name="description" label="Mô tả & Mục tiêu" :rows="2" class="text-xs" :value="editing.description || ''" />

                <div class="flex items-center gap-2 pt-1">
                    <input id="edit_is_active" type="checkbox" name="is_active" value="1" :checked="editing.is_active" class="h-4 w-4 cursor-pointer rounded border-outline-variant text-primary focus:ring-primary-container" />
                    <label for="edit_is_active" class="cursor-pointer font-bold text-on-surface-variant">Đang mở bán khóa học này</label>
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
                <UiButton type="submit" form="editCourseForm">Cập Nhật Học Phí</UiButton>
            </template>
        </UiModal>
    </div>
</template>
