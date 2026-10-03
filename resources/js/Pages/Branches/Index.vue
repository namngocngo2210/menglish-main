<script setup>
/** Cơ sở & chi nhánh: danh sách + số liệu; Thêm / Sửa trong hộp thoại ngay trên trang, bấm trạng thái để Bật / Tắt, Xóa có hộp xác nhận. */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { currentPosition } from '@/lib/geo';

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

// Cài đặt chấm công: toạ độ (gõ tay / dán từ Google Maps "vĩ độ, kinh độ" / lấy vị trí hiện tại khi đang ở cơ sở).
const checkin = ref(null);
const locating = ref(false);
const locateError = ref(null);
function openCheckin(branch) {
    locateError.value = null;
    checkin.value = { id: branch.id, name: branch.name, ...branch.checkin, latitude: branch.checkin.latitude ?? '', longitude: branch.checkin.longitude ?? '' };
}
function pasteCoordinates(event) {
    const match = (event.clipboardData?.getData('text') ?? '').match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
    if (!match) return;
    event.preventDefault();
    checkin.value.latitude = match[1];
    checkin.value.longitude = match[2];
}
async function useCurrentLocation() {
    locating.value = true;
    locateError.value = null;
    try {
        const pos = await currentPosition();
        checkin.value.latitude = pos.latitude.toFixed(7);
        checkin.value.longitude = pos.longitude.toFixed(7);
    } catch (error) {
        locateError.value = error.message;
    } finally {
        locating.value = false;
    }
}
const mapUrl = computed(() =>
    checkin.value?.latitude && checkin.value?.longitude ? `https://www.google.com/maps/search/?api=1&query=${checkin.value.latitude},${checkin.value.longitude}` : null,
);
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
                        <th class="w-44">Chấm công</th>
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
                        <td>
                            <button type="button" class="text-left hover:underline" :data-checkin-settings="branch.id" @click="openCheckin(branch)">
                                <template v-if="branch.checkin.configured">
                                    <span class="block font-semibold text-on-surface">{{ branch.checkin.work_start }}–{{ branch.checkin.work_end }}</span>
                                    <span class="block text-on-surface-variant">Bán kính {{ branch.checkin.radius }} m · muộn sau {{ branch.checkin.grace }}′</span>
                                </template>
                                <UiBadge v-else color="warning">Chưa cài toạ độ</UiBadge>
                            </button>
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
                                <UiButton variant="ghost" size="sm" icon="pin_drop" title="Cài đặt chấm công" aria-label="Cài đặt chấm công" @click="openCheckin(branch)" />
                                <UiButton variant="ghost" size="sm" icon="edit" title="Chỉnh sửa" aria-label="Chỉnh sửa" @click="openEdit(branch)" />
                                <UiForm :action="route('branches.destroy', branch.id)" method="delete" class="inline" :confirm="`Bạn có chắc chắn muốn xóa chi nhánh ${branch.name}?`" confirm-label="Xóa" danger>
                                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" aria-label="Xóa" />
                                </UiForm>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!branches.length">
                        <td colspan="8"><UiEmptyState icon="apartment" title="Không tìm thấy cơ sở chi nhánh nào phù hợp." /></td>
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

        <!-- Cài đặt chấm công của cơ sở -->
        <UiModal :show="!!checkin" :title="checkin ? `Cài đặt chấm công: ${checkin.name}` : ''" max-width="lg" @close="checkin = null">
            <UiForm v-if="checkin" id="branchCheckinForm" :key="checkin.id" :action="route('branches.attendance', checkin.id)" method="put" class="space-y-md" @success="checkin = null">
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Nhân sự của cơ sở chỉ chấm công được trên điện thoại khi GPS nằm trong bán kính quanh toạ độ này. Dán toạ độ từ Google Maps (nhấn giữ vị trí → sao chép "vĩ độ, kinh độ") hoặc đứng tại cơ sở và bấm "Lấy vị trí hiện tại".
                </p>
                <div class="grid grid-cols-2 gap-md">
                    <UiInput v-model="checkin.latitude" name="latitude" label="Vĩ độ (latitude)" required inputmode="decimal" placeholder="21.0285" class="font-mono" @paste="pasteCoordinates" />
                    <UiInput v-model="checkin.longitude" name="longitude" label="Kinh độ (longitude)" required inputmode="decimal" placeholder="105.8542" class="font-mono" @paste="pasteCoordinates" />
                </div>
                <div class="flex flex-wrap items-center gap-sm">
                    <UiButton variant="secondary" size="sm" icon="my_location" :disabled="locating" @click="useCurrentLocation">{{ locating ? 'Đang lấy vị trí…' : 'Lấy vị trí hiện tại' }}</UiButton>
                    <UiButton v-if="mapUrl" variant="ghost" size="sm" icon="map" :href="mapUrl" target="_blank" rel="noopener">Xem trên bản đồ</UiButton>
                </div>
                <UiAlert v-if="locateError" type="warning">{{ locateError }}</UiAlert>
                <UiInput name="checkin_radius" type="number" label="Bán kính được chấm công" required min="20" max="5000" suffix="mét" :value="checkin.radius" hint="Nên 50–150 m: GPS điện thoại trong nhà thường lệch 10–50 m." />
                <div class="grid grid-cols-3 gap-md">
                    <UiInput name="work_start_time" type="time" label="Giờ vào" required :value="checkin.work_start ?? '08:00'" />
                    <UiInput name="work_end_time" type="time" label="Giờ ra" required :value="checkin.work_end ?? '17:30'" />
                    <UiInput name="late_grace_minutes" type="number" label="Cho phép muộn" required min="0" max="240" suffix="phút" :value="checkin.grace" />
                </div>
                <p class="font-caption text-caption text-on-surface-variant">
                    Giờ vào / ra áp dụng cho nhân sự văn phòng. Giáo viên, trợ giảng, GVNN tính theo buổi dạy đầu tiên trong ngày. Đến muộn quá số phút cho phép → tự lập biên bản chờ giải trình (0đ, người chốt quyết mức phạt).
                </p>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="checkin = null">Hủy</UiButton>
                <UiButton type="submit" form="branchCheckinForm">Lưu cài đặt</UiButton>
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
