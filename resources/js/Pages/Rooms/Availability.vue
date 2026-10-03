<script setup>
/**
 * Quản lý phòng học — tab Tra cứu phòng trống: phòng của chi nhánh không có buổi học chồng giờ trong khung giờ đã chọn
 * (theo buổi học thật). Học vụ một chi nhánh: chi nhánh cố định. Lỗi bộ lọc hiện dưới từng ô, giữ giá trị đã nhập.
 */
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import BranchBlocked from './BranchBlocked.vue';
import RoomTabs from './RoomTabs.vue';

defineOptions({ layout: { title: 'Tra cứu phòng trống' } });

const props = defineProps({
    blocked: { type: Boolean, default: false },
    branches: { type: Array, default: () => [] },
    lockedBranch: { type: Object, default: null },
    types: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    filterErrors: { type: [Object, Array], default: () => ({}) },
    result: { type: Object, default: null },
});

const form = reactive({
    branch_id: props.filters.branch_id ? String(props.filters.branch_id) : '',
    date: props.filters.date ?? '',
    start: props.filters.start ?? '',
    end: props.filters.end ?? '',
    room_type_id: props.filters.room_type_id ? String(props.filters.room_type_id) : '',
});

function search() {
    router.get(route('rooms.availability'), { ...form, date: form.date || '' }, { preserveScroll: true });
}
</script>

<template>
    <RoomTabs :counts="counts" />

    <BranchBlocked v-if="blocked" />

    <template v-else>
        <form class="mb-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm" role="search" @submit.prevent="search">
            <div class="grid grid-cols-1 gap-md md:grid-cols-2 xl:grid-cols-12 xl:items-start">
                <div class="xl:col-span-3">
                    <UiField v-if="lockedBranch" label="Chi nhánh" required hint="Chi nhánh trực thuộc của bạn (cố định)">
                        <div class="flex items-center justify-between rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-base text-body-base text-on-surface">
                            {{ lockedBranch.name }}
                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">lock</span>
                        </div>
                    </UiField>
                    <UiSelect v-else id="lookup_branch" v-model="form.branch_id" label="Chi nhánh" required placeholder="-- Chọn cơ sở --" :options="branches" searchable :error="filterErrors.branch_id" />
                </div>
                <div class="xl:col-span-2">
                    <UiInput id="lookup_date" v-model="form.date" type="date" label="Ngày" required :error="filterErrors.date" />
                </div>
                <fieldset class="xl:col-span-3">
                    <legend class="mb-xs font-body-medium text-body-medium text-on-surface">Khung giờ (Bắt đầu - Kết thúc) <span class="text-error">*</span></legend>
                    <div class="flex items-start gap-xs">
                        <UiInput id="lookup_start" v-model="form.start" type="time" aria-label="Giờ bắt đầu" class="font-code" :error="filterErrors.start" />
                        <span class="pt-sm text-on-surface-variant" aria-hidden="true">–</span>
                        <UiInput id="lookup_end" v-model="form.end" type="time" aria-label="Giờ kết thúc" class="font-code" :error="filterErrors.end" />
                    </div>
                </fieldset>
                <div class="xl:col-span-2">
                    <UiSelect id="lookup_type" v-model="form.room_type_id" label="Loại phòng (tùy chọn)" placeholder="Tất cả loại phòng" :options="types" />
                </div>
                <div class="flex xl:col-span-2 xl:pt-lg">
                    <UiButton type="submit" icon="search" class="w-full">Tra cứu</UiButton>
                </div>
            </div>
        </form>

        <!-- Chưa tra cứu -->
        <div v-if="!result" class="rounded-xl border border-dashed border-outline-variant bg-surface-container-lowest">
            <UiEmptyState icon="manage_search" title="Sẵn sàng tra cứu" description="Vui lòng chọn chi nhánh, ngày và khoảng giờ rồi nhấn nút “Tra cứu” để kiểm tra danh sách phòng trống." />
        </div>

        <template v-else>
            <div class="rounded-xl border border-surface-container-highest bg-surface-container-lowest">
                <UiEmptyState v-if="!result.branch_has_rooms" icon="domain_disabled" title="Chưa thiết lập phòng" description="Chi nhánh chưa có phòng học nào." />
                <UiEmptyState v-else-if="!result.total" icon="filter_alt_off" title="Không tìm thấy phân loại" description="Chi nhánh chưa có phòng thuộc loại này." />
                <UiEmptyState v-else-if="result.holiday" icon="event_busy" title="Chưa phát sinh lịch" description="Ngày đã chọn là ngày nghỉ của chi nhánh, không có lịch học." />
                <UiEmptyState v-else-if="!result.rooms.length" icon="block" title="Đã kín lịch học" description="Không còn phòng trống trong khoảng giờ này." />
                <template v-else>
                    <p class="flex flex-wrap items-center gap-xs border-b border-surface-container px-md py-sm font-body-medium text-body-medium text-on-surface" data-testid="room-lookup-summary">
                        <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">check_circle</span>
                        Tìm thấy <strong class="text-primary">{{ result.rooms.length }} phòng trống</strong> trong khung giờ
                        <span class="font-code">{{ filters.start }} – {{ filters.end }}</span> ngày <span class="font-code">{{ formatDate(filters.date) }}</span> tại <strong>{{ result.branch }}</strong>.
                    </p>
                    <UiDataTable min-width="520px" class="!rounded-none !border-0 !shadow-none">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tên phòng</th>
                                    <th>Loại phòng</th>
                                    <th>Sức chứa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="room in result.rooms" :key="room.id">
                                    <td class="font-semibold">{{ room.name }}</td>
                                    <td>{{ room.type }}</td>
                                    <td class="whitespace-nowrap">
                                        <span v-if="room.capacity" class="font-code">{{ room.capacity }} chỗ</span>
                                        <span v-else class="italic text-on-surface-variant">Chưa đặt</span>
                                        <UiBadge color="success" class="ml-sm">Sẵn sàng</UiBadge>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>
                </template>
            </div>
        </template>
    </template>
</template>
