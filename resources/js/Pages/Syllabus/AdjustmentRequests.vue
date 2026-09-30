<script setup>
/**
 * Mockup 01_Web_Admin/04: danh sách yêu cầu giãn tiến độ (SLA) — bấm dòng → chi tiết trong hộp thoại
 * (?request=; đóng thì bỏ query). Duyệt thêm buổi vào lịch lớp; từ chối bắt buộc lý do.
 */
import { nextTick, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';

defineOptions({ layout: { title: 'Duyệt yêu cầu xin điều chỉnh tiến độ' } });

const props = defineProps({
    requests: { type: Object, required: true },
    selected: { type: Object, default: null },
    status: { type: String, default: 'all' },
    statusOptions: { type: Array, default: () => [] },
    listTitle: { type: String, default: '' },
    listUrl: { type: String, required: true },
    maxExtraSessions: { type: Number, default: 10 },
    canReview: { type: Boolean, default: false },
});

const page = usePage();
const open = ref(!!props.selected);
watch(() => props.selected?.id, (id) => (open.value = !!id));

// Đang nhập lý do từ chối (lỗi thiếu lý do → giữ ô từ chối mở).
const rejecting = ref(!!page.props.errors?.rejection_reason);
watch(() => page.props.errors?.rejection_reason, (e) => e && (rejecting.value = true));
async function startReject() {
    rejecting.value = true;
    await nextTick();
    document.getElementById('reject-reason')?.focus();
}
</script>

<template>
    <UiPageHeader title="Duyệt yêu cầu xin điều chỉnh tiến độ" description="Quản lý các yêu cầu giãn tiến độ từ giáo viên. Duyệt sẽ thêm buổi vào cuối lịch của lớp; từ chối bắt buộc nhập lý do." :back="route('syllabus.documents')">
        <template #actions>
            <GetForm class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant">filter_list</span>
                <UiSelect name="status" :value="status" :options="statusOptions" aria-label="Lọc" />
            </GetForm>
            <UiButton variant="secondary" icon="speed" :href="route('syllabus.teacher-adjust')">Gửi yêu cầu mới</UiButton>
        </template>
    </UiPageHeader>

    <UiDataTable min-width="760px">
        <template #header>
            <h2 class="font-h3 text-h3 text-on-surface">{{ listTitle }}</h2>
            <UiBadge color="primary" :dot="false" pill>{{ requests.total }} yêu cầu</UiBadge>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Giáo viên</th>
                    <th>Lớp / Chặng học</th>
                    <th class="text-right">Xin thêm</th>
                    <th>Lý do</th>
                    <th>Ngày gửi</th>
                    <th>Trạng thái</th>
                    <th class="text-right"><span class="sr-only">Thao tác</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="req in requests.data" :key="req.id" :data-href="req.detail_url" :class="['cursor-pointer', { 'bg-primary-fixed/30': selected?.id === req.id }]">
                    <td>
                        <Link :href="req.detail_url" class="flex items-center gap-sm font-semibold text-on-surface hover:text-primary">
                            <UiAvatar :name="req.teacher ?? '?'" size="sm" />
                            <span class="truncate">{{ req.teacher ?? '—' }}</span>
                        </Link>
                    </td>
                    <td>{{ req.class_stage_label }}</td>
                    <td class="whitespace-nowrap text-right font-semibold">{{ req.extra_sessions || 0 }} buổi</td>
                    <td class="max-w-[280px]"><p class="line-clamp-1 text-on-surface-variant" :title="req.reason">{{ req.reason }}</p></td>
                    <td class="whitespace-nowrap font-code text-body-small">{{ req.created_at }}</td>
                    <td class="whitespace-nowrap">
                        <UiBadge v-if="req.status === 'pending'" :color="req.sla_overdue ? 'error' : 'success'">{{ req.sla_overdue ? 'Quá hạn' : 'Còn hạn' }}</UiBadge>
                        <UiBadge v-else :color="req.status === 'approved' ? 'success' : 'error'">{{ req.status_label }}</UiBadge>
                    </td>
                    <td class="text-right"><UiButton variant="secondary" size="sm" icon="visibility" :href="req.detail_url">Xem</UiButton></td>
                </tr>
                <tr v-if="!requests.data.length">
                    <td colspan="7"><UiEmptyState icon="inbox" title="Không có yêu cầu nào" /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="requests" :options="[]" unit="yêu cầu" /></template>
    </UiDataTable>

    <!-- Chi tiết yêu cầu: mở sẵn khi URL có ?request=; đóng → bỏ request khỏi thanh địa chỉ. -->
    <UiModal v-if="selected" :show="open" title="Chi tiết yêu cầu điều chỉnh tiến độ" max-width="2xl" :dismiss-url="listUrl" @close="open = false">
        <div class="space-y-lg">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-sm">
                    <UiAvatar :name="selected.teacher ?? '?'" />
                    <div>
                        <p class="font-h3 text-h3 text-on-surface">{{ selected.teacher ?? '—' }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ selected.teacher_code || 'Giáo viên' }}</p>
                    </div>
                </div>
                <UiBadge v-if="selected.status === 'pending'" :color="selected.sla_overdue ? 'error' : 'success'">{{ selected.sla_overdue ? 'Quá hạn xử lý' : 'Còn hạn xử lý' }}</UiBadge>
                <UiBadge v-else :color="selected.status === 'approved' ? 'success' : 'error'">{{ selected.status_label }}</UiBadge>
            </div>

            <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <dt class="font-label text-label uppercase text-on-surface-variant">Lớp / Chặng học</dt>
                    <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-medium text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">school</span>{{ selected.class_stage_label }}</dd>
                </div>
                <div>
                    <dt class="font-label text-label uppercase text-on-surface-variant">Ngày gửi yêu cầu</dt>
                    <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-small text-on-surface"><span class="material-symbols-outlined text-[16px]">calendar_today</span>{{ selected.created_at }}</dd>
                </div>
                <div>
                    <dt class="font-label text-label uppercase text-on-surface-variant">Số buổi xin thêm</dt>
                    <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-small text-primary"><span class="material-symbols-outlined text-[16px]">add_circle</span>+{{ selected.extra_sessions || 0 }} buổi</dd>
                </div>
                <div>
                    <dt class="font-label text-label uppercase text-on-surface-variant">Kết thúc lớp hiện tại</dt>
                    <dd class="mt-xs font-code text-body-small text-on-surface">{{ selected.class_end_date ?? '—' }}</dd>
                </div>
            </dl>

            <div>
                <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Lý do xin giãn tiến độ</p>
                <div class="relative rounded-lg border border-outline-variant bg-surface-container-lowest p-md pl-xl">
                    <span class="material-symbols-outlined absolute left-sm top-sm text-[20px] text-outline">format_quote</span>
                    <p class="whitespace-pre-line font-body-base text-body-base text-on-surface">{{ selected.reason }}</p>
                    <p v-if="selected.request_type" class="mt-xs font-caption text-caption text-on-surface-variant">{{ selected.request_type }}</p>
                </div>
            </div>

            <UiAlert v-if="selected.status === 'approved'" type="success" class="font-body-small text-body-small">
                <p class="font-semibold">Đã duyệt bởi {{ selected.reviewed_by }}</p>
                <p class="mt-1">{{ selected.applied_note }}</p>
            </UiAlert>
            <UiAlert v-else-if="selected.status === 'rejected'" type="error" class="font-body-small text-body-small">
                <p class="font-semibold">Đã từ chối bởi {{ selected.reviewed_by }}</p>
                <p class="mt-1">Lý do: {{ selected.rejection_reason || '—' }}</p>
            </UiAlert>
            <template v-else-if="canReview">
                <UiForm v-show="rejecting" id="reject-form" :action="route('syllabus.adjustment-requests.reject', selected.id)" method="post" class="rounded-lg border border-error/30 bg-error/5 p-md">
                    <UiTextarea id="reject-reason" label="Lý do từ chối (Bắt buộc)" name="rejection_reason" rows="3" placeholder="Nhập lý do chi tiết để phản hồi lại giáo viên..." />
                </UiForm>
                <UiForm v-show="!rejecting" id="approve-form" :action="route('syllabus.adjustment-requests.approve', selected.id)" method="post">
                    <UiInput
                        type="number"
                        name="extra_sessions"
                        label="Số buổi thêm vào lịch khi duyệt"
                        min="0"
                        :max="maxExtraSessions"
                        :value="String(selected.extra_sessions ?? 0)"
                        hint="Buổi mới nối tiếp sau buổi cuối của lớp, theo TKB, bỏ qua ngày nghỉ lễ; 0 = chỉ ghi nhận, không đổi lịch."
                    />
                </UiForm>
            </template>
        </div>

        <template v-if="canReview && selected.status === 'pending'" #footer>
            <div v-show="!rejecting" class="flex flex-wrap justify-end gap-sm">
                <UiButton variant="danger-text" icon="cancel" @click="startReject">Từ chối</UiButton>
                <UiButton type="submit" form="approve-form" icon="check_circle">Phê duyệt</UiButton>
            </div>
            <div v-show="rejecting" class="flex flex-wrap justify-end gap-sm">
                <UiButton variant="secondary" @click="rejecting = false">Quay lại</UiButton>
                <UiButton type="submit" form="reject-form" variant="danger">Xác nhận từ chối</UiButton>
            </div>
        </template>
    </UiModal>
</template>
