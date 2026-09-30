<script setup>
/** Cơ sở & chi nhánh: danh sách + số liệu; Thêm / Sửa trong hộp thoại ngay trên trang, bấm trạng thái để Bật / Tắt, Xóa có hộp xác nhận. */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Cơ sở & chi nhánh' } });

defineProps({
    branches: { type: Array, required: true },
    stats: { type: Object, required: true },
});

const page = usePage();
const createOpen = ref(false);
const editData = ref(null);
const statuses = [
    { value: '1', label: 'Đang hoạt động' },
    { value: '0', label: 'Tạm dừng' },
];
const errorMessage = computed(() => (page.props.flash ?? []).find((f) => f.type === 'error')?.message ?? Object.values(page.props.errors ?? {})[0] ?? null);

function openEdit(branch) {
    editData.value = { ...branch };
}
</script>

<template>
    <UiPageHeader title="Cơ sở & chi nhánh" icon="apartment">
        <template #breadcrumbs>
            <Link :href="route('dashboard')" class="flex items-center gap-1 transition hover:text-on-surface">
                <span class="material-symbols-outlined text-[16px]">home</span>
                <span>Trang chủ</span>
            </Link>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="font-semibold text-primary-container">Cơ sở &amp; Chi nhánh</span>
        </template>
        <template #actions>
            <UiButton icon="add_business" @click="createOpen = true">Thêm Chi Nhánh Mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <UiAlert v-if="errorMessage" type="error">{{ errorMessage }}</UiAlert>

        <!-- Số liệu nhanh -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <UiStatCard label="Tổng chi nhánh" :value="stats.total" tone="secondary" icon="apartment" />
            <UiStatCard label="Đang hoạt động" :value="stats.active" tone="success" icon="check_circle" />
            <UiStatCard label="Tổng học viên" :value="stats.students" tone="primary" icon="school" />
            <UiStatCard label="Lớp đang mở" :value="stats.classes" icon="meeting_room" />
        </div>

        <!-- Bộ lọc & tìm kiếm -->
        <UiFilterBar :action="route('branches.index')" :reset-url="route('branches.index')" placeholder="Tìm tên, mã, địa chỉ, số hotline..." class="!mb-0">
            <UiSelect name="status" :options="statuses" placeholder="Tất cả trạng thái" label="Trạng thái" @change="$event.target.form?.requestSubmit()" />
            <div class="self-center font-body-small text-body-small text-on-surface-variant">
                Hiển thị: <strong>{{ branches.length }}</strong> cơ sở chi nhánh
            </div>
        </UiFilterBar>

        <!-- Danh sách chi nhánh -->
        <UiDataTable class="shadow-sm">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="w-16 text-center">STT</th>
                        <th class="w-28">Mã cơ sở</th>
                        <th>Tên Chi Nhánh &amp; Địa Chỉ</th>
                        <th class="w-36">Hotline liên hệ</th>
                        <th class="w-36 text-center">Quy mô hoạt động</th>
                        <th class="w-32 text-center">Trạng thái</th>
                        <th class="w-24 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(branch, idx) in branches" :key="branch.id">
                        <td class="text-center font-mono font-bold text-on-surface-subtle">{{ idx + 1 }}</td>
                        <td class="font-mono font-bold text-secondary">
                            <span class="rounded-lg border border-secondary/30 bg-secondary/10 px-2.5 py-1">{{ branch.code }}</span>
                        </td>
                        <td>
                            <div class="mb-0.5 text-sm font-bold text-on-surface">{{ branch.name }}</div>
                            <div class="flex items-center gap-1 text-xs text-on-surface-variant">
                                <span class="material-symbols-outlined text-[13px] text-on-surface-subtle">location_on</span>
                                <span>{{ branch.address }}</span>
                            </div>
                        </td>
                        <td class="font-mono font-medium text-on-surface-variant">{{ branch.phone || 'Chưa cập nhật' }}</td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-2 font-mono text-xs">
                                <UiBadge color="secondary" :dot="false" title="Số lớp học">{{ branch.classes_count }} Lớp</UiBadge>
                                <UiBadge color="success" :dot="false" title="Số học viên">{{ branch.students_count }} HV</UiBadge>
                                <UiBadge color="primary" :dot="false" title="Nhân sự phụ trách">{{ branch.users_count }} NS</UiBadge>
                            </div>
                        </td>
                        <td class="text-center">
                            <UiForm :action="route('branches.toggle', branch.id)" method="post" class="inline">
                                <button type="submit" class="cursor-pointer" title="Click để Bật / Tắt hoạt động">
                                    <UiBadge v-if="branch.is_active" color="success" pill class="uppercase">Hoạt động</UiBadge>
                                    <UiBadge v-else color="neutral" pill class="uppercase">Tạm dừng</UiBadge>
                                </button>
                            </UiForm>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-1">
                                <UiButton variant="ghost" size="sm" icon="edit" title="Chỉnh sửa" aria-label="Chỉnh sửa" @click="openEdit(branch)" />
                                <UiForm :action="route('branches.destroy', branch.id)" method="delete" class="inline" :confirm="`Bạn có chắc chắn muốn xóa chi nhánh ${branch.name}?`" confirm-label="Xóa" danger>
                                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" aria-label="Xóa" />
                                </UiForm>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!branches.length">
                        <td colspan="7"><UiEmptyState icon="apartment" title="Không tìm thấy cơ sở chi nhánh nào phù hợp." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <!-- Thêm chi nhánh mới -->
        <UiModal :show="createOpen" title="Thêm Cơ Sở Chi Nhánh Mới" max-width="md" @close="createOpen = false">
            <UiForm id="createBranchForm" :action="route('branches.store')" method="post" class="space-y-3" reset-on-success @success="createOpen = false">
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-1">
                        <UiInput name="code" id="create_code" label="Mã chi nhánh" required placeholder="VD: CG" class="font-mono font-bold uppercase" />
                    </div>
                    <div class="col-span-2">
                        <UiInput name="name" id="create_name" label="Tên chi nhánh" required placeholder="VD: Chi nhánh Cầu Giấy" />
                    </div>
                </div>
                <UiInput name="address" id="create_address" label="Địa chỉ chi nhánh" required placeholder="Số nhà, Đường, Quận, Thành phố..." />
                <UiInput name="phone" id="create_phone" label="Số điện thoại Hotline" placeholder="0243 555 0101" class="font-mono" />
                <div class="flex items-center gap-2 pt-1">
                    <input id="is_active_create" type="checkbox" name="is_active" value="1" checked class="h-4 w-4 cursor-pointer rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                    <label for="is_active_create" class="cursor-pointer text-xs font-semibold text-on-surface-variant">Kích hoạt chi nhánh ngay sau khi tạo</label>
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="createOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="createBranchForm">Tạo Chi Nhánh</UiButton>
            </template>
        </UiModal>

        <!-- Sửa chi nhánh -->
        <UiModal :show="!!editData" title="Chỉnh Sửa Cơ Sở Chi Nhánh" max-width="md" @close="editData = null">
            <UiForm v-if="editData" id="editBranchForm" :key="editData.id" :action="route('branches.update', editData.id)" method="put" class="space-y-3" @success="editData = null">
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-1">
                        <UiInput v-model="editData.code" name="code" id="edit_code" label="Mã chi nhánh" required class="font-mono font-bold uppercase" />
                    </div>
                    <div class="col-span-2">
                        <UiInput v-model="editData.name" name="name" id="edit_name" label="Tên chi nhánh" required />
                    </div>
                </div>
                <UiInput v-model="editData.address" name="address" id="edit_address" label="Địa chỉ chi nhánh" required />
                <UiInput v-model="editData.phone" name="phone" id="edit_phone" label="Số điện thoại Hotline" class="font-mono" />
                <div class="flex items-center gap-2 pt-1">
                    <input id="is_active_edit" type="checkbox" name="is_active" value="1" :checked="editData.is_active" class="h-4 w-4 cursor-pointer rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                    <label for="is_active_edit" class="cursor-pointer text-xs font-semibold text-on-surface-variant">Trạng thái: Đang hoạt động</label>
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="editData = null">Hủy</UiButton>
                <UiButton type="submit" form="editBranchForm">Lưu Thay Đổi</UiButton>
            </template>
        </UiModal>
    </div>
</template>
