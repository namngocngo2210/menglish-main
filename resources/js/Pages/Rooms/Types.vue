<script setup>
/**
 * Quản lý phòng học — tab Danh mục loại phòng (dùng chung toàn hệ thống). Thêm / sửa ngay trên dòng;
 * Ngừng dùng ↔ Dùng lại; Xóa: loại đang có phòng dùng thì chặn (chỉ Ngừng dùng được).
 */
import { ref } from 'vue';
import { toast } from '@/lib/toast';
import RoomTabs from './RoomTabs.vue';

defineOptions({ layout: { title: 'Quản lý phòng học' } });

defineProps({
    roomTypes: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    canManageTypes: { type: Boolean, default: false },
});

const adding = ref(false);
const editingId = ref(null);
const blockDelete = () => toast('Loại phòng đang được sử dụng — chỉ có thể Ngừng dùng', 'error');
</script>

<template>
    <RoomTabs :counts="counts" />

    <UiDataTable min-width="560px">
        <template #header>
            <div>
                <h2 class="font-h3 text-h3 text-on-surface">Danh mục loại phòng học</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Quy chuẩn dùng chung toàn hệ thống cho tất cả các cơ sở trực thuộc.</p>
            </div>
            <UiButton v-if="canManageTypes && !adding" icon="add" @click="adding = true">Thêm loại phòng</UiButton>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Tên loại phòng</th>
                    <th>Trạng thái</th>
                    <th v-if="canManageTypes" class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="adding" class="bg-primary-container/5">
                    <td colspan="3">
                        <UiForm :action="route('rooms.types.store')" method="post" back class="flex flex-col gap-sm sm:flex-row sm:items-start" @success="adding = false">
                            <UiInput name="name" placeholder="Nhập tên loại phòng (VD: Phòng Mẫu giáo)" maxlength="100" aria-label="Tên loại phòng mới" class="sm:flex-1" autofocus />
                            <span class="self-center whitespace-nowrap font-body-small text-body-small text-on-surface-variant">Mặc định: Đang dùng</span>
                            <div class="flex gap-xs">
                                <UiButton variant="secondary" @click="adding = false">Hủy</UiButton>
                                <UiButton type="submit">Lưu</UiButton>
                            </div>
                        </UiForm>
                    </td>
                </tr>
                <tr v-for="t in roomTypes" :key="t.id">
                    <template v-if="editingId === t.id">
                        <td colspan="3">
                            <UiForm :action="route('rooms.types.update', t.id)" method="put" back class="flex flex-col gap-sm sm:flex-row sm:items-start" @success="editingId = null">
                                <UiInput name="name" :value="t.name" maxlength="100" :aria-label="`Tên loại phòng ${t.name}`" class="sm:flex-1" autofocus />
                                <div class="flex gap-xs">
                                    <UiButton variant="secondary" @click="editingId = null">Hủy</UiButton>
                                    <UiButton type="submit">Lưu</UiButton>
                                </div>
                            </UiForm>
                        </td>
                    </template>
                    <template v-else>
                        <td :class="['font-semibold', t.is_active ? 'text-on-surface' : 'text-on-surface-variant']">{{ t.name }}</td>
                        <td>
                            <UiBadge :color="t.is_active ? 'success' : 'neutral'">{{ t.is_active ? 'Đang dùng' : 'Ngừng dùng' }}</UiBadge>
                        </td>
                        <td v-if="canManageTypes" class="whitespace-nowrap text-right">
                            <div class="inline-flex items-center gap-xs">
                                <UiButton variant="ghost" size="sm" icon="edit" @click="editingId = t.id">Sửa</UiButton>
                                <UiForm :action="route('rooms.types.toggle', t.id)" method="post" back>
                                    <UiButton type="submit" variant="ghost" size="sm" :icon="t.is_active ? 'block' : 'restart_alt'">{{ t.is_active ? 'Ngừng dùng' : 'Dùng lại' }}</UiButton>
                                </UiForm>
                                <UiButton v-if="t.in_use" variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa ${t.name}`" @click="blockDelete" />
                                <UiForm v-else :action="route('rooms.types.destroy', t.id)" method="delete" back :confirm="`Xóa loại phòng ${t.name}? Thao tác này sẽ xóa loại phòng khỏi hệ thống.`" confirm-title="Xóa loại phòng" confirm-label="Xóa" danger>
                                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa ${t.name}`" />
                                </UiForm>
                            </div>
                        </td>
                    </template>
                </tr>
                <tr v-if="!roomTypes.length && !adding">
                    <td colspan="3">
                        <UiEmptyState icon="category" title="Chưa có loại phòng nào" :description="canManageTypes ? 'Bấm Thêm loại phòng để bắt đầu.' : 'Liên hệ Admin để thêm loại phòng.'">
                            <UiButton v-if="canManageTypes" icon="add" @click="adding = true">Thêm loại phòng mới</UiButton>
                        </UiEmptyState>
                    </td>
                </tr>
            </tbody>
        </table>
    </UiDataTable>
</template>
