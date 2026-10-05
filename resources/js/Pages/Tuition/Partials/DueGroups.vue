<script setup>
/**
 * Nhóm "Quá hạn" (nghiêm trọng / mới) + nhóm "Sắp đến hạn" — dùng chung cho Công nợ học viên và Quá hạn & Nhắc phí.
 * Props từ TuitionController::dueGroupProps.
 */
import { computed, reactive } from 'vue';
import { formatDate } from '@/lib/format';
import DuePager from './DuePager.vue';

const props = defineProps({
    seriousOverdue: { type: Array, default: () => [] },
    newOverdue: { type: Array, default: () => [] },
    upcoming: { type: Object, required: true },
    overdueCount: { type: Number, default: 0 },
    type: { type: String, default: 'all' },
    seriousDays: { type: Number, default: 7 },
    upcomingDays: { type: Number, default: 14 },
});

const groups = computed(() => [
    { rows: props.seriousOverdue, title: `Quá hạn nghiêm trọng (≥ ${props.seriousDays} ngày) — bắt buộc liên hệ trực tiếp`, icon: 'report', tone: 'text-error', serious: true },
    { rows: props.newOverdue, title: `Mới quá hạn (1–${Math.max(1, props.seriousDays - 1)} ngày)`, icon: 'info', tone: 'text-warning', serious: false },
]);

// Ô "Xác nhận đã liên hệ" / "Báo cáo Admin" mở theo từng khoản (thay x-data { contact, report }).
const panel = reactive({});
const toggle = (id, which) => (panel[id] = panel[id] === which ? null : which);
const nowLocal = () => {
    const now = new Date();
    return `${formatDate(now, 'Y-m-d')}T${formatDate(now, 'H:i')}`;
};
</script>

<template>
    <!-- NHÓM QUÁ HẠN -->
    <section v-if="type !== 'upcoming'" class="space-y-md">
        <div class="flex items-center gap-sm border-l-4 border-error pl-sm">
            <h2 class="font-h2 text-h2 text-on-surface">Nhóm "Quá hạn"</h2>
            <UiBadge color="error" pill :dot="false">{{ overdueCount }} trường hợp</UiBadge>
        </div>

        <div v-for="group in groups" :key="group.title" class="space-y-sm">
            <h3 :class="['flex items-center gap-xs font-label text-label uppercase', group.tone]">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ group.icon }}</span>{{ group.title }}
                <span class="text-on-surface-variant">· {{ group.rows.length }}</span>
            </h3>

            <article
                v-for="ot in group.rows"
                :key="ot.id"
                :class="['grid grid-cols-1 overflow-hidden rounded-xl border bg-surface-container-lowest lg:grid-cols-[260px_1fr_240px]', group.serious ? 'border-error/40' : 'border-outline-variant']"
            >
                <div class="space-y-sm bg-surface-container-low p-md">
                    <div class="flex items-center gap-sm">
                        <UiAvatar :name="ot.student?.name" />
                        <div class="min-w-0">
                            <p class="truncate font-body-medium text-body-medium text-on-surface">{{ ot.student?.name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">MS: <UiCode :value="ot.student?.code" /> · {{ ot.student?.phone }}</p>
                        </div>
                    </div>
                    <div v-for="line in ot.session_lines" :key="line.label" class="flex justify-between font-body-small text-body-small">
                        <span class="text-on-surface-variant">{{ line.label }}:</span><span class="font-code">{{ line.value }}</span>
                    </div>
                    <p v-if="!ot.session_lines.length" class="font-caption text-caption text-on-surface-variant">Chưa có dữ liệu số buổi.</p>
                </div>

                <div class="grid grid-cols-2 gap-md p-md md:grid-cols-4">
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Lớp học</p>
                        <p class="font-body-medium text-body-medium">{{ ot.class_label }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ ot.branch_name }}</p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Khoản thu</p>
                        <p class="font-body-medium text-body-medium">{{ ot.fee_label }}</p>
                        <p class="font-caption text-caption text-error">Còn nợ {{ formatMoney(ot.debt_amount) }}</p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Hạn thanh toán</p>
                        <p class="font-code text-code text-error">{{ ot.due_date }}</p>
                    </div>
                    <div class="space-y-xs">
                        <p class="font-label text-label uppercase text-on-surface-variant">Trạng thái</p>
                        <template v-if="ot.last_contact">
                            <UiBadge color="info">Đã liên hệ — chờ thu</UiBadge>
                            <p class="font-caption text-caption text-on-surface-variant" :title="ot.last_contact.note">
                                {{ ot.last_contact.at }} · {{ ot.last_contact.user_name }}
                                <template v-if="ot.last_contact.note_short"> — {{ ot.last_contact.note_short }} </template>
                            </p>
                        </template>
                        <UiBadge :color="group.serious ? 'error' : 'warning'">Quá hạn {{ ot.days_overdue }} ngày</UiBadge>
                        <!-- SLA học phí: quá hạn ≥ N ngày → badge đỏ + bắt buộc gọi điện trực tiếp (không khoá lịch học). -->
                        <UiBadge v-if="group.serious && !ot.last_contact" color="error" :dot="false">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">call</span>Bắt buộc liên hệ trực tiếp
                        </UiBadge>
                    </div>
                </div>

                <!-- Nút chính "Lập phiếu thu", nút phụ "Gửi nhắc nợ"; thao tác còn lại trong menu "⋯". -->
                <div class="flex flex-col justify-center gap-sm bg-surface-container-low p-md">
                    <UiButton v-if="can('tuition.create')" size="sm" variant="secondary" icon="payments" :href="route('tuition.receipts.create', { tuition_id: ot.id })" modal="4xl">Lập phiếu thu</UiButton>
                    <div v-if="can('tuition.mark_contacted') || (!ot.last_report_date && can('tuition.report_overdue'))" class="flex items-center gap-sm">
                        <UiForm v-if="can('tuition.mark_contacted')" :action="route('tuition.overdue.remind', ot.id)" method="post" class="flex-1">
                            <UiButton type="submit" size="sm" variant="secondary" icon="notifications_active" class="w-full">Gửi nhắc nợ</UiButton>
                        </UiForm>
                        <UiDropdown align="right" width="56">
                            <template #trigger>
                                <UiButton size="sm" variant="ghost" icon="more_horiz" aria-label="Thao tác khác" title="Thao tác khác" />
                            </template>
                            <template #content>
                                <button v-if="can('tuition.mark_contacted')" type="button" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small hover:bg-surface-container-low" @click="toggle(ot.id, 'contact')">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">call</span>Xác nhận đã liên hệ
                                </button>
                                <button v-if="!ot.last_report_date && can('tuition.report_overdue')" type="button" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small hover:bg-surface-container-low" @click="toggle(ot.id, 'report')">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">flag</span>Báo cáo Admin
                                </button>
                            </template>
                        </UiDropdown>
                    </div>
                    <UiForm v-if="can('tuition.mark_contacted') && panel[ot.id] === 'contact'" :action="route('tuition.overdue.contacted', ot.id)" method="post" class="space-y-xs" @success="panel[ot.id] = null">
                        <input type="datetime-local" name="contacted_at" :value="nowLocal()" :max="nowLocal()" aria-label="Thời gian liên hệ" class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small" />
                        <textarea name="note" rows="2" placeholder="Nội dung trao đổi, hẹn ngày đóng..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                        <UiButton type="submit" size="sm" variant="secondary" icon="check" class="w-full">Lưu liên hệ</UiButton>
                    </UiForm>
                    <p v-if="ot.last_report_date" class="flex items-center gap-xs rounded-lg bg-surface-container px-sm py-xs font-body-small text-body-small italic text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>
                        Đã báo cáo Admin — {{ ot.last_report_date }}
                    </p>
                    <UiForm v-else-if="can('tuition.report_overdue') && panel[ot.id] === 'report'" :action="route('tuition.overdue.report-admin', ot.id)" method="post" class="space-y-xs" @success="panel[ot.id] = null">
                        <textarea name="note" rows="2" aria-label="Báo cáo tình hình nhắc phí" placeholder="Tình hình liên hệ, đề xuất xử lý..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                        <UiButton type="submit" size="sm" variant="secondary" icon="send" class="w-full">Gửi báo cáo</UiButton>
                    </UiForm>
                </div>
            </article>

            <p v-if="!group.rows.length" class="rounded-xl border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Không có học viên trong nhóm này.</p>
        </div>
    </section>

    <!-- NHÓM SẮP ĐẾN HẠN -->
    <section v-if="type !== 'overdue'" class="space-y-md">
        <div class="flex items-center gap-sm border-l-4 border-secondary pl-sm">
            <h2 class="font-h2 text-h2 text-on-surface">Nhóm "Sắp đến hạn"</h2>
            <span class="font-body-medium text-on-surface-variant">(Trong {{ upcomingDays }} ngày tới)</span>
            <span class="ml-auto font-caption text-caption text-on-surface-variant">Hiển thị {{ upcoming.total }} kết quả</span>
        </div>
        <UiDataTable min-width="960px">
            <table>
                <thead>
                    <tr>
                        <th>Học sinh</th>
                        <th>Lớp</th>
                        <th>Khoản thu</th>
                        <th>Hạn thanh toán</th>
                        <th>Chi tiết buổi học</th>
                        <th>Số ngày còn lại</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ot in upcoming.data" :key="ot.id">
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="ot.student?.name" size="sm" />
                                <div>
                                    <div class="font-body-medium">{{ ot.student?.name }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">MS: <UiCode :value="ot.student?.code" /></div>
                                </div>
                            </div>
                        </td>
                        <td>{{ ot.class_label }}</td>
                        <td>
                            <div>{{ ot.fee_label }}</div>
                            <div class="font-caption text-caption text-on-surface-variant">Còn nợ {{ formatMoney(ot.debt_amount) }}</div>
                        </td>
                        <td class="font-code text-code">{{ ot.due_date }}</td>
                        <td class="font-caption text-caption">
                            <div v-for="line in ot.session_lines" :key="line.label">{{ line.label }}: {{ line.value }}</div>
                            <span v-if="!ot.session_lines.length" class="text-on-surface-variant">—</span>
                        </td>
                        <td>
                            <UiBadge v-if="ot.last_contact" color="info">Đã liên hệ — chờ thu</UiBadge>
                            <UiBadge v-else-if="ot.days_overdue === 0" color="warning">Đến hạn hôm nay</UiBadge>
                            <UiBadge v-else color="success">{{ Math.abs(ot.days_overdue) }} ngày</UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <UiButton v-if="can('tuition.create')" size="sm" variant="ghost" icon="receipt_long" :href="route('tuition.receipts.create', { tuition_id: ot.id })" modal="4xl">Lập phiếu thu</UiButton>
                            <UiForm v-if="can('tuition.mark_contacted')" :action="route('tuition.overdue.upcoming-remind', ot.id)" method="post" class="inline">
                                <UiButton type="submit" size="sm" variant="ghost" icon="notifications_active" title="Gửi nhắc hạn học phí" aria-label="Gửi nhắc hạn học phí" />
                            </UiForm>
                        </td>
                    </tr>
                    <tr v-if="!upcoming.data.length">
                        <td colspan="7"><UiEmptyState icon="event_available" title="Không có khoản sắp đến hạn" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
        <div v-if="upcoming.last_page > 1" class="flex justify-end"><DuePager :paginator="upcoming" /></div>
    </section>
</template>
