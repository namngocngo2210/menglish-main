<script setup>
// Commission versions and renewal percentages for academic staff.
import { computed, ref } from 'vue';
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
    renewalSamePercent: { type: Number, required: true },
    renewalIncreasePercent: { type: Number, required: true },
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
                    Mỗi học viên mang % của mốc chứa <strong>thứ tự chốt của HS đó trong tháng</strong> của người phụ trách. Mặc định HS thứ 1–5: 3%, 6–10: 4%, từ 11: 5% (cấu hình được). Ngày chốt là ngày đóng học phí thành công đầu tiên (phiếu thu được duyệt), không phải ngày chuyển đổi CRM.
                    Hoa hồng = % × <strong>học phí thu được</strong> của khách mới (không tính tiền sách / Thu khác); hệ thống tự tính, thứ tự đếm lại từ 1 mỗi tháng.
                    Từng khách chỉ được trả khi <strong>đủ {{ gateDays }} ngày từ ngày chốt</strong> và
                    <strong>đủ {{ gateMilestones }}/3 mốc chăm sóc tháng đầu</strong>; chưa đủ thì hoãn sang kỳ sau (giữ % tại ngày chốt).
                    Sửa một mốc tạo <strong>phiên bản mới</strong>; mọi đợt đóng của học viên dùng bảng mốc hiệu lực tại ngày chốt. Không thu hồi hoa hồng đã trả khi hoàn phí / hủy hóa đơn; khoản chưa trả liên quan không được chi.
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
                <UiForm :action="route('payroll.config.renewal.store')" method="post" preserve-state="errors" class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Thưởng tái tục — % doanh thu lớp</h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">Chỉ áp dụng cho Học vụ, trên các lớp mình phụ trách có HS đầu kỳ. Có bất kỳ HS đầu kỳ nào nghỉ trong kỳ → 0%. Không có HS nghỉ: giữ nguyên số HS → tỷ lệ giữ nguyên; có HS mới vào trong kỳ còn học cuối kỳ, số HS cuối kỳ tăng so với đầu kỳ → tỷ lệ tăng HS. Tỷ lệ tăng số HS phải lớn hơn tỷ lệ giữ nguyên số HS.</p>
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        <UiInput id="renewal_same_percent" type="number" name="renewal_same_percent" label="Giữ nguyên số HS (%)" min="0" max="100" step="any" required :value="renewalSamePercent" />
                        <UiInput id="renewal_increase_percent" type="number" name="renewal_increase_percent" label="Tăng số HS (%)" min="0" max="100" step="any" required :value="renewalIncreasePercent" />
                    </div>
                    <UiButton type="submit" icon="save">Lưu cấu hình</UiButton>
                </UiForm>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Thưởng tái tục = tổng thưởng các lớp Học vụ phụ trách: tỷ lệ áp dụng × doanh thu lớp (phiếu thu đã duyệt trong kỳ, trừ tiền nhận chuyển nhượng).
                    Tỷ lệ áp dụng khi <strong>tính / tính lại</strong> kỳ lương đang mở; kỳ đã chốt / đã gửi GV xem / đã trả không đổi.
                </p>
            </template>
        </div>
    </div>
</template>
