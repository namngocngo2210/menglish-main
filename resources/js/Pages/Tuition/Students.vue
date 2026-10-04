<script setup>
/**
 * Công nợ học viên (sổ toàn bộ khoản học phí) + nhóm quá hạn / sắp đến hạn thu gọn.
 * Bấm dòng → modal lịch sử thu học phí của học viên (mọi đợt nộp, còn nợ sau từng đợt).
 * "Lập phiếu thu" ở từng dòng → modal 4xl (học viên + khoản nợ chọn sẵn); lưu phiếu xong trang tự có dữ liệu mới.
 * Nút "Nhập Excel" / "Lập phiếu thu" nằm ở thanh tab workspace Học phí.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { currentQuery } from '@/lib/url';
import { route } from '@/lib/route';
import DueGroups from './Partials/DueGroups.vue';

defineOptions({ layout: { title: 'Công nợ học viên' } });

const props = defineProps({
    tuitions: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    seriousOverdue: { type: Array, default: () => [] },
    newOverdue: { type: Array, default: () => [] },
    upcoming: { type: Object, required: true },
    overdueCount: { type: Number, default: 0 },
    type: { type: String, default: 'all' },
    seriousDays: { type: Number, default: 7 },
    upcomingDays: { type: Number, default: 14 },
});

const statusOptions = [
    { value: 'paid', label: 'Đã hoàn thành' },
    { value: 'partial', label: 'Đang nợ (Đã cọc)' },
    { value: 'overdue', label: 'Quá hạn' },
    { value: 'unpaid', label: 'Chưa nộp' },
];
const autoSubmit = (event) => event.target.form?.requestSubmit();

// Lọc trạng thái công nợ ngay trên bảng: giữ chi nhánh / lớp / tìm kiếm, cuộn về bảng.
function filterStatus(event) {
    const query = currentQuery();
    const data = {};
    for (const key of ['branch_id', 'class_id', 'search']) if (query.get(key)) data[key] = query.get(key);
    if (event.target.value) data.status = event.target.value;
    router.get(route('tuition.students') + '#all-tuitions', data, { preserveScroll: true });
}
const dueGroupProps = computed(() => ({
    seriousOverdue: props.seriousOverdue,
    newOverdue: props.newOverdue,
    upcoming: props.upcoming,
    overdueCount: props.overdueCount,
    type: props.type,
    seriousDays: props.seriousDays,
    upcomingDays: props.upcomingDays,
}));
</script>

<template>
    <UiPageHeader title="Công nợ học viên" description="Sổ toàn bộ khoản học phí: đã thu, còn nợ, hạn nộp và trạng thái của từng học viên.">
        <template #actions>
            <UiButton variant="secondary" icon="notifications_active" :href="route('tuition.overdue')">Xử lý quá hạn &amp; nhắc phí</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :action="route('tuition.students')" search="search" placeholder="Họ tên hoặc mã học sinh...">
        <UiSelect name="branch_id" label="Chi nhánh" placeholder="Tất cả chi nhánh" :options="branches" @change="autoSubmit" />
        <UiSelect name="class_id" label="Lớp học" placeholder="Tất cả lớp học" :options="classes" @change="autoSubmit" />
    </UiFilterBar>

    <div class="mb-xl grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
        <UiStatCard label="Tổng học phí phải thu" :value="formatMoney(stats.final)" icon="request_quote" />
        <UiStatCard label="Đã thực thu" :value="formatMoney(stats.paid)" tone="success" icon="savings" />
        <UiStatCard label="Công nợ còn lại" :value="formatMoney(stats.debt)" tone="warning" icon="account_balance_wallet" />
        <UiStatCard label="Học viên quá hạn" :value="stats.overdue + ' học viên'" tone="error" icon="report" />
    </div>

    <div id="tuition-list" class="space-y-xl">
        <!-- Toàn bộ khoản học phí (sổ công nợ) -->
        <section id="all-tuitions" class="space-y-md">
            <div class="flex flex-wrap items-center gap-sm border-l-4 border-outline pl-sm">
                <h2 class="font-h2 text-h2 text-on-surface">Toàn bộ khoản học phí</h2>
                <form method="GET" :action="route('tuition.students')" class="ml-auto flex items-center gap-sm" @submit.prevent>
                    <UiSelect name="status" aria-label="Trạng thái công nợ" placeholder="Tất cả trạng thái" :options="statusOptions" @change="filterStatus" />
                </form>
            </div>
            <UiDataTable min-width="900px">
                <table>
                    <thead>
                        <tr>
                            <th>Học viên</th>
                            <th>Lớp học · Cơ sở</th>
                            <th>Khoản thu</th>
                            <th class="text-right">Tổng học phí</th>
                            <th class="text-right">Đã nộp</th>
                            <th class="text-right">Còn nợ</th>
                            <th>Hạn nộp</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in tuitions.data" :key="t.id" :data-href="t.student ? route('tuition.students.payments', t.student.id) : null" data-modal="2xl" class="cursor-pointer" title="Xem lịch sử thu học phí">
                            <td class="whitespace-nowrap">
                                <a v-if="t.student" :href="route('tuition.students.payments', t.student.id)" class="font-body-medium hover:text-primary" @click.prevent="openRemoteModal(route('tuition.students.payments', t.student.id), { size: '2xl' })">{{ t.student.name }}</a>
                                <div class="font-code text-caption text-on-surface-variant"><UiCode :value="t.student?.code" /> · {{ t.student?.phone }}</div>
                            </td>
                            <td>
                                <div class="text-primary">{{ t.class_name ?? 'Chưa gán lớp' }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">{{ t.branch_name ?? '—' }}</div>
                            </td>
                            <td>{{ t.fee_label }}</td>
                            <td class="whitespace-nowrap text-right font-code">{{ formatMoney(t.final_amount) }}</td>
                            <td class="whitespace-nowrap text-right font-code text-tertiary">{{ formatMoney(t.paid_amount) }}</td>
                            <td :class="['whitespace-nowrap text-right font-code', t.debt_amount > 0 ? 'text-error' : 'text-on-surface-variant']">{{ formatMoney(t.debt_amount) }}</td>
                            <td class="whitespace-nowrap font-code text-code">{{ t.due_date ?? '—' }}</td>
                            <td>
                                <template v-if="t.days_overdue > 0">
                                    <UiBadge :color="t.days_overdue >= seriousDays ? 'error' : 'warning'">Quá hạn {{ t.days_overdue }} ngày</UiBadge>
                                    <div v-if="t.days_overdue >= seriousDays" class="mt-xs font-caption text-caption font-semibold text-error">Bắt buộc liên hệ trực tiếp</div>
                                </template>
                                <UiBadge v-else :color="t.status_color">{{ t.status_label }}</UiBadge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <template v-if="t.debt_amount > 0">
                                    <UiButton v-if="can('tuition.create')" size="sm" variant="ghost" icon="payments" :href="route('tuition.receipts.create', { tuition_id: t.id })" modal="4xl" title="Lập phiếu thu" aria-label="Lập phiếu thu" />
                                </template>
                                <span v-else class="inline-flex items-center text-tertiary" title="Đã tất toán">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">verified</span><span class="sr-only">Đã tất toán</span>
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!tuitions.data.length">
                            <td colspan="9"><UiEmptyState icon="payments" title="Không tìm thấy khoản học phí nào" /></td>
                        </tr>
                    </tbody>
                </table>
                <template #footer>
                    <UiPagination :paginator="tuitions" unit="khoản học phí" />
                </template>
            </UiDataTable>
        </section>

        <!-- Nhóm quá hạn / sắp đến hạn: thu gọn, việc đôn đốc chính ở màn "Quá hạn & Nhắc phí". -->
        <details class="group rounded-xl border border-outline-variant bg-surface-container-lowest">
            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-sm p-md">
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant transition group-open:rotate-90" aria-hidden="true">chevron_right</span>
                <span class="font-body-medium text-body-medium text-on-surface">Khoản quá hạn &amp; sắp đến hạn</span>
                <UiBadge color="error" pill :dot="false">{{ overdueCount }} quá hạn</UiBadge>
                <UiBadge color="warning" pill :dot="false">{{ upcoming.total }} sắp đến hạn</UiBadge>
            </summary>
            <div class="space-y-xl border-t border-outline-variant p-md">
                <DueGroups v-bind="dueGroupProps" />
            </div>
        </details>
    </div>
</template>
