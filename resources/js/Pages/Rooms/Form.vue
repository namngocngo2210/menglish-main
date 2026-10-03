<script setup>
/**
 * Thêm / Sửa phòng học (modal; mở thẳng URL → trang đầy đủ). 5 ô: Tên phòng, Chi nhánh, Loại phòng, Sức chứa, Ghi chú.
 * Sửa: chi nhánh cố định (muốn chuyển chi nhánh thì tạo phòng mới); loại phòng đã ngừng dùng vẫn hiện "(ngừng dùng)".
 * Danh mục loại phòng chưa có loại nào đang dùng → khoá form, nhắc cấu hình danh mục trước.
 */
import { computed } from 'vue';

defineOptions({ layout: { title: 'Quản lý phòng học' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    room: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    lockedBranch: { type: Object, default: null },
    types: { type: Array, default: () => [] },
    canManageTypes: { type: Boolean, default: false },
});

const noTypes = computed(() => !props.types.length);
const fixedBranch = computed(() => (props.room ? props.room.branch : props.lockedBranch?.name ?? null));
</script>

<template>
    <UiModalFrame
        :title="room ? 'Sửa phòng học' : 'Thêm phòng mới'"
        :action="room ? route('rooms.update', room.id) : route('rooms.store')"
        :method="room ? 'put' : 'post'"
        :back="route('rooms.index')"
        cancel="Hủy"
        :submit-label="noTypes ? false : room ? 'Lưu thay đổi' : 'Lưu phòng'"
    >
        <UiAlert v-if="noTypes" type="warning" title="Chưa có loại phòng">
            Vui lòng cấu hình danh mục loại phòng trước khi tạo phòng mới.
            <UiButton v-if="canManageTypes" variant="ghost" size="sm" icon="arrow_forward" :href="route('rooms.types.index')" class="mt-xs">Chuyển sang tab Danh mục loại phòng</UiButton>
            <span v-else>Liên hệ Admin để thêm loại phòng.</span>
        </UiAlert>

        <UiInput id="room_name" name="name" label="Tên phòng" required maxlength="100" placeholder="VD: Phòng 202" hint="Tối đa 100 ký tự" :value="room?.name" :disabled="noTypes" />

        <template v-if="fixedBranch">
            <UiField label="Chi nhánh" :hint="room ? 'Muốn chuyển chi nhánh, vui lòng tạo phòng mới' : 'Chi nhánh trực thuộc của bạn'">
                <div class="flex items-center justify-between rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-base text-body-base text-on-surface">
                    {{ fixedBranch }}
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">lock</span>
                </div>
            </UiField>
            <input v-if="!room" type="hidden" name="branch_id" :value="lockedBranch.id" />
        </template>
        <UiSelect v-else id="room_branch" name="branch_id" label="Chi nhánh" required placeholder="-- Chọn chi nhánh cơ sở --" :options="branches" searchable :disabled="noTypes" />

        <UiSelect id="room_type" name="room_type_id" label="Loại phòng" required :placeholder="noTypes ? '(Danh mục đang trống)' : '-- Chọn loại phòng đang hoạt động --'" :options="types" :value="room?.room_type_id" :disabled="noTypes" />

        <UiInput id="room_capacity" type="number" name="capacity" label="Sức chứa (tùy chọn)" min="1" max="999" step="1" suffix="chỗ" placeholder="VD: 15" hint="Số nguyên lớn hơn hoặc bằng 1" :value="room?.capacity" :disabled="noTypes" />

        <UiTextarea id="room_description" name="description" label="Ghi chú mô tả phòng" :rows="3" placeholder="VD: Ngồi đất, thảm xốp, ghế nhựa mini, phù hợp lứa tuổi mẫu giáo..." :value="room?.description" :disabled="noTypes" />
    </UiModalFrame>
</template>
