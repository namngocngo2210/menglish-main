<script setup>
/**
 * Cấu hình tài khoản ngân hàng thu tiền (mockup ui-full-tinh-nang-menglish/epic-5/cau-hinh-tai-khoan-ngan-hang):
 * tab Tài khoản ngân hàng / Webhook SePay / Nhật ký giao dịch SePay. Thêm / Sửa tài khoản dùng chung 1 hộp thoại
 * (lỗi validate → giữ hộp thoại mở kèm dữ liệu đã nhập).
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { copyText } from '@/lib/clipboard';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Tài khoản ngân hàng thu tiền' } });

const props = defineProps({
    accounts: { type: Array, required: true },
    totalAccounts: { type: Number, default: 0 },
    defaultAccount: { type: Object, default: null },
    search: { type: String, default: '' },
    branches: { type: Array, default: () => [] },
    sepayEnabled: { type: Boolean, default: false },
    sepay: { type: Object, default: null },
    transactions: { type: Array, default: () => [] },
});

const page = usePage();
const blank = { id: '', account_type: 'company', bank_code: '', bank_name: '', account_number: '', account_holder: '', branch_id: '', is_default_vietqr: false, is_active: true };
const activeTab = ref('banks');
const showSecret = ref(false);
const copiedTag = ref('');
const formOpen = ref(false);
const form = reactive({ ...blank });
const secretInput = ref(null);
const webhookUrl = ref(props.sepay ? props.sepay.webhook_url || props.sepay.current_endpoint : '');
const isActiveError = computed(() => page.props.errors?.is_active ?? null);

const tabClass = (tab) => [
    'flex items-center gap-1.5 rounded-xl border border-surface-container-highest px-4 py-2 text-xs transition',
    activeTab.value === tab ? 'bg-primary-container font-bold text-white shadow-xs' : 'bg-surface-container-lowest font-semibold text-on-surface-variant hover:bg-surface-container',
];

async function copyVal(text, tag) {
    if (!(await copyText(text, null))) return;
    copiedTag.value = tag;
    setTimeout(() => {
        if (copiedTag.value === tag) copiedTag.value = '';
    }, 2000);
}

function openNew() {
    activeTab.value = 'banks';
    Object.assign(form, blank);
    formOpen.value = true;
}

function openEdit(acc) {
    Object.assign(form, {
        id: acc.id,
        account_type: acc.account_type || 'company',
        bank_code: acc.bank_code,
        bank_name: acc.bank_name,
        account_number: acc.account_number,
        account_holder: acc.account_holder,
        branch_id: acc.branch_id ? String(acc.branch_id) : '',
        is_default_vietqr: Boolean(acc.is_default_vietqr),
        is_active: acc.is_active === undefined ? true : Boolean(acc.is_active),
    });
    formOpen.value = true;
}

// Lỗi "không ngừng dùng được tài khoản mặc định" hiện ở trang (như cũ) → đóng hộp thoại.
function onFormError(errors) {
    if (errors.is_active) formOpen.value = false;
}

function onSearch(event) {
    const q = new FormData(event.target).get('q');
    router.get(route('system-config.bank-accounts'), q ? { q } : {}, { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Cấu hình Tài khoản ngân hàng thu tiền" description="Quản lý các tài khoản nhận thanh toán học phí trên toàn hệ thống MENGLISH, mã VietQR và kết nối SePay tự động gạch nợ.">
        <template #actions>
            <UiButton icon="add_circle" @click="openNew">Thêm tài khoản mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- Tab: Tài khoản / SePay / Nhật ký -->
        <div class="flex items-center gap-2 overflow-x-auto pb-1">
            <button type="button" :class="tabClass('banks')" @click="activeTab = 'banks'">
                <span class="material-symbols-outlined text-base">account_balance_wallet</span>
                <span>Tài khoản Ngân hàng ({{ accounts.length }})</span>
            </button>

            <template v-if="sepayEnabled && sepay">
                <button type="button" :class="tabClass('sepay')" @click="activeTab = 'sepay'">
                    <span class="material-symbols-outlined text-base">webhook</span>
                    <span>Cấu hình Webhook SePay Gateway</span>
                    <span :class="['py-0.2 rounded-full px-1.5 text-xs font-bold', sepay.is_active ? 'bg-tertiary/10 text-tertiary' : 'bg-surface-container-high text-on-surface-variant']">{{ sepay.is_active ? 'ĐANG BẬT' : 'TẮT' }}</span>
                </button>
                <button type="button" :class="tabClass('logs')" @click="activeTab = 'logs'">
                    <span class="material-symbols-outlined text-base">receipt_long</span>
                    <span>Nhật ký Giao dịch SePay ({{ transactions.length }})</span>
                </button>
            </template>
            <div v-else class="flex items-center gap-1.5 rounded-xl border border-dashed border-outline-variant bg-surface-container px-4 py-2 text-xs text-on-surface-variant" title="Đặt SEPAY_WEBHOOK_ENABLED=true trong .env để bật lại">
                <span class="material-symbols-outlined text-base">webhook_off</span>
                <span>SePay webhook đang tắt tạm thời</span>
            </div>
        </div>

        <!-- TAB 1: TÀI KHOẢN NGÂN HÀNG -->
        <div v-show="activeTab === 'banks'" class="space-y-4">
            <UiAlert type="info">
                <strong>Lưu ý:</strong> Mọi tài khoản ngân hàng được cấu hình tại đây đều tự động tích hợp mã QR gắn mã học sinh.
                Chỉ có <strong>01 tài khoản "Mặc định"</strong> hiển thị tự động trên màn hình Lập phiếu thu khi hợp đồng / chi nhánh của học viên chưa có tài khoản riêng.
            </UiAlert>

            <UiAlert v-if="isActiveError" type="error">{{ isActiveError }}</UiAlert>

            <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
                <div class="lg:col-span-8">
                    <UiDataTable min-width="720px">
                        <template #header>
                            <h3 class="font-h3 text-h3 text-on-surface">Danh sách tài khoản</h3>
                            <form method="GET" :action="route('system-config.bank-accounts')" class="relative" @submit.prevent="onSearch">
                                <input type="search" name="q" :value="search" placeholder="Tìm kiếm tài khoản..." aria-label="Tìm kiếm tài khoản" class="w-64 rounded-lg border-outline-variant py-xs pl-sm pr-10 font-body-small text-body-small" />
                                <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant" aria-label="Tìm"><span class="material-symbols-outlined text-[20px]">search</span></button>
                            </form>
                        </template>
                        <table>
                            <thead>
                                <tr>
                                    <th>Loại</th>
                                    <th>Ngân hàng &amp; Số TK</th>
                                    <th>Chủ tài khoản</th>
                                    <th>Chi nhánh</th>
                                    <th>Trạng thái</th>
                                    <th class="text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="acc in accounts" :key="acc.id" :class="{ 'opacity-60': !acc.is_active }">
                                    <td><UiBadge :color="acc.account_type === 'other' ? 'neutral' : 'primary'" :dot="false">{{ acc.type_label }}</UiBadge></td>
                                    <td>
                                        <div class="font-body-medium text-body-medium">{{ acc.bank_name }} <span class="font-code text-caption text-on-surface-variant">({{ acc.bank_code }})</span></div>
                                        <div class="flex items-center gap-xs font-code text-code text-primary">
                                            {{ acc.account_number }}
                                            <button type="button" class="text-on-surface-variant hover:text-on-surface" title="Sao chép STK" aria-label="Sao chép STK" @click="copyVal(String(acc.account_number).replace(/\s+/g, ''), `stk_${acc.id}`)">
                                                <span class="material-symbols-outlined text-[16px]">{{ copiedTag === `stk_${acc.id}` ? 'check' : 'content_copy' }}</span>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="uppercase">{{ acc.account_holder }}</td>
                                    <td>{{ acc.branch_name ?? acc.branch_location ?? 'Toàn hệ thống' }}</td>
                                    <td class="whitespace-nowrap">
                                        <span v-if="acc.is_default_vietqr" class="inline-flex items-center gap-xs font-body-medium text-primary"><span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1" aria-hidden="true">star</span>Mặc định</span>
                                        <UiBadge v-else-if="!acc.is_active" color="neutral">Ngừng dùng</UiBadge>
                                        <UiForm v-else :action="route('system-config.bank-accounts.default', acc.id)" method="post" class="inline">
                                            <button type="submit" class="font-body-small text-body-small text-on-surface-variant underline hover:text-primary">Đặt làm mặc định</button>
                                        </UiForm>
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <UiButton size="sm" variant="ghost" icon="edit" title="Sửa tài khoản" aria-label="Sửa tài khoản" @click="openEdit(acc)" />
                                        <UiForm :action="route('system-config.bank-accounts.destroy', acc.id)" method="delete" confirm="Xóa tài khoản ngân hàng này?" confirm-label="Xóa" danger class="inline">
                                            <UiButton type="submit" size="sm" variant="danger-text" icon="delete" title="Xóa" aria-label="Xóa tài khoản" />
                                        </UiForm>
                                    </td>
                                </tr>
                                <tr v-if="!accounts.length">
                                    <td colspan="6"><UiEmptyState icon="account_balance" :title="search !== '' ? 'Không tìm thấy tài khoản phù hợp' : 'Chưa có tài khoản ngân hàng nào'" /></td>
                                </tr>
                            </tbody>
                        </table>
                        <template #footer>
                            <p class="px-md py-sm font-body-small text-body-small text-on-surface-variant">Hiển thị {{ accounts.length }} trên {{ totalAccounts }} tài khoản</p>
                        </template>
                    </UiDataTable>
                </div>

                <!-- VietQR -->
                <div class="space-y-md lg:col-span-4">
                    <section class="flex items-start gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                        <span class="material-symbols-outlined text-[32px] text-primary" aria-hidden="true">qr_code_2</span>
                        <div class="min-w-0 flex-1">
                            <h4 class="font-body-medium text-body-medium text-on-surface">Tích hợp VietQR</h4>
                            <p class="font-body-small text-body-small text-on-surface-variant">Mã QR được tự động tạo dựa trên số tài khoản và nội dung nộp học phí.</p>
                            <template v-if="defaultAccount?.vietqr_preview_url">
                                <img :src="defaultAccount.vietqr_preview_url" alt="VietQR tài khoản mặc định" class="mt-sm h-32 w-32 rounded-lg border border-outline-variant object-contain" loading="lazy" />
                                <p class="font-caption text-caption text-on-surface-variant">Tài khoản mặc định: {{ defaultAccount.bank_name }} · {{ defaultAccount.account_number }}</p>
                            </template>
                            <p v-else class="mt-sm font-caption text-caption text-error">Chưa có tài khoản đang hoạt động để tạo mã QR.</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <template v-if="sepayEnabled && sepay">
            <!-- TAB 2: CẤU HÌNH WEBHOOK SEPAY GATEWAY -->
            <div v-show="activeTab === 'sepay'" class="space-y-6">
                <div class="max-w-4xl space-y-6 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-xs">
                    <div class="flex items-center justify-between border-b border-surface-container-highest pb-4">
                        <div>
                            <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-primary-container">lock_reset</span>
                                <span>Cấu hình Webhook SePay Gateway (Tự động Gạch Nợ &amp; Xác Thực)</span>
                            </h2>
                            <p class="mt-0.5 text-xs text-on-surface-variant">Copy đường dẫn URL Webhook và Secret Key này lên trang quản trị SePay để kết nối</p>
                        </div>
                        <UiBadge :color="sepay.is_active ? 'success' : 'neutral'" :dot="false" pill>{{ sepay.is_active ? '● Đang Kích Hoạt' : '○ Tạm Dừng' }}</UiBadge>
                    </div>

                    <UiForm :action="route('system-config.sepay.update')" method="post" class="space-y-5">
                        <!-- 1. Thông tin cơ bản -->
                        <div class="space-y-4">
                            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface text-primary">
                                <span class="material-symbols-outlined text-sm">tune</span>
                                <span>Thông tin cơ bản Webhook</span>
                            </h3>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <UiInput label="Tên webhook" name="webhook_name" :value="sepay.webhook_name" required placeholder="Xác Thực Thanh Toán Meducation" class="font-bold" hint="Đặt tên dễ nhớ để phân biệt các webhook trong danh sách SePay." />
                                <UiSelect
                                    label="Loại giao dịch"
                                    name="transaction_type"
                                    :value="sepay.transaction_type"
                                    required
                                    :options="[
                                        { value: 'in', label: 'Tiền vào (Thu học phí - Khuyên dùng)' },
                                        { value: 'out', label: 'Tiền ra' },
                                        { value: 'all', label: 'Tất cả (Tiền vào & Tiền ra)' },
                                    ]"
                                />
                            </div>

                            <UiAlert v-if="sepay.different_host" type="warning" class="text-xs">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                    <div>
                                        <span class="font-bold">Cảnh báo khác tên miền:</span> URL Webhook trong CSDL đang trỏ đến <code class="rounded bg-warning/10 px-1 py-0.5 font-mono font-bold text-on-warning-container">{{ sepay.saved_host }}</code>, khác với tên miền bạn đang truy cập (<code class="rounded bg-surface-container-lowest px-1 py-0.5 font-mono font-bold text-on-surface">{{ sepay.current_host }}</code>).
                                    </div>
                                    <UiButton size="sm" icon="sync" @click="webhookUrl = sepay.current_endpoint">Đổi sang {{ sepay.current_host }}</UiButton>
                                </div>
                            </UiAlert>

                            <div>
                                <label for="sepayWebhookUrlInput" class="mb-1 block flex items-center justify-between text-xs font-semibold text-on-surface-variant">
                                    <span>URL nhận webhook <span class="text-error">*</span></span>
                                    <span class="text-xs text-on-surface-subtle">SePay gửi dữ liệu giao dịch đến URL này khi có tiền vào</span>
                                </label>
                                <div class="flex items-center gap-2">
                                    <input id="sepayWebhookUrlInput" v-model="webhookUrl" type="url" name="webhook_url" required class="flex-1 rounded-xl border border-primary-container/30 bg-primary-container/10 p-2.5 font-mono text-xs font-bold text-primary-container focus:border-primary-container focus:ring-primary-container" />
                                    <UiButton variant="secondary" @click="copyVal(webhookUrl, 'webhook_url')">
                                        <span class="material-symbols-outlined text-sm">{{ copiedTag === 'webhook_url' ? 'check' : 'content_copy' }}</span>
                                        <span>{{ copiedTag === 'webhook_url' ? 'Đã sao chép!' : 'Sao chép URL' }}</span>
                                    </UiButton>
                                </div>
                                <UiErrors :messages="page.props.errors?.webhook_url" class="mt-1" />
                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-on-surface-variant">
                                    <div class="flex items-center gap-2">
                                        <span>Đường dẫn endpoint chuẩn theo domain đang mở:</span>
                                        <code class="select-all rounded bg-surface-container px-1.5 py-0.5 font-mono text-on-surface-variant">{{ sepay.current_endpoint }}</code>
                                    </div>
                                    <button type="button" class="flex items-center gap-1 font-semibold text-secondary underline transition hover:text-secondary" @click="webhookUrl = sepay.current_endpoint">
                                        <span class="material-symbols-outlined text-xs">sync</span>
                                        <span>Điền nhanh URL theo domain hiện tại</span>
                                    </button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <UiSelect
                                    label="Định dạng dữ liệu"
                                    name="data_format"
                                    value="json"
                                    required
                                    :options="[
                                        { value: 'json', label: 'JSON (khuyến nghị) — application/json' },
                                        { value: 'form', label: 'Form (hỗ trợ tệp đính kèm) — multipart/form-data' },
                                        { value: 'urlencoded', label: 'Form (URL-encoded) — application/x-www-form-urlencoded' },
                                    ]"
                                />
                                <div class="flex items-center gap-2 pt-6">
                                    <label class="relative flex cursor-pointer items-center gap-2 text-xs font-medium text-on-surface-variant">
                                        <input type="checkbox" name="auto_retry" value="1" :checked="sepay.auto_retry" class="rounded text-primary-container focus:ring-primary-container" />
                                        <span>Tự động gửi lại khi server trả lỗi (tối đa 7 lần)</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Bảo mật & Xác thực HMAC-SHA256 -->
                        <div class="space-y-4 border-t border-surface-container-highest pt-4">
                            <div class="flex items-center justify-between">
                                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface text-primary">
                                    <span class="material-symbols-outlined text-sm">security</span>
                                    <span>Bảo mật &amp; Xác thực Chống Giả Mạo</span>
                                </h3>
                                <UiBadge color="success" :dot="false" pill>Khuyến nghị: HMAC-SHA256</UiBadge>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <UiSelect
                                    label="Phương thức xác thực"
                                    name="auth_method"
                                    :value="sepay.auth_method"
                                    required
                                    :options="[
                                        { value: 'hmac_sha256', label: 'HMAC-SHA256 (Khuyến nghị)' },
                                        { value: 'api_key', label: 'API Key' },
                                    ]"
                                />
                                <div>
                                    <label for="sepaySecretKeyInput" class="mb-1 block flex items-center justify-between text-xs font-semibold text-on-surface-variant">
                                        <span>Secret Key HMAC-SHA256 <span class="text-error">*</span></span>
                                        <button type="button" class="text-xs font-normal text-on-surface-subtle hover:text-on-surface-variant" @click="showSecret = !showSecret">
                                            <span>{{ showSecret ? 'Ẩn' : 'Hiện' }}</span>
                                        </button>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input id="sepaySecretKeyInput" ref="secretInput" :type="showSecret ? 'text' : 'password'" name="secret_key" :value="sepay.secret_key" required class="flex-1 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2.5 font-mono text-xs font-bold text-on-surface focus:border-primary-container focus:ring-primary-container" placeholder="whsec_..." />
                                        <UiButton variant="secondary" title="Sao chép Secret Key" @click="copyVal(secretInput?.value ?? '', 'secret_key')">
                                            <span class="material-symbols-outlined text-sm">{{ copiedTag === 'secret_key' ? 'check' : 'content_copy' }}</span>
                                        </UiButton>
                                    </div>
                                    <UiErrors :messages="page.props.errors?.secret_key" class="mt-1" />
                                    <p class="mt-1 text-xs text-on-surface-subtle">SePay ký dữ liệu bằng HMAC-SHA256 qua header <code class="font-mono text-on-surface-variant">X-SePay-Signature</code>.</p>
                                </div>
                            </div>

                            <div class="pt-2">
                                <label class="relative flex cursor-pointer items-center gap-2 text-xs font-semibold text-on-surface">
                                    <input type="checkbox" name="is_active" value="1" :checked="sepay.is_active" class="rounded text-primary-container focus:ring-primary-container" />
                                    <span>Kích hoạt Webhook (Bật tính năng tự động gạch nợ khi có thông báo tiền về)</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-t border-surface-container-highest pt-4">
                            <span class="text-xs text-on-surface-subtle">Sau khi lưu, vui lòng đối soát URL và Secret Key khớp với trang SePay.vn</span>
                            <UiButton type="submit" icon="save">Lưu Cấu Hình SePay Webhook</UiButton>
                        </div>
                    </UiForm>
                </div>
            </div>

            <!-- TAB 3: NHẬT KÝ GIAO DỊCH SEPAY -->
            <div v-show="activeTab === 'logs'" class="space-y-4">
                <UiDataTable>
                    <template #header>
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface">Giao dịch SePay Webhook gần nhất</h3>
                            <p class="text-xs text-on-surface-subtle">Tự động đối soát nội dung chuyển khoản và gạch nợ học phí</p>
                        </div>
                        <span class="text-xs font-bold text-on-surface-variant">Tổng cộng: {{ transactions.length }} giao dịch</span>
                    </template>
                    <table class="text-xs">
                        <thead>
                            <tr>
                                <th>Thời gian</th>
                                <th>ID SePay</th>
                                <th>STK Nhận</th>
                                <th class="text-right">Số tiền</th>
                                <th>Nội dung chuyển khoản</th>
                                <th>Trạng thái</th>
                                <th>Kết quả đối soát</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in transactions" :key="tx.id">
                                <td class="font-mono text-xs text-on-surface-variant">{{ tx.date }}</td>
                                <td class="font-mono font-semibold">#{{ tx.sepay_id }}</td>
                                <td class="font-mono">
                                    {{ tx.account_number }}
                                    <div class="text-xs text-on-surface-subtle">{{ tx.gateway }}</div>
                                </td>
                                <td class="text-right"><UiMoney :value="tx.amount" sign tone="success" class="font-bold" /></td>
                                <td class="max-w-xs truncate" :title="tx.content"><span class="font-mono font-semibold text-on-surface">{{ tx.content }}</span></td>
                                <td>
                                    <UiBadge v-if="tx.status === 'matched'" color="success" :dot="false" pill>✓ Đã khớp học viên</UiBadge>
                                    <UiBadge v-else-if="tx.status === 'unmatched'" color="warning" :dot="false" pill>? Chưa khớp mã HS</UiBadge>
                                    <UiBadge v-else color="neutral" :dot="false" pill>{{ tx.status_label }}</UiBadge>
                                </td>
                                <td class="max-w-sm text-xs text-on-surface-variant">{{ tx.response_message ?? 'Đang chờ xử lý' }}</td>
                            </tr>
                            <tr v-if="!transactions.length">
                                <td colspan="7"><UiEmptyState icon="receipt_long" title="Chưa có giao dịch webhook nào từ SePay." description="Khi phụ huynh chuyển khoản quét mã VietQR, giao dịch sẽ tự động xuất hiện tại đây." /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </div>
        </template>

        <!-- Thêm / Sửa tài khoản (dùng chung 1 hộp thoại) -->
        <UiModal :show="formOpen" max-width="lg" bare labelledby="modal-bank-account-title" @close="formOpen = false">
            <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                <h3 id="modal-bank-account-title" class="font-h3 text-h3 text-on-surface">{{ form.id ? 'Sửa tài khoản ngân hàng' : 'Thêm tài khoản ngân hàng' }}</h3>
                <button type="button" class="rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" aria-label="Đóng" data-modal-close @click="formOpen = false">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-lg py-md font-body-base text-body-base text-on-surface">
                <UiForm id="bank-account-form" :action="form.id ? route('system-config.bank-accounts.update', form.id) : route('system-config.bank-accounts.store')" :method="form.id ? 'put' : 'post'" class="space-y-sm" @success="formOpen = false" @error="onFormError">
                    <UiSelect v-model="form.account_type" label="Loại tài khoản" name="account_type" :options="[{ value: 'company', label: 'Công ty (Chủ sở hữu chính)' }, { value: 'other', label: 'Khác (Cá nhân/Đại diện)' }]" />
                    <UiInput v-model="form.account_number" label="Số tài khoản" name="account_number" required placeholder="Nhập số tài khoản ngân hàng" class="font-code text-code" />
                    <div class="grid grid-cols-3 gap-sm">
                        <UiInput v-model="form.bank_code" label="Mã NH" name="bank_code" required placeholder="VCB" title="Mã ngân hàng NAPAS dùng tạo VietQR" class="font-code text-code uppercase" />
                        <div class="col-span-2">
                            <UiInput v-model="form.bank_name" label="Tên ngân hàng" name="bank_name" required placeholder="VD: Vietcombank, Techcombank..." />
                        </div>
                    </div>
                    <UiInput v-model="form.account_holder" label="Chủ tài khoản" name="account_holder" required placeholder="Nhập tên đầy đủ chủ tài khoản" class="uppercase" />
                    <UiSelect v-model="form.branch_id" label="Cơ sở áp dụng" name="branch_id" :options="branches" placeholder="Toàn hệ thống" />
                    <label class="flex items-center justify-between gap-sm rounded-lg border border-outline-variant p-sm">
                        <span>
                            <span class="block font-body-medium text-body-medium">Đặt làm tài khoản mặc định</span>
                            <span class="block font-caption text-caption text-on-surface-variant">Sử dụng cho toàn bộ phiếu thu tự động</span>
                        </span>
                        <input v-model="form.is_default_vietqr" type="checkbox" name="is_default_vietqr" value="1" class="rounded text-primary-container focus:ring-primary-container" />
                    </label>
                    <label v-if="form.id" class="flex items-center justify-between gap-sm rounded-lg border border-outline-variant p-sm">
                        <span class="font-body-medium text-body-medium">Đang sử dụng</span>
                        <span>
                            <input type="hidden" name="is_active" value="0" />
                            <input v-model="form.is_active" type="checkbox" name="is_active" value="1" class="rounded text-primary-container focus:ring-primary-container" />
                        </span>
                    </label>
                </UiForm>
            </div>
            <div class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
                <UiButton variant="secondary" @click="formOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="bank-account-form" icon="save">Lưu cấu hình</UiButton>
            </div>
        </UiModal>
    </div>
</template>
