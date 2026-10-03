<script setup>
/**
 * Chấm công hằng ngày (quản lý): theo ngày (ai đã vào / ra, đi muộn, chưa chấm, nghỉ phép; bấm dòng xem ảnh + vị trí)
 * hoặc tổng hợp theo tháng (đối soát lương). Nhân sự tự chấm trên điện thoại (/m).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Chấm công hằng ngày' } });

const props = defineProps({
    view: { type: String, required: true },
    date: { type: String, required: true },
    month: { type: String, required: true },
    rows: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
    unconfigured: { type: Array, default: () => [] },
});

const isDay = computed(() => props.view === 'day');
const tabClass = (active) => [
    'inline-flex min-h-9 items-center gap-xs rounded-full px-md font-body-medium text-body-small transition-colors',
    active ? 'bg-primary-container text-white' : 'bg-surface-container-lowest text-on-surface-variant ring-1 ring-outline-variant hover:bg-surface-container-low',
];
</script>

<template>
    <UiPageHeader title="Chấm công hằng ngày" icon="fingerprint" description="Nhân sự chấm công trên điện thoại bằng ảnh khuôn mặt + GPS tại cơ sở; giờ lấy theo máy chủ.">
        <template #actions>
            <UiButton variant="secondary" icon="smartphone" :href="route('mobile.home')">Giao diện điện thoại</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-md">
        <UiAlert v-if="unconfigured.length" type="warning">
            Cơ sở chưa cài toạ độ chấm công (nhân sự chưa chấm được): <strong>{{ unconfigured.join(', ') }}</strong>.
            <Link v-if="can('branch.update')" :href="route('branches.index')" class="font-semibold underline">Cài ở Cơ sở &amp; chi nhánh</Link>
        </UiAlert>

        <div class="flex flex-wrap gap-sm">
            <Link :href="route('staff-attendance.index', { view: 'day', date })" :class="tabClass(isDay)" :aria-current="isDay ? 'page' : null">Theo ngày</Link>
            <Link :href="route('staff-attendance.index', { view: 'month', month })" :class="tabClass(!isDay)" :aria-current="!isDay ? 'page' : null">Tổng hợp tháng</Link>
        </div>

        <div v-if="isDay && stats" class="grid grid-cols-2 gap-md sm:grid-cols-4">
            <UiStatCard label="Nhân sự" :value="stats.staff" icon="groups" />
            <UiStatCard label="Đã chấm vào" :value="stats.checked_in" tone="success" icon="login" />
            <UiStatCard label="Đi muộn" :value="stats.late" tone="error" icon="alarm" />
            <UiStatCard label="Nghỉ có phép" :value="stats.leave" tone="secondary" icon="beach_access" />
        </div>

        <UiFilterBar placeholder="Tìm tên, mã nhân sự…" :keep="['view']" :reset-url="route('staff-attendance.index', { view })">
            <UiInput v-if="isDay" name="date" type="date" label="Ngày" :value="date" />
            <UiInput v-else name="month" type="month" label="Tháng" :value="month" />
            <UiSelect v-if="branches.length > 1" name="branch_id" label="Cơ sở" :options="branches" placeholder="Tất cả cơ sở" />
            <UiSelect v-if="isDay" name="status" label="Tình trạng" :options="statuses" placeholder="Tất cả" />
        </UiFilterBar>

        <UiDataTable v-if="isDay" min-width="760px">
            <table>
                <thead>
                    <tr>
                        <th>Nhân sự</th>
                        <th>Giờ vào</th>
                        <th>Giờ ra</th>
                        <th>Giờ phải có mặt</th>
                        <th>Tình trạng</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows.data" :key="row.user.id" :data-href="row.attendance ? route('staff-attendance.show', row.attendance.id) : null" :data-modal="row.attendance ? 'lg' : null">
                        <td>
                            <p class="font-semibold text-on-surface">{{ row.user.name }}</p>
                            <p class="text-caption text-on-surface-variant">{{ [row.user.code, row.user.branch].filter(Boolean).join(' · ') }}</p>
                        </td>
                        <td>
                            <div v-if="row.attendance?.check_in" class="flex items-center gap-sm">
                                <img v-if="row.attendance.check_in_photo" :src="row.attendance.check_in_photo" alt="" class="h-9 w-9 rounded-md object-cover" loading="lazy" />
                                <span>
                                    <span class="block font-mono font-semibold">{{ row.attendance.check_in }}</span>
                                    <span v-if="row.attendance.check_in_distance !== null" class="block text-caption text-on-surface-variant">cách {{ formatNumber(row.attendance.check_in_distance) }} m</span>
                                    <span v-else-if="row.attendance.source === 'request'" class="block text-caption text-on-surface-variant">bổ sung công</span>
                                </span>
                            </div>
                            <span v-else class="text-on-surface-subtle">—</span>
                        </td>
                        <td class="font-mono">{{ row.attendance?.check_out ?? '—' }}</td>
                        <td class="font-mono text-on-surface-variant">{{ row.attendance?.expected || '—' }}</td>
                        <td>
                            <UiBadge v-if="row.attendance" :color="row.attendance.status_tone">{{ row.attendance.status_label }}</UiBadge>
                            <UiBadge v-else-if="row.leave !== null" color="secondary" :title="row.leave">Nghỉ có phép</UiBadge>
                            <UiBadge v-else color="neutral">Chưa chấm công</UiBadge>
                            <span v-if="row.attendance?.penalty" class="mt-0.5 block text-caption text-error">Biên bản {{ row.attendance.penalty }}</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.data.length">
                        <td colspan="5"><UiEmptyState icon="person_search" title="Không có nhân sự phù hợp bộ lọc." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="rows" unit="nhân sự" /></template>
        </UiDataTable>

        <UiDataTable v-else min-width="760px">
            <table>
                <thead>
                    <tr>
                        <th>Nhân sự</th>
                        <th class="text-right">Ngày công</th>
                        <th class="text-right">Đi muộn</th>
                        <th class="text-right">Muộn có phép</th>
                        <th class="text-right">Về sớm</th>
                        <th class="text-right">Quên chấm ra</th>
                        <th class="text-right">Nghỉ phép</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows.data" :key="row.user.id">
                        <td>
                            <p class="font-semibold text-on-surface">{{ row.user.name }}</p>
                            <p class="text-caption text-on-surface-variant">{{ [row.user.code, row.user.branch].filter(Boolean).join(' · ') }}</p>
                        </td>
                        <td class="text-right font-mono">{{ row.summary.days }}</td>
                        <td class="text-right font-mono" :class="row.summary.late_count ? 'text-error' : ''">{{ row.summary.late_count }} lần · {{ row.summary.late_minutes }}′</td>
                        <td class="text-right font-mono">{{ row.summary.excused_late }}</td>
                        <td class="text-right font-mono">{{ row.summary.early_count }}</td>
                        <td class="text-right font-mono">{{ row.summary.missing_out }}</td>
                        <td class="text-right font-mono">{{ row.summary.leave_days }}</td>
                    </tr>
                    <tr v-if="!rows.data.length">
                        <td colspan="7"><UiEmptyState icon="person_search" title="Không có nhân sự phù hợp bộ lọc." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="rows" unit="nhân sự" /></template>
        </UiDataTable>
    </div>
</template>
