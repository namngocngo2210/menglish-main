<script setup>
/**
 * Cấu hình đơn giá giáo viên (mockup epic-7/cau-hinh-don-gia-giao-vien): đơn giá đang hiệu lực của mọi GV (tìm không dấu,
 * bấm dòng → modal chi tiết GV theo ?teacher_id=), lịch sử thay đổi đơn giá, khung đơn giá tham khảo theo cấp bậc.
 * "Cập nhật đơn giá" = thêm phiên bản mới có ngày hiệu lực (modal new-rate); "Thêm cấp bậc tham khảo" (modal new-rank).
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { urlWith } from '@/lib/url';
import { searchKey } from '../format';
import RateHistoryTable from './RateHistoryTable.vue';

defineOptions({ layout: { title: 'Cấu hình đơn giá giáo viên' } });

const props = defineProps({
    rates: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    history: { type: Object, required: true },
    teacherHistory: { type: Array, default: () => [] },
    selectedTeacher: { type: Object, default: null },
    selectedType: { type: String, default: null },
    teacherTypes: { type: Array, default: () => [] },
    defaultRate: { type: String, required: true },
    today: { type: String, required: true },
    todayDate: { type: String, required: true },
});

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);

const q = ref('');
const visible = (teacher) => {
    const key = searchKey(q.value);
    return !key || teacher.search.includes(key);
};
const detailUrl = (id) => route('payroll.config.teacher-rates', { teacher_id: id });

const detailOpen = ref(!!props.selectedTeacher);
const rateOpen = ref(false);
const rankOpen = ref(false);
const unit = ref('session');
const type = ref(props.selectedType ?? 'parttime');
const teacherOptions = computed(() => props.teachers.map((t) => ({ value: String(t.id), label: t.name + (t.employee_code ? ` — ${t.employee_code}` : '') })));
const unitOptions = [
    { value: 'session', label: 'Theo buổi dạy (VNĐ / buổi)' },
    { value: 'hour', label: 'Theo giờ (VNĐ / giờ)' },
];
</script>

<template>
    <div>
        <UiPageHeader title="Cấu hình đơn giá giáo viên" description="Quản lý và cập nhật định mức lương theo buổi / giờ cho từng giáo viên. Đổi giá = thêm phiên bản mới có ngày hiệu lực, buổi dạy cũ vẫn tính theo giá cũ.">
            <template #actions>
                <UiButton variant="secondary" icon="percent" :href="route('payroll.config.commission-tiers')">Cấu hình hoa hồng</UiButton>
                <UiButton icon="price_change" @click="rateOpen = true">Cập nhật đơn giá</UiButton>
            </template>
        </UiPageHeader>

        <UiAlert v-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>

        <div class="space-y-lg">
            <!-- Đơn giá đang hiệu lực của mọi GV — bấm dòng → modal chi tiết GV (?teacher_id=; đóng modal thì bỏ query). -->
            <UiDataTable min-width="640px">
                <template #header>
                    <h3 class="font-h3 text-h3 text-on-surface">Đơn giá đang hiệu lực ({{ today }})</h3>
                    <div class="w-full sm:w-72">
                        <UiInput v-model="q" type="search" icon="search" placeholder="Tìm tên hoặc mã nhân viên..." aria-label="Tìm giáo viên" />
                    </div>
                </template>
                <table>
                    <thead><tr><th>Giáo viên</th><th>Trạng thái</th><th class="text-right">Đơn giá</th><th>Hiệu lực từ</th><th class="text-right"><span class="sr-only">Thao tác</span></th></tr></thead>
                    <tbody>
                        <tr v-for="teacher in teachers" v-show="visible(teacher)" :key="teacher.id" :data-href="detailUrl(teacher.id)" :class="['cursor-pointer', selectedTeacher?.id === teacher.id ? 'bg-primary-fixed/40' : '']">
                            <td>
                                <Link :href="detailUrl(teacher.id)" class="flex items-center gap-sm hover:text-primary">
                                    <UiAvatar :name="teacher.name" size="sm" />
                                    <span>
                                        <span class="block font-semibold">{{ teacher.name }}</span>
                                        <span class="block font-caption text-caption text-on-surface-variant">Mã NV: {{ teacher.employee_code || '—' }}</span>
                                    </span>
                                </Link>
                            </td>
                            <td><UiBadge :color="teacher.is_active ? 'success' : 'neutral'" pill>{{ teacher.is_active ? 'Đang giảng dạy' : 'Ngừng hoạt động' }}</UiBadge></td>
                            <td>
                                <UiMoney v-if="teacher.current" :value="teacher.current.rate" :suffix="teacher.current.unit_label" />
                                <template v-else-if="teacher.profile_rate > 0">
                                    <UiMoney :value="teacher.profile_rate" suffix="đ/giờ" />
                                    <span class="block text-right font-caption text-caption text-on-surface-variant">theo hồ sơ nhân sự</span>
                                </template>
                                <span v-else class="block text-right font-caption text-caption text-on-surface-variant">Mặc định</span>
                            </td>
                            <td class="font-code text-code">{{ teacher.current?.effective_from ?? '—' }}</td>
                            <td class="text-right"><UiButton variant="secondary" size="sm" icon="visibility" :href="detailUrl(teacher.id)">Xem</UiButton></td>
                        </tr>
                        <tr v-if="!teachers.length">
                            <td colspan="5"><UiEmptyState icon="person_off" title="Chưa có nhân sự giảng dạy" /></td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <!-- Lịch sử thay đổi đơn giá của mọi GV (đơn giá mới nhập bằng nút "Cập nhật đơn giá" → modal) -->
            <RateHistoryTable :rows="history.data" with-teacher title="Lịch sử thay đổi đơn giá" :paginator="history" />

            <!-- Khung đơn giá theo cấp bậc (tham khảo khi đặt giá cho GV) -->
            <details class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <summary class="cursor-pointer px-lg py-md font-body-medium text-body-medium text-on-surface">
                    Khung đơn giá tham khảo theo cấp bậc ({{ rates.length }} bậc)
                </summary>
                <div class="space-y-md border-t border-surface-container p-lg">
                    <UiDataTable>
                        <table>
                            <thead><tr><th>Cấp bậc</th><th>Yêu cầu</th><th class="text-right">Lớp Giao tiếp</th><th class="text-right">Lớp IELTS / Cambridge</th></tr></thead>
                            <tbody>
                                <tr v-for="r in rates" :key="r.id">
                                    <td class="font-semibold">{{ r.rank_title }}</td>
                                    <td>{{ r.criteria }}</td>
                                    <td><UiMoney :value="r.communication_rate" suffix="đ" /></td>
                                    <td><UiMoney :value="r.ielts_rate" suffix="đ" /></td>
                                </tr>
                                <tr v-if="!rates.length">
                                    <td colspan="4"><UiEmptyState title="Chưa có khung đơn giá" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>

                    <div class="flex justify-end">
                        <UiButton variant="secondary" icon="add" @click="rankOpen = true">Thêm cấp bậc tham khảo</UiButton>
                    </div>
                </div>
            </details>
        </div>

        <!-- Chi tiết đơn giá 1 GV: mở sẵn khi URL có ?teacher_id=; đóng → bỏ teacher_id khỏi thanh địa chỉ. -->
        <UiModal v-if="selectedTeacher" :show="detailOpen" :title="`Đơn giá — ${selectedTeacher.name}`" max-width="4xl" :dismiss-url="urlWith({ teacher_id: null })" data-modal="teacher-rate-detail" @close="detailOpen = false">
            <div class="space-y-lg" :data-teacher-rate="selectedTeacher.id">
                <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                    <div>
                        <dt class="font-body-small text-body-small text-on-surface-variant">Mã nhân viên</dt>
                        <dd class="mt-xs font-code text-on-surface">{{ selectedTeacher.employee_code || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-body-small text-body-small text-on-surface-variant">Loại giáo viên</dt>
                        <dd class="mt-xs inline-flex items-center gap-xs font-body-medium text-body-medium text-on-surface">
                            <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">badge</span>
                            {{ selectedTeacher.type_label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-body-small text-body-small text-on-surface-variant">Mức lương đang áp dụng</dt>
                        <dd class="font-h3 text-h3 text-primary">
                            <template v-if="selectedTeacher.current">
                                {{ selectedTeacher.current.rate }}
                                <span class="block font-body-small text-body-small text-on-surface-variant">Hiệu lực từ: {{ selectedTeacher.current.effective_from }}</span>
                            </template>
                            <template v-else-if="selectedTeacher.profile_rate">
                                {{ selectedTeacher.profile_rate }} / giờ
                                <span class="block font-caption text-caption text-on-surface-variant">theo hồ sơ nhân sự</span>
                            </template>
                            <span v-else class="font-body-medium text-body-medium text-on-surface-variant">Chưa có đơn giá riêng (mặc định {{ defaultRate }} / giờ)</span>
                        </dd>
                    </div>
                </dl>

                <RateHistoryTable :rows="teacherHistory" title="Lịch sử thay đổi đơn giá" />
            </div>
            <template #footer>
                <UiButton icon="price_change" @click="rateOpen = true">Cập nhật đơn giá</UiButton>
            </template>
        </UiModal>

        <UiModal :show="rateOpen" :title="'Cập nhật đơn giá mới' + (selectedTeacher ? ` — ${selectedTeacher.name}` : '')" max-width="2xl" data-modal="new-rate" @close="rateOpen = false">
            <UiForm id="new-rate-form" :action="route('payroll.config.teacher-rates.personal.store')" method="post" preserve-state="errors" class="space-y-md">
                <input v-if="selectedTeacher" type="hidden" name="user_id" :value="selectedTeacher.id" />
                <UiSelect v-else name="user_id" label="Giáo viên" required placeholder="-- Chọn giáo viên --" :options="teacherOptions" />

                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <UiSelect v-model="type" name="teacher_type" label="Loại giáo viên" required :options="teacherTypes" />
                    <UiDate name="effective_from" label="Ngày hiệu lực từ" required :value="todayDate" hint="Áp dụng cho các ca dạy từ ngày này tới khi có đơn giá mới hơn." />
                    <UiSelect v-model="unit" name="rate_unit" label="Đơn vị tính" required :options="unitOptions" />
                    <UiField label="Mức đơn giá mới" name="hourly_rate" for="f_hourly_rate" required hint="* Đơn vị tính theo loại giáo viên: Part-time tính theo buổi.">
                        <div class="flex items-center gap-sm">
                            <input id="f_hourly_rate" type="number" name="hourly_rate" required min="1000" step="1000" placeholder="Nhập số tiền..." :class="['w-full rounded-lg border bg-surface-container-lowest px-md py-sm text-right font-mono text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50', page.props.errors?.hourly_rate ? 'border-error' : 'border-outline-variant']" />
                            <span class="whitespace-nowrap font-body-medium text-body-medium text-on-surface-variant">{{ unit === 'session' ? 'VNĐ / buổi' : 'VNĐ / giờ' }}</span>
                        </div>
                    </UiField>
                </div>
                <UiTextarea name="note" label="Ghi chú / Lý do thay đổi" rows="2" placeholder="Nhập ghi chú nếu có..." />

                <p v-show="type === 'fulltime'" class="rounded-lg bg-warning-container px-md py-sm font-body-small text-body-small text-on-warning-container">
                    GV Full-time hưởng lương cơ bản — đơn giá buổi chỉ dùng để đối soát, không cộng vào lương.
                </p>
                <p v-show="type === 'foreign'" class="rounded-lg bg-warning-container px-md py-sm font-body-small text-body-small text-on-warning-container">
                    Lương buổi có GVNN đang chờ BA chốt cách tính — Kế toán nhập tay trên phiếu lương.
                </p>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="rateOpen = false">Hủy bỏ</UiButton>
                <UiButton type="submit" form="new-rate-form" icon="save">Cập nhật đơn giá mới</UiButton>
            </template>
        </UiModal>

        <UiModal :show="rankOpen" title="Thêm cấp bậc tham khảo" max-width="xl" data-modal="new-rank" @close="rankOpen = false">
            <UiForm id="new-rank-form" :action="route('payroll.config.teacher-rates.store')" method="post" preserve-state="errors" class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiInput name="rank_title" label="Cấp bậc" required placeholder="VD: Senior IELTS Trainer" />
                <UiInput name="criteria" label="Yêu cầu chứng chỉ & kinh nghiệm" placeholder="IELTS 8.0+, 3 năm KN" />
                <UiInput type="number" name="communication_rate" label="Lớp Giao tiếp (VNĐ/giờ)" required step="10000" />
                <UiInput type="number" name="ielts_rate" label="Lớp IELTS / Cambridge (VNĐ/giờ)" required step="10000" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="rankOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="new-rank-form" icon="add">Thêm cấp bậc</UiButton>
            </template>
        </UiModal>
    </div>
</template>
