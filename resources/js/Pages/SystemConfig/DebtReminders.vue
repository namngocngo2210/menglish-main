<script setup>
/**
 * Cấu hình nhắc nợ (mockup: epic-5/cau-hinh-nhac-no): thiết lập nhanh 3 mốc, danh sách mốc nhắc chi tiết (mỗi mốc 1 form),
 * "Thêm mốc nhắc" trong hộp thoại. Mỗi form dùng error bag riêng → lỗi validate chỉ hiện ở đúng form vừa gửi.
 */
import { reactive, ref } from 'vue';

defineOptions({ layout: { title: 'Cấu hình nhắc nợ' } });

const props = defineProps({
    rules: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    mustContactDays: { type: Number, required: true },
    firstDays: { type: Number, required: true },
    repeatDays: { type: Number, required: true },
});

const timingOptions = [
    { value: 'before', label: 'Trước hạn đóng' },
    { value: 'due', label: 'Đúng ngày đến hạn' },
    { value: 'after', label: 'Sau hạn (quá hạn)' },
];
const control = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50';

const repeat = ref(props.repeatDays);
const timing = reactive(Object.fromEntries(props.rules.map((r) => [r.milestone_key, r.timing])));
const newOpen = ref(false);
const newTiming = ref('before');

function onNewSaved() {
    newOpen.value = false;
    newTiming.value = 'before';
}
</script>

<template>
    <div class="space-y-lg">
        <UiPageHeader title="Cấu hình Nhắc nợ" description="Tối ưu hóa thời gian và tần suất gửi thông báo nhắc học phí cho phụ huynh, giúp cải thiện tỷ lệ thanh toán đúng hạn và duy trì sự chuyên nghiệp trong khâu vận hành." />

        <!-- Thiết lập nhanh theo mockup: 3 mốc + kênh thông báo -->
        <UiForm :action="route('system-config.debt-reminders.settings')" method="post" error-bag="debtSettings" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm" #default="{ errors }">
            <div class="grid grid-cols-1 gap-lg md:grid-cols-3">
                <div class="space-y-xs">
                    <label for="first_days" class="block font-body-medium text-body-medium text-on-surface">Mốc nhắc nợ trước hạn</label>
                    <p class="font-caption text-caption text-on-surface-variant">Số ngày trước ngày đáo hạn để hệ thống gửi thông báo nhắc nhở đầu tiên.</p>
                    <div class="w-36">
                        <UiInput id="first_days" type="number" name="first_days" min="1" max="60" :value="firstDays" required suffix="Ngày" class="font-code text-code" />
                    </div>
                    <p v-if="errors.first_days" class="font-caption text-caption text-error">{{ errors.first_days }}</p>
                </div>
                <div class="space-y-xs">
                    <label for="repeat_days" class="block font-body-medium text-body-medium text-on-surface">Mốc nhắc lại</label>
                    <p class="font-caption text-caption text-on-surface-variant">Gửi thông báo lần 2 sát ngày đáo hạn để tăng độ nhận diện.</p>
                    <div class="flex items-center gap-sm">
                        <input id="repeat_days" v-model.number="repeat" type="number" name="repeat_days" min="1" max="3" required :class="['w-24 rounded-lg font-code text-code', repeat < 1 || repeat > 3 ? 'border-error ring-2 ring-error/20' : 'border-outline-variant']" />
                        <span class="font-body-small text-body-small text-on-surface-variant">Ngày</span>
                    </div>
                    <p v-show="repeat < 1 || repeat > 3" class="flex items-center gap-xs font-caption text-caption text-error"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1-3 ngày.</p>
                    <p v-if="errors.repeat_days" class="font-caption text-caption text-error">{{ errors.repeat_days }}</p>
                    <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">info</span>Mốc nhắc lại bắt buộc phải nằm trong khoảng từ 1 đến 3 ngày trước hạn.</p>
                </div>
                <div class="space-y-xs">
                    <label for="must_contact_days" class="block font-body-medium text-body-medium text-on-surface">Mốc quá hạn bắt buộc liên hệ</label>
                    <p class="font-caption text-caption text-on-surface-variant">Tạo yêu cầu liên hệ trực tiếp (gọi điện) nếu quá hạn thanh toán.</p>
                    <div class="w-36">
                        <UiInput id="must_contact_days" type="number" name="must_contact_days" min="1" max="60" :value="mustContactDays" required suffix="Ngày" class="font-code text-code" />
                    </div>
                    <p v-if="errors.must_contact_days" class="font-caption text-caption text-error">{{ errors.must_contact_days }}</p>
                </div>
            </div>
            <div class="mt-lg flex flex-col gap-md border-t border-surface-container pt-md md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">notifications</span>
                    <div>
                        <p class="font-body-medium text-body-medium">Kênh thông báo: Chuông thông báo in-app</p>
                        <p class="font-caption text-caption text-on-surface-variant">Thông báo đẩy trực tiếp tới Cổng phụ huynh / học sinh MENGLISH (kênh từng mốc chỉnh ở danh sách mốc bên dưới).</p>
                    </div>
                    <UiBadge color="success" pill>Hoạt động</UiBadge>
                </div>
                <div class="flex gap-sm">
                    <UiButton variant="secondary" :href="route('system-config.debt-reminders')">Hủy</UiButton>
                    <UiButton type="submit" icon="save">Lưu cấu hình</UiButton>
                </div>
            </div>
        </UiForm>

        <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
            <div class="space-y-lg xl:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Các mốc nhắc chi tiết</h2>
                    <UiButton icon="add" @click="newOpen = true">Thêm mốc nhắc</UiButton>
                </div>

                <!-- Các mốc nhắc -->
                <UiForm
                    v-for="rule in rules"
                    :key="rule.milestone_key"
                    :action="route('system-config.debt-reminders.store')"
                    method="post"
                    :error-bag="`rule_${rule.id}`"
                    class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md"
                >
                    <input type="hidden" name="milestone_key" :value="rule.milestone_key" />
                    <input type="hidden" name="channels_submitted" value="1" />

                    <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                        <div class="flex items-center gap-sm">
                            <span class="rounded-lg bg-primary-fixed px-sm py-xs font-code text-code text-primary">{{ rule.milestone_key }}</span>
                            <span class="font-body-medium text-body-medium text-on-surface">{{ rule.offset_label }}</span>
                        </div>
                        <label class="flex items-center gap-xs font-body-small text-body-small">
                            <input type="hidden" name="is_enabled" value="0" />
                            <input type="checkbox" name="is_enabled" value="1" :checked="rule.is_enabled" class="rounded text-primary focus:ring-primary-container" />
                            Đang bật
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <UiField label="Tên mốc" name="title" :for="`title_${rule.milestone_key}`" required>
                            <input :id="`title_${rule.milestone_key}`" type="text" name="title" :value="rule.title" required :class="control" />
                        </UiField>
                        <UiSelect v-model="timing[rule.milestone_key]" label="Thời điểm gửi" name="timing" :id="`timing_${rule.milestone_key}`" :options="timingOptions" />
                        <div v-show="timing[rule.milestone_key] !== 'due'">
                            <UiField label="Số ngày" name="days" :for="`days_${rule.milestone_key}`">
                                <input :id="`days_${rule.milestone_key}`" type="number" name="days" min="1" max="60" :value="rule.days ?? ''" :class="control" />
                            </UiField>
                        </div>
                    </div>

                    <UiField label="Kênh thông báo" name="channels">
                        <div class="flex flex-wrap gap-md">
                            <label v-for="channel in channels" :key="channel.value" class="flex items-center gap-xs font-body-small text-body-small">
                                <input type="checkbox" name="channels[]" :value="channel.value" :checked="rule.channels.includes(channel.value)" class="rounded text-primary focus:ring-primary-container" />
                                {{ channel.label }}
                            </label>
                            <span class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" title="Chưa tích hợp Zalo ZNS / SMS cho nhắc nợ">
                                <input type="checkbox" disabled class="rounded" aria-label="Zalo ZNS / SMS (chưa tích hợp)" /> Zalo ZNS / SMS (chưa tích hợp)
                            </span>
                        </div>
                    </UiField>

                    <UiField label="Mẫu tin nhắn" name="template_content" :for="`template_${rule.milestone_key}`" required>
                        <textarea :id="`template_${rule.milestone_key}`" name="template_content" rows="3" required :class="control" :value="rule.template_content"></textarea>
                    </UiField>

                    <div class="flex justify-end">
                        <UiButton type="submit" variant="secondary" icon="save">Lưu mốc {{ rule.milestone_key }}</UiButton>
                    </div>
                </UiForm>
                <UiEmptyState v-if="!rules.length" icon="notifications_off" title="Chưa cấu hình mốc nhắc nợ" description="Khi chưa có mốc nào, hệ thống dùng mặc định: trước hạn 3 ngày, đúng hạn, quá hạn 3 ngày." />
            </div>

            <div class="space-y-lg">
                <UiAlert type="info" title="Biến dùng trong mẫu tin">
                    <ul class="space-y-xs">
                        <li v-for="variable in variables" :key="variable.value"><code class="font-code">{{ variable.value }}</code> — {{ variable.label }}</li>
                    </ul>
                    <p class="mt-xs">Viết thường hoặc IN HOA đều được (vd. <code class="font-code">{TEN_HOC_VIEN}</code>). Mẫu có biến khác sẽ không lưu được, để tin gửi đi không còn nguyên dấu ngoặc.</p>
                </UiAlert>

                <UiAlert type="success" title="Thông tin vận hành">
                    Các cấu hình mới có hiệu lực cho các đợt quét nhắc nợ từ lần chạy kế tiếp. Hệ thống tự động gửi thông báo vào lúc 08:30 sáng hằng ngày theo giờ Việt Nam (GMT+7),
                    đúng ngày chạm mốc, mỗi mốc một lần/ngày. Khoản đang khất nợ hoặc bảo lưu được tạm dừng nhắc tới hạn mới / ngày học lại.
                    Quá hạn từ "Mốc quá hạn bắt buộc liên hệ", học viên vào nhóm "Quá hạn nghiêm trọng" để gọi điện trực tiếp.
                </UiAlert>
            </div>
        </div>
    </div>

    <UiModal :show="newOpen" title="Thêm mốc nhắc" max-width="2xl" @close="newOpen = false">
        <UiForm id="new-reminder-form" :action="route('system-config.debt-reminders.store')" method="post" error-bag="newReminder" class="space-y-md" reset-on-success @success="onNewSaved">
            <input type="hidden" name="channels_submitted" value="1" />
            <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                <UiInput name="title" id="title_new" label="Tên mốc" placeholder="Ví dụ: Nhắc trước hạn 7 ngày" required />
                <UiSelect v-model="newTiming" label="Thời điểm gửi" name="timing" id="timing_new" :options="timingOptions" />
                <div v-show="newTiming !== 'due'">
                    <UiInput name="days" id="days_new" type="number" min="1" max="60" label="Số ngày" placeholder="7" />
                </div>
            </div>
            <UiField label="Kênh thông báo" name="channels">
                <div class="flex flex-wrap gap-md">
                    <label v-for="channel in channels" :key="channel.value" class="flex items-center gap-xs font-body-small text-body-small">
                        <input type="checkbox" name="channels[]" :value="channel.value" checked class="rounded text-primary focus:ring-primary-container" />
                        {{ channel.label }}
                    </label>
                </div>
            </UiField>
            <UiTextarea name="template_content" id="template_new" label="Mẫu tin nhắn" :rows="3" required placeholder="Chào {ten_hoc_vien}, học phí lớp {lop_hoc} ({so_tien}) sẽ đến hạn ngày {han_dong}..." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="newOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="new-reminder-form" icon="add">Thêm mốc nhắc</UiButton>
        </template>
    </UiModal>
</template>
