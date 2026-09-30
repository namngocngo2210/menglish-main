<script setup>
/**
 * Danh sách lớp: màn chính của menu Lớp học (gộp Sơ đồ khối + Danh sách lớp chi tiết). Bấm một lớp → Trang lớp.
 * Chip trạng thái / chương trình / cấp độ giữ các bộ lọc khác; bấm lại chip đang chọn = bỏ chọn.
 */
import { Link } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';

defineOptions({ layout: { title: 'Danh sách lớp' } });

const props = defineProps({
    classes: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    openCount: { type: Number, default: 0 },
    programCounts: { type: Array, default: () => [] },
    levelCounts: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, required: true },
});

const chipClass = (active) =>
    'inline-flex items-center gap-xs rounded-full border px-sm py-1 font-body-small text-body-small font-semibold transition-colors ' +
    (active ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface');
const countClass = (active) => 'rounded-full px-1.5 font-code text-xs leading-4 ' + (active ? 'bg-white/25' : 'bg-surface-container-high');
const chipUrl = (key, value) => urlWith({ page: null, [key]: props.filters[key] === value ? null : value });
const progress = (c) => (c.total_sessions > 0 ? Math.round((c.done_sessions * 100) / c.total_sessions) : null);
</script>

<template>
    <UiPageHeader title="Danh sách lớp" icon="co_present" description="Tìm lớp theo trạng thái, chương trình, cấp độ; bấm vào lớp để làm mọi việc của lớp đó." />

    <div class="space-y-5">
        <!-- Việc cần chú ý -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Link :href="route('classes.index', { status: 'active' })" class="block">
                <UiStatCard label="Đang học" :value="stats.active" tone="success" icon="play_circle" />
            </Link>
            <Link :href="route('classes.index', { status: 'pending_schedule' })" class="block">
                <UiStatCard label="Chờ cấu hình lịch" :value="stats.pending_schedule" :tone="stats.pending_schedule ? 'warning' : 'default'" icon="edit_calendar" />
            </Link>
            <UiStatCard label="Chưa đủ ngưỡng khai giảng" :value="stats.short" :tone="stats.short ? 'warning' : 'default'" icon="group_add" />
            <Link :href="route('tasks.classes-dashboard', { attendance: 'missing' })" class="block">
                <UiStatCard label="Buổi hôm nay chưa điểm danh" :value="stats.missing_attendance" :tone="stats.missing_attendance ? 'error' : 'default'" icon="fact_check" />
            </Link>
        </div>

        <UiFilterBar :action="route('classes.index')" placeholder="Tìm tên lớp hoặc mã lớp..." :reset-url="route('classes.index')" class="!mb-0">
            <template #quick>
                <div class="space-y-sm">
                    <nav class="flex flex-wrap items-center gap-xs" aria-label="Lọc theo trạng thái lớp">
                        <Link :href="urlWith({ page: null, status: null })" :class="chipClass(!filters.status)" :aria-current="!filters.status ? 'page' : null">
                            Đang mở <span :class="countClass(!filters.status)">{{ openCount }}</span>
                        </Link>
                        <Link v-for="s in statuses" :key="s.key" :href="chipUrl('status', s.key)" :class="chipClass(filters.status === s.key)" :aria-current="filters.status === s.key ? 'page' : null">
                            {{ s.label }} <span :class="countClass(filters.status === s.key)">{{ s.count }}</span>
                        </Link>
                    </nav>
                    <!-- Sơ đồ khối: số lớp theo chương trình / cấp độ, bấm để lọc -->
                    <nav v-if="programCounts.length" class="flex flex-wrap items-center gap-xs" aria-label="Lọc theo chương trình">
                        <span class="mr-xs font-body-small text-body-small text-on-surface-variant">Chương trình</span>
                        <Link v-for="p in programCounts" :key="p.label" :href="chipUrl('program', p.label)" :class="chipClass(filters.program === p.label)" :aria-current="filters.program === p.label ? 'page' : null">
                            {{ p.label }} <span :class="countClass(filters.program === p.label)">{{ p.total }}</span>
                        </Link>
                    </nav>
                    <nav v-if="levelCounts.length" class="flex flex-wrap items-center gap-xs" aria-label="Lọc theo cấp độ">
                        <span class="mr-xs font-body-small text-body-small text-on-surface-variant">Cấp độ</span>
                        <Link v-for="l in levelCounts" :key="l.label" :href="chipUrl('level', l.label)" :class="chipClass(filters.level === l.label)" :aria-current="filters.level === l.label ? 'page' : null">
                            {{ l.label }} <span :class="countClass(filters.level === l.label)">{{ l.total }}</span>
                        </Link>
                    </nav>
                </div>
            </template>
            <template v-for="(value, name) in filters" :key="name">
                <input v-if="value" type="hidden" :name="name" :value="value" />
            </template>
            <UiSelect name="branch_id" label="Chi nhánh" placeholder="Tất cả chi nhánh" :options="branches" />
        </UiFilterBar>

        <!-- Gộp trạng thái vào cột Lớp và Big Test vào cột Tiến độ để bảng vừa khung 1440; cột Lớp cố định khi cuộn ngang. -->
        <UiDataTable min-width="900px">
            <table class="whitespace-nowrap text-xs">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-surface-container-low">Lớp</th>
                        <th>Lịch học</th>
                        <th>GV / CM</th>
                        <th class="text-center">Sĩ số</th>
                        <th>Tiến độ · Big Test</th>
                        <th>Việc tiếp theo</th>
                        <th class="text-right" aria-label="Thao tác"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in classes.data" :key="c.id">
                        <td class="sticky left-0 z-10 border-r border-surface-container bg-surface-container-lowest">
                            <div class="flex items-center gap-xs">
                                <Link :href="route('classes.show', c.id)" class="font-code font-bold text-primary hover:underline">{{ c.code }}</Link>
                                <UiBadge :color="c.status.color" pill>{{ c.status.label }}</UiBadge>
                            </div>
                            <!-- Tên lớp cũng là link mở lớp (trước đây chỉ có nút ở cuối dòng, bị khuất khi bảng rộng). -->
                            <Link :href="route('classes.show', c.id)" class="block max-w-[220px] truncate font-semibold text-on-surface hover:text-primary hover:underline" :title="c.name">{{ c.name }}</Link>
                            <div class="max-w-[220px] truncate text-xs text-on-surface-subtle" :title="c.meta">{{ c.meta }}</div>
                        </td>
                        <td class="min-w-[170px] max-w-[220px] whitespace-normal text-on-surface-variant">{{ c.schedule_text || 'Chưa xếp lịch' }}</td>
                        <td>
                            <div class="font-medium text-on-surface">{{ c.teacher ?? 'Chưa phân công' }}</div>
                            <div v-if="c.foreign_teacher" class="text-xs text-on-surface-variant">GVNN: {{ c.foreign_teacher }}</div>
                        </td>
                        <td class="text-center" :data-seats="c.id">
                            <span class="font-bold text-on-surface">{{ c.seat.occupied }}</span><span class="text-on-surface-subtle">/{{ c.seat.capacity || '∞' }}</span>
                            <div class="text-xs">
                                <span v-if="c.seat.left === null" class="text-on-surface-subtle">Không giới hạn</span>
                                <span v-else-if="c.seat.left === 0" class="font-bold text-error">Đã đủ</span>
                                <span v-else class="font-semibold text-tertiary">Còn {{ c.seat.left }} chỗ</span>
                            </div>
                            <div v-if="c.seat.needed > 0" class="text-xs font-semibold text-warning" :title="`Ngưỡng khai giảng ${c.seat.min} học viên`">Thiếu {{ c.seat.needed }}/{{ c.seat.min }} để KG</div>
                        </td>
                        <td>
                            <span v-if="progress(c) === null" class="text-xs text-on-surface-subtle">Chưa có buổi học</span>
                            <template v-else>
                                <div class="mb-1 h-1.5 w-24 overflow-hidden rounded-full bg-surface-container">
                                    <div class="h-full rounded-full bg-secondary" :style="{ width: progress(c) + '%' }"></div>
                                </div>
                                <span class="block font-code text-xs text-on-surface-variant">{{ c.done_sessions }}/{{ c.total_sessions }} buổi</span>
                            </template>
                            <span v-if="!c.big_tests.length" class="mt-[2px] block text-xs text-on-surface-subtle">Big Test: chưa có</span>
                            <div v-else class="mt-[2px] flex items-center gap-1" aria-label="Big Test">
                                <span
                                    v-for="(bt, i) in c.big_tests"
                                    :key="i"
                                    :class="['flex h-5 w-5 items-center justify-center rounded text-xs font-bold', bt.done ? 'bg-tertiary/10 text-on-tertiary-container' : 'bg-surface-container text-on-surface-variant']"
                                    :title="bt.title + (bt.date ? ' — ' + bt.date : '')"
                                    >{{ i + 1 }}</span
                                >
                            </div>
                        </td>
                        <td class="max-w-[150px] whitespace-normal">
                            <Link v-if="c.next" :href="route('classes.show', { id: c.id, tab: c.next.tab })" class="hover:opacity-80">
                                <UiBadge :color="c.next.tone" :dot="false">{{ c.next.label }}</UiBadge>
                            </Link>
                            <span v-else class="text-xs text-on-surface-subtle">—</span>
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                <UiButton variant="secondary" size="sm" :href="route('classes.show', c.id)">Mở lớp</UiButton>
                                <UiButton v-if="can('class.update')" variant="ghost" size="sm" icon="edit" :href="route('classes.edit', c.id)" :aria-label="`Sửa lớp ${c.code}`" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!classes.data.length">
                        <td colspan="7">
                            <UiEmptyState icon="search_off" title="Không tìm thấy lớp học nào thỏa mãn điều kiện tìm kiếm.">
                                <UiButton v-if="can('class.create')" icon="add" :href="route('classes.create')">Tạo lớp mới</UiButton>
                            </UiEmptyState>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="classes" /></template>
        </UiDataTable>
    </div>
</template>
