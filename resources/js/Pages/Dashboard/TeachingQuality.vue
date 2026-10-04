<script setup>
/**
 * Chất lượng giảng dạy theo tháng (DashboardController::teaching): chọn tháng; giáo viên / trợ giảng thấy số liệu của mình
 * + biểu đồ theo lớp; người theo dõi giáo viên thấy biểu đồ + bảng theo giáo viên; Học thuật / Admin / Quản lý thêm báo cáo
 * tháng của giáo viên và order học thuật.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import BarList from '@/Components/Dashboard/BarList.vue';
import { formatDate } from '@/lib/format';

const props = defineProps({ teaching: { type: Object, required: true } });

const mine = computed(() => props.teaching.mine);
const team = computed(() => props.teaching.team);
const opened = ref(null);

function pickMonth(event) {
    router.get(route('dashboard'), { month: event.target.value }, { preserveScroll: true, preserveState: true, only: ['teaching'] });
}

const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`);
const score = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 }));
const studentsHint = (row) => `${row.students} học sinh`;

const mineStats = computed(() => {
    if (!mine.value) return [];
    const { work, totals } = mine.value;
    return [
        { label: 'Ngày công', value: work.work_days, icon: 'event_available', tone: 'primary' },
        { label: 'Buổi đi muộn', value: work.late, icon: 'schedule', tone: work.late > 0 ? 'warning' : 'default' },
        { label: 'Ngày nghỉ phép', value: work.leave_days, icon: 'beach_access', tone: 'default' },
        { label: 'Lỗi vi phạm', value: work.violations, icon: 'gavel', tone: work.violations > 0 ? 'error' : 'default' },
        { label: 'Lớp đang giữ', value: totals.classes, icon: 'co_present', tone: 'default' },
        { label: 'Học sinh đang dạy', value: totals.students, icon: 'groups', tone: 'default' },
        { label: 'HS mới / nghỉ trong tháng', value: `${totals.new} / ${totals.dropped}`, icon: 'swap_vert', tone: 'default' },
        { label: 'Điểm TB tháng', value: score(totals.score), icon: 'grade', tone: 'secondary', hint: 'Mini test, thang 10' },
    ];
});

const classRows = (key) => (mine.value?.classes ?? []).map((c) => ({ key: c.id, label: c.code || c.name, value: c[key], hint: `${c.name} · ${studentsHint(c)}` }));
const teamRows = (key) => (team.value?.rows ?? []).map((t) => ({ key: t.id, label: t.name, value: t[key], hint: `${t.classes} lớp · ${t.students} học sinh` }));
const supportCount = (report) => report.classes.filter((c) => c.need_support).length;
</script>

<template>
    <section class="space-y-md" data-teaching-quality>
        <div class="flex flex-wrap items-end justify-between gap-sm">
            <div>
                <h2 class="font-h2 text-h2 text-on-surface">Chất lượng giảng dạy</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Tổng hợp từ điểm danh, bài về nhà, điểm mini test và chấm công trong tháng</p>
            </div>
            <div class="flex flex-wrap items-center gap-sm">
                <label class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    Tháng
                    <select class="rounded-lg border-surface-container-highest bg-surface-container-lowest py-1 font-body-small text-body-small text-on-surface focus:border-primary-container focus:ring-primary-container" :value="teaching.month" aria-label="Chọn tháng" @change="pickMonth">
                        <option v-for="m in teaching.months" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </label>
                <UiButton v-if="teaching.reportUrl" size="sm" variant="secondary" icon="edit_note" :href="teaching.reportUrl">Viết báo cáo tháng</UiButton>
            </div>
        </div>

        <!-- Giáo viên / trợ giảng: số liệu của chính mình -->
        <template v-if="mine">
            <div class="grid grid-cols-2 gap-md lg:grid-cols-4" data-teaching-mine>
                <UiStatCard v-for="stat in mineStats" :key="stat.label" :label="stat.label" :value="stat.value" :icon="stat.icon" :tone="stat.tone" :hint="stat.hint" />
            </div>
            <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <BarList title="Chuyên cần theo lớp" :rows="classRows('attendance')" :max="100" unit="%" />
                <BarList title="Làm bài về nhà theo lớp" :rows="classRows('homework')" :max="100" unit="%" />
                <BarList title="Điểm trung bình theo lớp" :rows="classRows('score')" :max="10" />
            </div>
        </template>

        <!-- Người theo dõi giáo viên: theo từng giáo viên (GV chính của lớp) -->
        <template v-if="team">
            <h3 v-if="mine" class="pt-sm font-h3 text-h3 text-on-surface">Theo giáo viên</h3>
            <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <BarList title="Chuyên cần" :rows="teamRows('attendance')" :max="100" unit="%" />
                <BarList title="Làm bài về nhà" :rows="teamRows('homework')" :max="100" unit="%" />
                <BarList title="Điểm trung bình" :rows="teamRows('score')" :max="10" />
            </div>
            <div class="rounded-xl border border-surface-variant bg-surface-container-lowest" data-teaching-team>
                <UiDataTable v-if="team.rows.length">
                    <table>
                        <thead>
                            <tr>
                                <th>Giáo viên</th>
                                <th class="text-right">Lớp</th>
                                <th class="text-right">HS</th>
                                <th class="text-right">Mới / nghỉ</th>
                                <th class="text-right">Chuyên cần</th>
                                <th class="text-right">BTVN</th>
                                <th class="text-right">Điểm TB</th>
                                <th class="text-right">Ngày công</th>
                                <th class="text-right">Đi muộn</th>
                                <th class="text-right">Phép</th>
                                <th class="text-right">Vi phạm</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in team.rows" :key="row.id">
                                <td class="font-semibold">{{ row.name }}</td>
                                <td class="text-right tabular-nums">{{ row.classes }}</td>
                                <td class="text-right tabular-nums">{{ row.students }}</td>
                                <td class="text-right tabular-nums">{{ row.new }} / {{ row.dropped }}</td>
                                <td class="text-right tabular-nums">{{ pct(row.attendance) }}</td>
                                <td class="text-right tabular-nums">{{ pct(row.homework) }}</td>
                                <td class="text-right tabular-nums">{{ score(row.score) }}</td>
                                <td class="text-right tabular-nums">{{ row.work_days }}</td>
                                <td :class="['text-right tabular-nums', row.late > 0 ? 'text-warning' : '']">{{ row.late }}</td>
                                <td class="text-right tabular-nums">{{ row.leave_days }}</td>
                                <td :class="['text-right tabular-nums', row.violations > 0 ? 'text-error' : '']">{{ row.violations }}</td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <UiEmptyState v-else icon="co_present" title="Chưa có lớp đang chạy có giáo viên chính trong phạm vi của bạn" />
            </div>
        </template>

        <!-- Học thuật / Admin / Quản lý: báo cáo tháng của giáo viên + order học thuật -->
        <div v-if="teaching.teacherReports" class="grid grid-cols-1 gap-md lg:grid-cols-3">
            <section class="rounded-xl border border-surface-variant bg-surface-container-lowest lg:col-span-2" data-teacher-reports>
                <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Báo cáo tháng của giáo viên</h3>
                    <span class="font-body-small text-body-small text-on-surface-variant">{{ teaching.teacherReports.filter((r) => r.submitted).length }}/{{ teaching.teacherReports.length }} đã nộp</span>
                </div>
                <button
                    v-for="report in teaching.teacherReports"
                    :key="report.id"
                    type="button"
                    :disabled="!report.submitted"
                    class="flex w-full items-start justify-between gap-sm border-b border-surface-variant/60 px-md py-sm text-left last:border-0 enabled:hover:bg-surface-container-low"
                    @click="opened = report"
                >
                    <span class="min-w-0">
                        <span class="block font-body-medium text-body-medium font-semibold text-on-surface">{{ report.teacher }}</span>
                        <span v-if="report.submitted" class="block truncate font-body-small text-body-small text-on-surface-variant">{{ report.difficulties || report.progress || report.proposals || 'Chỉ báo cáo theo lớp' }}</span>
                        <span v-else class="block font-body-small text-body-small italic text-on-surface-variant">Chưa nộp báo cáo tháng</span>
                    </span>
                    <UiBadge v-if="supportCount(report)" color="warning" class="shrink-0">{{ supportCount(report) }} lớp cần hỗ trợ</UiBadge>
                </button>
                <UiEmptyState v-if="!teaching.teacherReports.length" icon="summarize" title="Chưa có giáo viên trong phạm vi của bạn" />
            </section>
            <section class="rounded-xl border border-surface-variant bg-surface-container-lowest" data-academic-orders>
                <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Order học thuật</h3>
                    <Link v-if="teaching.ordersUrl" :href="teaching.ordersUrl" class="font-body-small text-body-small text-primary hover:underline">Xem tất cả</Link>
                </div>
                <div v-for="order in teaching.academicOrders" :key="order.id" class="border-b border-surface-variant/60 px-md py-sm last:border-0">
                    <p class="truncate font-body-medium text-body-medium text-on-surface" :title="order.title">{{ order.title }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">{{ order.teacher }} · {{ order.class ?? 'Không gắn lớp' }} · {{ order.status_label }}</p>
                </div>
                <UiEmptyState v-if="!teaching.academicOrders.length" icon="inventory" title="Không có order học thuật trong tháng" />
            </section>
        </div>

        <UiModal :show="!!opened" :title="opened ? `Báo cáo tháng · ${opened.teacher}` : ''" max-width="2xl" @close="opened = null">
            <div v-if="opened" class="space-y-md text-on-surface">
                <p class="font-caption text-caption text-on-surface-variant">Cập nhật {{ formatDate(opened.updated_at, 'H:i d/m/Y') }}</p>
                <dl class="space-y-sm">
                    <div v-for="[label, text] in [['Tiến độ giảng dạy', opened.progress], ['Khó khăn', opened.difficulties], ['Đề xuất', opened.proposals]]" :key="label">
                        <dt class="font-body-small text-body-small font-semibold text-on-surface-variant">{{ label }}</dt>
                        <dd class="whitespace-pre-line">{{ text || '—' }}</dd>
                    </div>
                </dl>
                <div v-for="c in opened.classes" :key="c.class" class="rounded-lg border border-surface-variant p-sm">
                    <p class="flex items-center justify-between gap-sm font-semibold">
                        {{ c.class }}
                        <UiBadge v-if="c.need_support" color="warning">Cần hỗ trợ</UiBadge>
                    </p>
                    <p v-if="c.attention" class="mt-xs whitespace-pre-line font-body-small text-body-small"><span class="font-semibold">Học sinh cần chú ý:</span> {{ c.attention }}</p>
                    <p v-if="c.solution" class="mt-xs whitespace-pre-line font-body-small text-body-small"><span class="font-semibold">Giải pháp:</span> {{ c.solution }}</p>
                    <p v-if="c.support_note" class="mt-xs whitespace-pre-line font-body-small text-body-small"><span class="font-semibold">Cần hỗ trợ:</span> {{ c.support_note }}</p>
                </div>
            </div>
            <template #footer><UiButton variant="secondary" @click="opened = null">Đóng</UiButton></template>
        </UiModal>
    </section>
</template>
