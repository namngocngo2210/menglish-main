<script setup>
/**
 * Quản lý phòng học — tab Danh sách phòng: lọc chi nhánh / loại phòng, khung giờ lớp đang dùng phòng.
 * Thêm / Sửa mở modal (Form.vue). Xóa (Admin) hỏi theo 3 trường hợp: phòng trống · chỉ gán lớp chưa bắt đầu
 * (xóa = bỏ liên kết phòng khỏi lớp) · đang có lớp học (chặn).
 */
import { computed, ref } from 'vue';
import BranchBlocked from './BranchBlocked.vue';
import RoomTabs from './RoomTabs.vue';

defineOptions({ layout: { title: 'Quản lý phòng học' } });

defineProps({
    blocked: { type: Boolean, default: false },
    rooms: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    lockedBranch: { type: Object, default: null },
    types: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const deleting = ref(null);
const studyingClasses = computed(() => (deleting.value?.classes ?? []).filter((c) => c.studying));
const pendingClasses = computed(() => (deleting.value?.classes ?? []).filter((c) => !c.studying));
const deleteCase = computed(() => (studyingClasses.value.length ? 'blocked' : pendingClasses.value.length ? 'pending' : 'free'));
const names = (list) => list.map((c) => c.name).join(', ');
</script>

<template>
    <RoomTabs :counts="counts">
        <template v-if="canManage && !blocked" #actions>
            <UiButton icon="add" :href="route('rooms.create')" modal="md">Thêm phòng</UiButton>
        </template>
    </RoomTabs>

    <BranchBlocked v-if="blocked" />

    <template v-else>
        <UiFilterBar :search="false">
            <div v-if="lockedBranch" class="flex items-center gap-xs rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-medium text-body-medium text-on-surface" title="Chi nhánh trực thuộc (cố định)">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">lock</span>
                {{ lockedBranch.name }}
                <span class="font-body-small text-body-small text-on-surface-variant">(Chi nhánh trực thuộc)</span>
            </div>
            <UiSelect v-else name="branch_id" label="Chi nhánh" :options="branches" placeholder="Tất cả chi nhánh" />
            <UiSelect name="room_type_id" label="Loại phòng" :options="types" placeholder="Tất cả loại phòng" />
        </UiFilterBar>

        <UiDataTable min-width="880px">
            <table>
                <thead>
                    <tr>
                        <th>Tên phòng</th>
                        <th>Chi nhánh</th>
                        <th>Loại phòng</th>
                        <th>Sức chứa</th>
                        <th>Khung giờ đang có lớp</th>
                        <th v-if="canManage || canDelete" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="room in rooms.data" :key="room.id">
                        <td class="whitespace-nowrap">
                            <span class="inline-flex items-center gap-xs font-semibold text-on-surface">
                                <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">meeting_room</span>
                                {{ room.name }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap">{{ room.branch }}</td>
                        <td>
                            <UiBadge v-if="room.type_active" color="secondary" :dot="false">{{ room.type }}</UiBadge>
                            <UiBadge v-else :dot="false" title="Loại phòng đã ngừng dùng"><span class="line-through">{{ room.type }}</span>&nbsp;(ngừng dùng)</UiBadge>
                        </td>
                        <td class="whitespace-nowrap">
                            <span v-if="room.capacity" class="font-code">{{ room.capacity }} chỗ</span>
                            <span v-else class="italic text-on-surface-variant">Chưa đặt</span>
                        </td>
                        <td>
                            <ul v-if="room.classes.length" class="space-y-xs">
                                <template v-for="c in room.classes" :key="c.id">
                                    <li v-for="slot in c.slots" :key="`${c.id}-${slot}`">
                                        <UiBadge :color="c.studying ? 'success' : 'warning'" :dot="false">
                                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ c.studying ? 'schedule' : 'hourglass_top' }}</span>
                                            {{ slot }} · Lớp {{ c.name }}<template v-if="!c.studying"> (chưa bắt đầu học)</template>
                                        </UiBadge>
                                    </li>
                                    <li v-if="!c.slots.length" class="font-body-small text-body-small italic text-on-surface-variant">Lớp {{ c.name }} — chưa có lịch</li>
                                </template>
                            </ul>
                            <span v-else class="font-body-small text-body-small text-on-surface-variant">Chưa có lớp nào gắn phòng</span>
                        </td>
                        <td v-if="canManage || canDelete" class="whitespace-nowrap text-right">
                            <div class="inline-flex items-center gap-xs">
                                <UiButton v-if="canManage" variant="ghost" size="sm" icon="edit" :href="route('rooms.edit', room.id)" modal="md" :aria-label="`Sửa ${room.name}`">Sửa</UiButton>
                                <UiButton v-if="canDelete" variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa ${room.name}`" @click="deleting = room" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rooms.data.length">
                        <td colspan="6">
                            <UiEmptyState icon="meeting_room" title="Chưa có phòng học nào" :description="canManage ? 'Bấm “Thêm phòng” để tạo phòng cho chi nhánh.' : 'Chưa có phòng học trong phạm vi bạn được xem.'" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="rooms" unit="phòng học" /></template>
        </UiDataTable>
    </template>

    <!-- Xác nhận xóa phòng: 3 trường hợp -->
    <UiModal :show="!!deleting" :title="deleteCase === 'blocked' ? 'Không thể xóa phòng học' : deleteCase === 'pending' ? 'Xóa phòng học đang phân bổ' : 'Xóa phòng học'" max-width="md" @close="deleting = null">
        <template v-if="deleting">
            <p v-if="deleteCase === 'blocked'" data-testid="room-delete-blocked">
                Không thể xóa: <strong>{{ deleting.name }}</strong> đang được lớp <strong>{{ names(studyingClasses) }}</strong> sử dụng.
            </p>
            <p v-else-if="deleteCase === 'pending'">
                <strong>{{ deleting.name }}</strong> đang được gán cho lớp <strong>{{ names(pendingClasses) }}</strong> (chưa bắt đầu học). Xóa phòng sẽ bỏ liên kết phòng khỏi các lớp này.
            </p>
            <p v-else>Xóa <strong>{{ deleting.name }}</strong>? Thao tác không hoàn tác được.</p>
        </template>
        <template #footer>
            <UiButton variant="secondary" @click="deleting = null">{{ deleteCase === 'blocked' ? 'Đóng' : 'Hủy' }}</UiButton>
            <UiForm v-if="deleting && deleteCase !== 'blocked'" :action="route('rooms.destroy', deleting.id)" method="delete" back @success="deleting = null">
                <UiButton type="submit" variant="danger" icon="delete">Xóa</UiButton>
            </UiForm>
        </template>
    </UiModal>
</template>
