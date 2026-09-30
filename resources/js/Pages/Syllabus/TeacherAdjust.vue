<script setup>
/**
 * Mockup 03_Cong_Giao_Vien/14: Danh sách yêu cầu đã gửi; form Gửi yêu cầu (lớp/chặng đang mở, lý do, số buổi 1/2)
 * mở bằng nút "Gửi yêu cầu" (hộp thoại).
 */
import { ref } from 'vue';

defineOptions({ layout: { title: 'Xin điều chỉnh tiến độ' } });

defineProps({
    requests: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    slaHours: { type: Number, default: 24 },
    canReview: { type: Boolean, default: false },
});

const statusColor = { pending: 'warning', approved: 'success', rejected: 'error' };
const statusLabel = { pending: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối' };

const requesting = ref(false);
const sessions = ref('');
function sent() {
    requesting.value = false;
    sessions.value = '';
}
</script>

<template>
    <UiPageHeader title="Xin điều chỉnh tiến độ" description="Gửi yêu cầu điều chỉnh thời gian cho các lớp hoặc chặng học hiện tại." :back="route('syllabus.versions')">
        <template #actions>
            <UiButton v-if="canReview" variant="secondary" icon="rule" :href="route('syllabus.adjustment-requests')">Duyệt yêu cầu tiến độ</UiButton>
            <UiButton icon="send" @click="requesting = true">Gửi yêu cầu</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="flex min-w-0 flex-col gap-4 lg:col-span-12">
            <UiDataTable min-width="640px">
                <template #header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Danh sách yêu cầu đã gửi</h2>
                        <UiBadge>{{ requests.total }} yêu cầu</UiBadge>
                    </div>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Ngày gửi</th>
                            <th scope="col">Lớp / Chặng</th>
                            <th scope="col">Lý do</th>
                            <th scope="col" class="text-center">Số buổi thêm</th>
                            <th scope="col">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="req in requests.data" :key="req.id">
                            <td class="whitespace-nowrap font-mono text-on-surface-variant">{{ req.created_at }}</td>
                            <td class="font-body-medium text-body-small text-on-surface">{{ req.class_stage_label }}</td>
                            <td class="max-w-[220px]">
                                <p class="truncate" :title="req.reason">{{ req.reason }}</p>
                                <p v-if="req.status === 'approved' && req.applied_note" class="whitespace-normal font-caption text-caption text-tertiary">{{ req.applied_note }}</p>
                            </td>
                            <td class="text-center font-semibold">{{ req.extra_sessions || 0 }}</td>
                            <td class="whitespace-nowrap">
                                <UiBadge :color="statusColor[req.status] ?? 'neutral'">{{ statusLabel[req.status] ?? req.status_label }}</UiBadge>
                                <p v-if="req.status === 'rejected' && req.rejection_reason" class="mt-1 max-w-[200px] whitespace-normal font-caption text-caption text-error">{{ req.rejection_reason }}</p>
                            </td>
                        </tr>
                        <tr v-if="!requests.data.length">
                            <td colspan="5"><UiEmptyState icon="speed" title="Bạn chưa gửi yêu cầu xin điều chỉnh tiến độ nào" /></td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="requests" unit="yêu cầu" /></template>
            </UiDataTable>
        </div>
    </div>

    <UiModal :show="requesting" title="Gửi yêu cầu điều chỉnh tiến độ" max-width="lg" @close="requesting = false">
        <div v-if="!classes.length" class="flex flex-col items-center gap-sm py-md text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-surface-container-high text-on-surface-variant"><span class="material-symbols-outlined text-[28px]">info</span></span>
            <h3 class="font-h3 text-h3 text-on-surface">Không có chặng học nào đang mở</h3>
            <p class="font-body-small text-body-small text-on-surface-variant">Bạn hiện không có chặng học nào đang mở để gửi yêu cầu.</p>
        </div>
        <UiForm v-else id="new-adjustment-form" :action="route('syllabus.adjustment-requests.store')" method="post" class="space-y-md" reset-on-success @success="sent">
            <UiSelect label="Lớp học / Chặng học" name="class_id" value="" required>
                <option disabled value="">Chọn lớp/chặng cần xin giãn</option>
                <option v-for="cl in classes" :key="cl.value" :value="cl.value">{{ cl.label }}</option>
            </UiSelect>

            <UiTextarea name="reason" label="Lý do xin điều chỉnh" required rows="4" placeholder="Vui lòng ghi rõ lý do (VD: Học sinh chưa nắm vững kiến thức, cháy giáo án do mất điện...)" />

            <UiField label="Số buổi cần thêm" name="extra_sessions" required hint="Khi được duyệt, hệ thống thêm đúng số buổi này vào cuối lịch học của lớp (theo TKB, bỏ qua ngày nghỉ).">
                <div class="grid grid-cols-2 gap-md">
                    <label
                        v-for="n in ['1', '2']"
                        :key="n"
                        :class="['relative flex cursor-pointer items-center justify-center rounded-lg border px-md py-md font-body-medium text-body-medium transition-all', sessions === n ? 'border-primary-container bg-surface-container-low ring-1 ring-primary-container' : 'border-outline-variant bg-surface-container-lowest']"
                    >
                        <input v-model="sessions" type="radio" name="extra_sessions" :value="n" required class="sr-only" />
                        <span>{{ n }} buổi</span>
                        <span :class="['material-symbols-outlined absolute right-sm text-[18px] text-primary transition-opacity', sessions === n ? 'opacity-100' : 'opacity-0']">check_circle</span>
                    </label>
                </div>
            </UiField>

            <UiAlert type="warning" class="font-body-small text-body-small">
                <p><strong>Quy định SLA:</strong> Yêu cầu được Ban Học thuật xem xét và phản hồi trong vòng {{ slaHours }} giờ.</p>
            </UiAlert>
        </UiForm>
        <template v-if="classes.length" #footer>
            <UiButton variant="secondary" @click="requesting = false">Hủy</UiButton>
            <UiButton type="submit" form="new-adjustment-form" icon="send">Gửi yêu cầu</UiButton>
        </template>
    </UiModal>
</template>
