<script setup>
/**
 * Quản lý đợt khảo sát chất lượng đào tạo: học vụ tạo khảo sát + hạn (hộp thoại "Tạo đợt khảo sát mới"), sửa / đóng / xóa;
 * học viên / phụ huynh thấy và nộp tại Cổng PH/HS (MH #6 Khảo sát 5 sao).
 */
import { ref } from 'vue';

defineOptions({ layout: { title: 'Đợt khảo sát' } });

defineProps({
    today: { type: String, required: true },
    surveys: { type: Array, default: () => [] },
});

const createOpen = ref(false);
const editing = ref(null);
const deleteMessage = (sv) => `Xóa khảo sát "${sv.title}"? Lịch sử đã nộp của học viên vẫn được giữ.`;
</script>

<template>
    <UiPageHeader title="Quản lý Đợt Khảo sát Chất lượng" icon="ballot">
        <template #actions>
            <UiButton icon="add_circle" @click="createOpen = true">Tạo đợt khảo sát</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-4">
        <!-- Danh sách -->
        <UiDataTable>
            <table>
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Hạn</th>
                        <th>Trạng thái</th>
                        <th>Người tạo</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sv in surveys" :key="sv.id" class="align-top">
                        <td class="max-w-md">
                            <p class="font-semibold text-on-surface">{{ sv.title }}</p>
                            <p v-if="sv.description" class="line-clamp-2 text-xs text-on-surface-variant">{{ sv.description }}</p>
                        </td>
                        <td class="whitespace-nowrap font-mono text-on-surface-variant">
                            {{ sv.deadline_label ?? '—' }}
                            <span v-if="sv.deadline_today" class="font-bold text-error">• Hôm nay</span>
                        </td>
                        <td>
                            <UiBadge :color="sv.is_active ? 'success' : 'neutral'" pill>{{ sv.is_active ? 'Đang mở' : 'Đã đóng' }}</UiBadge>
                        </td>
                        <td class="text-on-surface-variant">{{ sv.creator_name ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right">
                            <UiButton variant="ghost" size="sm" icon="edit" @click="editing = sv">Sửa</UiButton>
                            <UiForm :action="route('surveys.destroy', sv.id)" method="delete" class="inline" :confirm="deleteMessage(sv)" confirm-label="Xóa" danger>
                                <UiButton type="submit" variant="danger-text" size="sm">Xóa</UiButton>
                            </UiForm>
                        </td>
                    </tr>
                    <tr v-if="!surveys.length">
                        <td colspan="5"><UiEmptyState icon="ballot" title="Chưa có đợt khảo sát nào." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <!-- Sửa khảo sát -->
    <UiModal :show="!!editing" title="Sửa khảo sát" @close="editing = null">
        <UiForm v-if="editing" id="edit-survey-form" :key="editing.id" :action="route('surveys.update', editing.id)" method="put" class="space-y-md" @success="editing = null">
            <UiInput name="title" label="Tiêu đề khảo sát" required :value="editing.title" />
            <UiDate name="deadline" label="Hạn hoàn thành" :value="editing.deadline" />
            <UiTextarea name="description" label="Mô tả / câu hỏi hướng dẫn" :rows="3" :value="editing.description" />
            <label class="flex items-center gap-sm font-body-medium text-body-medium text-on-surface">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" :checked="editing.is_active" class="rounded border-outline-variant text-tertiary focus:ring-tertiary" />
                Đang mở
            </label>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
            <UiButton type="submit" form="edit-survey-form" icon="save">Lưu</UiButton>
        </template>
    </UiModal>

    <!-- Tạo khảo sát mới -->
    <UiModal :show="createOpen" title="Tạo đợt khảo sát mới" @close="createOpen = false">
        <UiForm id="new-survey-form" :action="route('surveys.store')" method="post" class="space-y-md" reset-on-success @success="createOpen = false">
            <UiInput name="title" label="Tiêu đề khảo sát" required placeholder="VD: Đánh giá chất lượng cơ sở vật chất tháng 10" />
            <UiDate name="deadline" label="Hạn hoàn thành" :min="today" />
            <UiTextarea name="description" label="Mô tả / câu hỏi hướng dẫn (tùy chọn)" :rows="3" placeholder="VD: Đánh giá phòng học, thiết bị, thái độ hỗ trợ của học vụ..." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="createOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="new-survey-form" icon="add">Tạo khảo sát</UiButton>
        </template>
    </UiModal>
</template>
