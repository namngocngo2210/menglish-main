<script setup>
/**
 * Cấu hình mốc hoa hồng & thưởng tái tục (mockup epic-7/cau-hinh-moc-hoa-hong-thuong-tai-tuc).
 * Tab "Hoa hồng tuyển sinh": mốc đang hiệu lực tại một ngày (xem lại theo ?as_of=), sửa = tạo phiên bản mới, ngừng áp dụng,
 * lịch sử phiên bản, thêm mốc (modal new-tier). Tab "Thưởng tái tục" (?tab=renewal): bảng % doanh thu lớp theo số HS nghỉ.
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Cấu hình mốc hoa hồng & thưởng tái tục' } });

const props = defineProps({
    tab: { type: String, default: 'commission' },
    asOf: { type: Object, required: true },
    tiers: { type: Array, default: () => [] },
    history: { type: Object, required: true },
    gateDays: { type: [Number, String], default: null },
    gateMilestones: { type: [Number, String], default: null },
    today: { type: String, required: true },
    tomorrow: { type: String, required: true },
    renewalRows: { type: Array, default: () => [] },
    renewalBeyond: { type: String, default: '0' },
});

const page = usePage();
const allErrors = computed(() => Object.values(page.props.errors ?? {}));

const tabOptions = [
    { value: route('payroll.config.commission-tiers'), label: 'Hoa hồng tuyển sinh' },
    { value: route('payroll.config.commission-tiers', { tab: 'renewal' }), label: 'Thưởng tái tục' },
];
const currentTabUrl = computed(() => (props.tab === 'renewal' ? tabOptions[1].value : tabOptions[0].value));
function changeTab(event) {
    router.visit(event.target.value);
}

const asOfDate = ref(props.asOf.date);
function viewAsOf() {
    router.get(route('payroll.config.commission-tiers'), { as_of: asOfDate.value }, { preserveScroll: true });
}

const newOpen = ref(false);
const editOpen = ref(false);
const editing = ref(null);
function edit(tier) {
    editing.value = {
        id: tier.id,
        tier_name: tier.tier_name,
        min_students: tier.min_students,
        max_students: tier.max_students,
        new_sale_percent: tier.new_sale_percent,
    };
    editOpen.value = true;
}

const rows = reactive(props.renewalRows.map((row) => ({ ...row })));
</script>

<template>
    <div>
        <UiPageHeader title="Cấu hình mốc hoa hồng & thưởng tái tục" description="Quản lý và thiết lập các mốc chính sách hoa hồng tuyển sinh và thưởng tái tục cho bảng lương.">
            <template #actions>
                <label class="flex items-center gap-sm">
                    <span class="sr-only">Loại cấu hình</span>
                    <UiSelect :options="tabOptions" :value="currentTabUrl" @change="changeTab" />
                </label>
                <UiButton v-if="tab === 'commission'" icon="add" @click="newOpen = true">Thêm mốc mới</UiButton>
                <UiButton variant="secondary" icon="price_change" :href="route('payroll.config.teacher-rates')">Đơn giá GV</UiButton>
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <UiAlert v-if="allErrors.length" type="error">
                <ul class="list-disc pl-5">
                    <li v-for="(error, i) in allErrors" :key="i">{{ error }}</li>
                </ul>
            </UiAlert>

            <UiAlert type="info">
                Thay đổi cấu hình sẽ được áp dụng cho các kỳ tính lương tiếp theo kể từ ngày hiệu lực. Hệ thống sẽ giữ nguyên lịch sử cấu hình cũ cho các kỳ đã quyết toán.
            </UiAlert>

            <template v-if="tab === 'commission'">
                <UiAlert type="warning" title="Hoa hồng tăng tiến theo mốc × học phí thu được — gate kép, hoãn không mất">
                    Mỗi học viên mang % của mốc chứa <strong>thứ tự chốt của HS đó trong tháng</strong> của người phụ trách. VD mốc 1–5: 4%, từ 6: 3% → 5 HS đầu tháng được 4%, HS thứ 6 trở đi 3%.
                    Hoa hồng = % × <strong>học phí thu được</strong> của khách mới (không tính tiền sách / Thu khác); hệ thống tự tính, thứ tự đếm lại từ 1 mỗi tháng.
                    Từng khách chỉ được trả khi <strong>đủ {{ gateDays }} ngày từ ngày chốt</strong> và
                    <strong>đủ {{ gateMilestones }}/3 mốc chăm sóc tháng đầu</strong>; chưa đủ thì hoãn sang kỳ sau (giữ % kỳ phát sinh).
                    Sửa một mốc tạo <strong>phiên bản mới</strong>; kỳ lương dùng mốc hiệu lực tại ngày cuối kỳ.
                </UiAlert>

                <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
                    <div class="space-y-lg lg:col-span-12">
                        <UiDataTable min-width="640px">
                            <template #header>
                                <h3 class="font-h3 text-h3 text-on-surface">Mốc đang hiệu lực ngày {{ asOf.label }}</h3>
                                <form method="GET" class="flex items-center gap-sm" @submit.prevent="viewAsOf">
                                    <UiInput v-model="asOfDate" type="date" name="as_of" inline-label="Xem tại ngày:" />
                                    <UiButton type="submit" variant="secondary" size="sm">Xem</UiButton>
                                </form>
                            </template>
                            <table>
                                <thead>
                                    <tr>
                                        <th class="text-right">Từ HS thứ</th>
                                        <th class="text-right">Đến HS thứ</th>
                                        <th class="text-center">Tỷ lệ (%)</th>
                                        <th>Ngày hiệu lực từ</th>
                                        <th class="text-right">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="tier in tiers" :key="tier.id">
                                        <td class="text-right font-code text-code">
                                            {{ tier.min_students }}
                                            <span class="block font-caption text-caption text-on-surface-variant">{{ tier.tier_name }}</span>
                                        </td>
                                        <td class="text-right font-code text-code">{{ tier.max_students !== null ? tier.max_students : 'Không giới hạn' }}</td>
                                        <td class="text-center"><UiBadge color="success">{{ tier.percent_label }}</UiBadge></td>
                                        <td class="font-code text-code">{{ tier.effective_from }}</td>
                                        <td class="text-right">
                                            <div v-if="tier.current" class="flex justify-end gap-xs">
                                                <UiButton variant="ghost" size="sm" icon="edit" aria-label="Sửa (tạo phiên bản mới)" @click="edit(tier)" />
                                                <UiForm :action="route('payroll.config.commission-tiers.destroy', tier.id)" method="delete" :confirm="`Ngừng áp dụng mốc ${tier.tier_name} từ hôm nay?`" confirm-label="Ngừng áp dụng" danger>
                                                    <UiButton type="submit" variant="danger-text" size="sm" icon="block" aria-label="Ngừng áp dụng" />
                                                </UiForm>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="!tiers.length">
                                        <td colspan="5"><UiEmptyState icon="percent" title="Chưa có mốc hoa hồng hiệu lực tại ngày này" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </UiDataTable>

                        <UiDataTable min-width="760px">
                            <template #header>
                                <h3 class="font-h3 text-h3 text-on-surface">Lịch sử các phiên bản</h3>
                            </template>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Bậc</th>
                                        <th class="text-right">HS thứ (trong tháng chốt)</th>
                                        <th class="text-center">Tỷ lệ (%)</th>
                                        <th>Hiệu lực</th>
                                        <th>Người tạo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="version in history.data" :key="version.id">
                                        <td>
                                            <span class="font-semibold">{{ version.tier_name }}</span>
                                            <span v-if="version.replaces" class="block font-caption text-caption text-on-surface-variant">thay cho “{{ version.replaces }}”</span>
                                        </td>
                                        <td class="text-right font-code text-code">{{ version.range }}</td>
                                        <td class="text-center font-code text-code">{{ version.percent_label }}</td>
                                        <td class="whitespace-nowrap font-code text-code">
                                            {{ version.effective_from }} → {{ version.effective_to }}
                                            <UiBadge v-if="version.current" color="success">Đang áp dụng</UiBadge>
                                        </td>
                                        <td>{{ version.creator }}</td>
                                    </tr>
                                    <tr v-if="!history.data.length">
                                        <td colspan="5"><UiEmptyState icon="history" title="Chưa có lịch sử" /></td>
                                    </tr>
                                </tbody>
                            </table>
                            <template #footer><UiPagination :paginator="history" /></template>
                        </UiDataTable>
                    </div>
                </div>

                <UiModal :show="newOpen" title="Thêm mốc cấu hình mới" data-modal="new-tier" @close="newOpen = false">
                    <UiForm id="new-tier-form" :action="route('payroll.config.commission-tiers.store')" method="post" preserve-state="errors" class="space-y-md">
                        <div class="grid grid-cols-2 gap-md">
                            <UiInput type="number" name="min_students" label="Từ HS thứ" required min="0" step="1" placeholder="VD: 6" />
                            <UiInput type="number" name="max_students" label="Đến HS thứ" min="0" step="1" placeholder="Để trống = không giới hạn" />
                        </div>
                        <UiInput id="f_new_sale_percent" type="number" name="new_sale_percent" label="Tỷ lệ (%)" required suffix="%" min="0" max="100" step="0.1" placeholder="0.0" class="text-right font-mono" />
                        <UiDate name="effective_from" label="Hiệu lực từ ngày" required :value="today" />
                        <UiInput name="tier_name" label="Tên bậc (tuỳ chọn)" placeholder="Bỏ trống = tự đặt theo ngưỡng" />
                        <p class="font-caption text-caption text-on-surface-variant">Không có hoa hồng tái tục cho sale (A6). Thưởng tái tục của GV phụ trách lớp ở tab "Thưởng tái tục".</p>
                    </UiForm>
                    <template #footer>
                        <UiButton variant="secondary" @click="newOpen = false">Hủy</UiButton>
                        <UiButton type="submit" form="new-tier-form" icon="save">Lưu cấu hình</UiButton>
                    </template>
                </UiModal>

                <UiModal :show="editOpen" title="Tạo phiên bản mới của mốc hoa hồng" data-modal="edit-tier" @close="editOpen = false">
                    <UiForm v-if="editing" id="edit-tier-form" :key="editing.id" :action="route('payroll.config.commission-tiers.update', editing.id)" method="put" preserve-state="errors" class="space-y-md">
                        <div class="space-y-md">
                            <div class="grid grid-cols-2 gap-md">
                                <UiField label="Từ HS thứ" required>
                                    <input v-model="editing.min_students" type="number" name="min_students" min="0" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code" />
                                </UiField>
                                <UiField label="Đến HS thứ">
                                    <input v-model="editing.max_students" type="number" name="max_students" min="0" placeholder="Để trống = Max" class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code" />
                                </UiField>
                                <UiField label="Tỷ lệ (%)" required>
                                    <input v-model="editing.new_sale_percent" type="number" name="new_sale_percent" min="0" max="100" step="0.1" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code" />
                                </UiField>
                                <UiField label="Tên bậc">
                                    <input v-model="editing.tier_name" type="text" name="tier_name" class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base" />
                                </UiField>
                            </div>
                            <UiDate name="effective_from" label="Phiên bản mới hiệu lực từ" required :value="tomorrow" hint="Phiên bản cũ tự đóng vào ngày trước đó." />
                        </div>
                    </UiForm>
                    <template #footer>
                        <UiButton variant="secondary" @click="editOpen = false">Hủy</UiButton>
                        <UiButton type="submit" form="edit-tier-form" icon="save">Lưu phiên bản mới</UiButton>
                    </template>
                </UiModal>
            </template>

            <template v-else>
                <!-- Thưởng tái tục (A6): % doanh thu lớp theo số HS nghỉ trong kỳ, cho GV Full-time phụ trách lớp -->
                <UiForm :action="route('payroll.config.renewal.store')" method="post" preserve-state="errors">
                    <UiDataTable class="shadow-sm">
                        <template #header>
                            <div>
                                <h3 class="font-h3 text-h3 text-on-surface">Thưởng tái tục — % doanh thu lớp theo số HS nghỉ trong kỳ</h3>
                                <p class="font-body-small text-body-small text-on-surface-variant">BA đã chốt: giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%. Các mốc khác gắn <strong>chờ BA</strong> tới khi có bảng đầy đủ.</p>
                            </div>
                            <UiButton variant="secondary" size="sm" icon="add" @click="rows.push({ quits: rows.length, percent: 0, pending: true })">Thêm mốc mới</UiButton>
                        </template>
                        <table>
                            <thead>
                                <tr>
                                    <th>Số HS nghỉ trong lớp</th>
                                    <th>Tỷ lệ (% doanh thu lớp)</th>
                                    <th>Chờ BA</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in rows" :key="i">
                                    <td><input v-model="row.quits" type="number" min="0" :name="`renewal[${i}][quits]`" aria-label="Số HS nghỉ" class="w-28 rounded-lg border border-outline-variant px-sm py-xs font-code text-code" /></td>
                                    <td>
                                        <span class="relative inline-block">
                                            <input v-model="row.percent" type="number" min="0" max="100" step="0.05" :name="`renewal[${i}][percent]`" aria-label="Tỷ lệ %" class="w-32 rounded-lg border border-outline-variant px-sm py-xs pr-lg text-right font-code text-code" />
                                            <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant">%</span>
                                        </span>
                                    </td>
                                    <td>
                                        <input type="hidden" :name="`renewal[${i}][pending]`" :value="row.pending ? 1 : 0" />
                                        <label class="inline-flex items-center gap-xs"><input v-model="row.pending" type="checkbox" class="rounded border-outline-variant text-primary-container" /><span v-show="row.pending" class="font-caption text-caption font-semibold text-warning">chờ BA</span></label>
                                    </td>
                                    <td class="text-right"><UiButton variant="danger-text" size="sm" icon="delete" aria-label="Xoá mốc" @click="rows.splice(i, 1)" /></td>
                                </tr>
                            </tbody>
                        </table>
                        <template #footer>
                            <div class="flex flex-wrap items-center justify-between gap-md p-md">
                                <label class="flex items-center gap-sm font-body-medium text-body-medium">
                                    Nghỉ nhiều hơn các mốc trên:
                                    <input type="number" name="renewal_beyond_percent" min="0" max="100" step="0.05" :value="renewalBeyond" class="w-28 rounded-lg border border-outline-variant px-sm py-xs text-right font-code text-code" /> % <span class="font-caption text-caption text-warning">(chờ BA)</span>
                                </label>
                                <UiButton type="submit" icon="save">Lưu cấu hình</UiButton>
                            </div>
                        </template>
                    </UiDataTable>
                </UiForm>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Thưởng tái tục = Σ theo lớp GV Full-time là GV chính: % theo số HS nghỉ trong kỳ × doanh thu lớp (phiếu thu đã duyệt trong kỳ, trừ tiền nhận chuyển nhượng).
                    Bảng áp dụng cho lần <strong>tính / tính lại</strong> kỳ lương tiếp theo; kỳ đã duyệt không đổi.
                </p>
            </template>
        </div>
    </div>
</template>
