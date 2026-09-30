<script setup>
/**
 * Cấu hình Email nhận thông báo & Hòm thư gửi (SMTP): danh sách email nhận (không giới hạn), tài khoản SMTP lưu database,
 * các sự kiện tự động gửi email; cột phải: gửi thử (kèm khung kết quả lần gửi vừa rồi) và thông số SMTP đang áp dụng.
 */
import { reactive, ref } from 'vue';
import { confirmDialog } from '@/lib/confirm';

defineOptions({ layout: { title: 'Cấu hình Email nhận & Hòm thư gửi' } });

const props = defineProps({
    emails: { type: Array, default: () => [] },
    events: { type: Object, default: () => ({}) },
    mailConfig: { type: Object, required: true },
    userEmail: { type: String, default: null },
    testResult: { type: Object, default: null },
});

const emails = ref([...props.emails]);
const newEmail = ref('');
const errorMessage = ref('');
const showPassword = ref(false);
const smtp = reactive({
    host: props.mailConfig.host || 'smtp.gmail.com',
    port: props.mailConfig.port || 587,
    encryption: props.mailConfig.encryption || 'tls',
    username: props.mailConfig.username || '',
    fromAddress: props.mailConfig.from_address || '',
    fromName: props.mailConfig.from_name || 'MEnglish Support',
});
const encryptions = [
    { value: 'tls', label: 'TLS' },
    { value: 'ssl', label: 'SSL' },
    { value: '', label: 'Không mã hóa' },
];
const presets = [
    ['build', 'tech.vmst@gmail.com'],
    ['mail', 'nhungmagnet.001@gmail.com'],
    ['shield_person', 'admin@meducation.vn'],
];
// Nhóm sự kiện: [tiêu đề nhóm, icon, màu icon, [tên trường, sự kiện, tiêu đề, badge [nhãn, màu] | null, mô tả]]
const eventGroups = [
    ['1. Hỗ trợ Kỹ thuật & Ticket', 'confirmation_number', 'text-primary', [
        ['notify_created', 'created', 'Khi có Ticket mới được tạo', ['Khuyên dùng', 'warning'], 'Gửi email kèm mã ticket, phân loại, mức độ ưu tiên và mô tả chi tiết ngay khi có ticket mới.'],
        ['notify_comment', 'comment', 'Khi có phản hồi / tin nhắn trao đổi mới', null, 'Gửi email thông báo nội dung trao đổi mới nhất để các bên nắm tiến độ mà không cần mở trang web liên tục.'],
        ['notify_status_changed', 'status_changed', 'Khi cập nhật trạng thái Ticket (Đang xử lý, Hoàn thành, Đóng)', null, 'Bắn email thông báo người thực hiện và tiến độ giải quyết sự cố đến các bên liên quan.'],
    ]],
    ['2. CRM & Tuyển sinh', 'campaign', 'text-secondary', [
        ['notify_stale_lead', 'stale_lead', 'Khi có thông báo Lead CRM trễ / sót chăm sóc (>24h)', ['Cảnh báo', 'error'], 'Bắn email cảnh báo khi khách hàng tiềm năng tiếp nhận quá 24 giờ mà chưa được nhân sự liên hệ chăm sóc.'],
    ]],
    ['3. Tài chính & Thu chi (Giao dịch)', 'payments', 'text-tertiary', [
        ['notify_transaction', 'transaction', 'Khi có giao dịch thanh toán / phiếu thu mới (SePay, Chuyển khoản, Tiền mặt)', ['Kế toán', 'success'], 'Bắn email thông báo số tiền, mã phiếu thu và thông tin học viên ngay khi có giao dịch thanh toán được ghi nhận.'],
        ['notify_overdue_debt', 'overdue_debt', 'Khi có phiếu thu trễ hẹn / học viên quá hạn đóng học phí', ['Nhắc nợ', 'warning'], 'Bắn email thông báo danh sách học viên và số tiền trễ hạn khi kích hoạt gửi nhắc nợ hoặc quét công nợ quá hạn.'],
    ]],
    ['4. Học vụ & Đào tạo (Học viên & Lớp học)', 'school', 'text-secondary', [
        ['notify_homework', 'homework', 'Khi có học viên nộp bài / trễ nộp bài tập hoặc kiểm tra', ['Học vụ', 'secondary'], 'Bắn email thông báo khi học viên nộp bài thi trên Portal, nộp bài tập video/ghi âm hoặc có cảnh báo trễ hạn nộp bài.'],
    ]],
];

function addEmail() {
    errorMessage.value = '';
    const raw = (newEmail.value || '').trim();
    if (!raw) return;
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    let addedCount = 0;
    for (const part of raw.split(/[\s,;]+/)) {
        const item = part.trim().toLowerCase();
        if (!item) continue;
        if (!regex.test(item)) {
            errorMessage.value = `Email "${item}" không đúng định dạng!`;
            return;
        }
        if (!emails.value.includes(item)) {
            emails.value.push(item);
            addedCount++;
        } else {
            errorMessage.value = `Email "${item}" đã có trong danh sách!`;
        }
    }
    if (addedCount > 0) newEmail.value = '';
}

function removeEmail(idx) {
    errorMessage.value = '';
    if (idx >= 0 && idx < emails.value.length) emails.value.splice(idx, 1);
}

async function clearAllEmails() {
    if (await confirmDialog({ message: 'Xóa toàn bộ email nhận thông báo khỏi danh sách?', confirmLabel: 'Xóa tất cả', danger: true })) {
        emails.value = [];
        errorMessage.value = '';
    }
}

function addPreset(presetEmail) {
    errorMessage.value = '';
    const item = (presetEmail || '').trim().toLowerCase();
    if (!item) return;
    if (!emails.value.includes(item)) emails.value.push(item);
    else errorMessage.value = `Email "${item}" đã có trong danh sách!`;
}

function applyGmailPreset() {
    smtp.host = 'smtp.gmail.com';
    smtp.port = 587;
    smtp.encryption = 'tls';
    if (!smtp.fromAddress && smtp.username) smtp.fromAddress = smtp.username;
}

function applyDomainPreset() {
    smtp.host = 'mail.meducation.vn';
    smtp.port = 465;
    smtp.encryption = 'ssl';
}
</script>

<template>
    <UiPageHeader title="Cấu hình Email nhận & Hòm thư gửi" icon="forward_to_inbox">
        <template #actions>
            <UiButton variant="secondary" icon="confirmation_number" :href="route('tickets.index')">Xem danh sách Ticket</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Cột trái: form cấu hình -->
            <div class="space-y-6 lg:col-span-2">
                <UiForm :action="route('system-config.ticket-emails.update')" method="post" class="space-y-6">
                    <!-- Thẻ 1: danh sách email nhận -->
                    <div class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-xs">
                        <div class="flex items-center justify-between border-b border-surface-container-highest pb-4">
                            <div>
                                <h2 class="flex items-center gap-2 text-sm font-bold text-on-surface">
                                    <span class="material-symbols-outlined text-[20px] text-primary">alternate_email</span>
                                    <span>Danh sách Email nhận thông báo Ticket (Không giới hạn)</span>
                                </h2>
                                <p class="mt-0.5 text-xs text-on-surface-variant">Thêm n email kỹ thuật, quản lý trung tâm hoặc ban giám đốc để nhận thông báo tức thì</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <UiButton v-show="emails.length > 1" variant="danger-text" size="sm" @click="clearAllEmails()">Xóa tất cả</UiButton>
                                <span class="rounded-full border border-primary-container/30 bg-primary-container/10 px-2.5 py-1 text-xs font-bold text-primary">{{ emails.length + ' email đã cấu hình' }}</span>
                            </div>
                        </div>

                        <!-- Các email đang nhận -->
                        <div>
                            <label class="mb-2 block text-xs font-semibold text-on-surface-variant">Các email đang nhận thông báo:</label>
                            <div class="flex min-h-[85px] flex-wrap items-center gap-2 rounded-2xl border-2 border-dashed border-surface-container-highest bg-surface-container-low/50 p-3">
                                <div v-for="(email, idx) in emails" :key="email" class="shadow-2xs group inline-flex items-center gap-1.5 rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-xs font-medium text-on-surface transition hover:border-primary-container/30">
                                    <span class="material-symbols-outlined text-[16px] text-primary">mail</span>
                                    <span class="font-mono text-on-surface">{{ email }}</span>
                                    <button type="button" class="ml-1 flex h-5 w-5 cursor-pointer items-center justify-center rounded-full bg-surface-container text-on-surface-subtle transition group-hover:bg-error/10 group-hover:text-error" title="Xóa email này khỏi danh sách" @click.stop.prevent="removeEmail(idx)">
                                        <span class="material-symbols-outlined text-[14px]">close</span>
                                    </button>
                                    <input type="hidden" name="emails[]" :value="email" />
                                </div>
                                <div v-if="emails.length === 0" class="flex items-center gap-1.5 px-1 py-2 text-xs italic text-on-surface-subtle">
                                    <span class="material-symbols-outlined text-[18px]">info</span>
                                    <span>Chưa có email nào trong danh sách. Hãy nhập email bên dưới và nhấn <strong>Thêm</strong>.</span>
                                </div>
                            </div>
                            <UiErrors :messages="Object.entries($page.props.errors ?? {}).filter(([k]) => k === 'emails' || k.startsWith('emails.')).map(([, m]) => m)" class="mt-1" />
                        </div>

                        <!-- Thêm email mới -->
                        <div class="space-y-2">
                            <label for="new-ticket-email" class="block text-xs font-semibold text-on-surface-variant">Thêm email mới vào danh sách:</label>
                            <div class="flex gap-2">
                                <div class="flex-1">
                                    <UiInput id="new-ticket-email" v-model="newEmail" icon="add_link" placeholder="Nhập email (ví dụ: cskh@menglish.edu.vn) rồi nhấn Enter hoặc bấm Thêm..." class="font-mono" @keydown.enter.prevent="addEmail()" />
                                </div>
                                <UiButton icon="add" @click="addEmail()">Thêm</UiButton>
                            </div>
                            <div v-if="errorMessage" class="flex items-center gap-1 text-xs font-semibold text-error">
                                <span class="material-symbols-outlined text-[14px]">warning</span>
                                <span>{{ errorMessage }}</span>
                            </div>
                        </div>

                        <!-- Gợi ý thêm nhanh -->
                        <div class="pt-1">
                            <span class="mr-1.5 text-xs font-medium text-on-surface-variant">Gợi ý thêm nhanh:</span>
                            <div class="mt-1 inline-flex flex-wrap gap-1.5">
                                <UiButton v-if="userEmail" variant="secondary" size="sm" icon="person" @click="addPreset(userEmail)">Email của tôi ({{ userEmail }})</UiButton>
                                <UiButton v-for="[icon, preset] in presets" :key="preset" variant="secondary" size="sm" :icon="icon" @click="addPreset(preset)">{{ preset }}</UiButton>
                            </div>
                        </div>
                    </div>

                    <!-- Thẻ 2: tài khoản gửi thư SMTP -->
                    <div class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-xs">
                        <div class="flex items-center justify-between border-b border-surface-container-highest pb-4">
                            <div>
                                <h2 class="flex items-center gap-2 text-sm font-bold text-on-surface">
                                    <span class="material-symbols-outlined text-[20px] text-primary">outbox</span>
                                    <span>Cấu hình Hòm thư &amp; Tài khoản gửi SMTP</span>
                                </h2>
                                <p class="mt-0.5 text-xs text-on-surface-variant">Hệ thống sẽ dùng tài khoản này để gửi mail thông báo và email thử nghiệm mà không phụ thuộc file .env</p>
                            </div>
                            <UiBadge color="success" pill>Lưu Database</UiBadge>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
                            <span class="flex items-center gap-1.5 text-xs font-semibold text-on-surface-variant">
                                <span class="material-symbols-outlined text-[18px] text-primary">bolt</span>
                                <span>Cấu hình nhanh 1 chạm:</span>
                            </span>
                            <div class="flex flex-wrap gap-2">
                                <UiButton variant="secondary" size="sm" @click="applyGmailPreset()"><span class="material-symbols-outlined text-[16px] text-error">mail</span><span>Gmail / Google Workspace (TLS 587)</span></UiButton>
                                <UiButton variant="secondary" size="sm" @click="applyDomainPreset()"><span class="material-symbols-outlined text-[16px] text-secondary">dns</span><span>Mail Tên Miền / VPS (SSL 465)</span></UiButton>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 text-xs md:grid-cols-2">
                            <div>
                                <UiInput v-model="smtp.host" label="Máy chủ gửi thư (SMTP Host)" name="mail_host" required placeholder="smtp.gmail.com" class="font-mono" />
                                <span class="mt-1 block text-xs text-on-surface-subtle">Gmail: <code>smtp.gmail.com</code> | Mail tên miền: <code>mail.meducation.vn</code></span>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <UiInput v-model="smtp.port" type="number" label="Cổng (Port)" name="mail_port" required placeholder="587" class="font-mono" />
                                    <span class="mt-1 block text-xs text-on-surface-subtle">TLS: <code>587</code> | SSL: <code>465</code></span>
                                </div>
                                <div>
                                    <UiSelect v-model="smtp.encryption" label="Mã hóa (Encryption)" name="mail_encryption" :options="encryptions" class="font-mono" />
                                </div>
                            </div>

                            <div>
                                <UiInput v-model="smtp.username" type="email" label="Tài khoản / Email đăng nhập SMTP" name="mail_username" required placeholder="tech.vmst@gmail.com" class="font-mono" />
                                <span class="mt-1 block text-xs text-on-surface-subtle">Tài khoản email dùng để xác thực với máy chủ SMTP</span>
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label for="f_mail_password" class="block font-semibold text-on-surface-variant">Mật khẩu ứng dụng (App Password) <span class="text-error">*</span></label>
                                    <button type="button" class="flex cursor-pointer items-center gap-1 text-xs text-primary hover:underline" @click="showPassword = !showPassword">
                                        <span class="material-symbols-outlined text-[13px]">{{ showPassword ? 'visibility_off' : 'visibility' }}</span>
                                        <span>{{ showPassword ? 'Ẩn' : 'Hiện' }}</span>
                                    </button>
                                </div>
                                <div class="relative">
                                    <UiInput :type="showPassword ? 'text' : 'password'" name="mail_password" :placeholder="mailConfig.has_password ? '•••••••••••••••• (Đã cấu hình mật khẩu, nhập mới nếu muốn đổi)' : 'Nhập 16 ký tự Mật khẩu ứng dụng Gmail...'" class="pr-8 font-mono" />
                                </div>
                                <UiErrors :messages="$page.props.errors?.mail_password" class="mt-1" />
                                <span class="mt-1 block text-xs text-on-surface-subtle">Đối với @gmail.com: dùng <strong>Mật khẩu ứng dụng 16 ký tự</strong> (không dùng mật khẩu đăng nhập cá nhân)</span>
                            </div>

                            <div>
                                <UiInput v-model="smtp.fromAddress" type="email" label="Email người gửi hiển thị (From Address)" name="mail_from_address" placeholder="tech.vmst@gmail.com" class="font-mono" />
                                <span class="mt-1 block text-xs text-on-surface-subtle">Thường để trùng với email đăng nhập ở trên</span>
                            </div>

                            <div>
                                <UiInput v-model="smtp.fromName" label="Tên người gửi hiển thị (From Name)" name="mail_from_name" placeholder="MEnglish Support" />
                                <span class="mt-1 block text-xs text-on-surface-subtle">Tên hiển thị trong hộp thư người nhận (ví dụ: MEnglish Support)</span>
                            </div>
                        </div>

                        <UiAlert type="warning" title="Hướng dẫn lấy Mật khẩu ứng dụng (App Password) cho tài khoản @gmail.com:" class="text-xs">
                            <ol class="list-inside list-decimal space-y-1 pl-1 text-xs leading-relaxed">
                                <li>Truy cập <a href="https://myaccount.google.com/security" target="_blank" rel="noopener" class="font-semibold text-secondary underline">myaccount.google.com/security</a> ➔ Đảm bảo đã bật <strong>Xác minh 2 bước (2-Step Verification)</strong>.</li>
                                <li>Vào mục <strong>Mật khẩu ứng dụng (App Passwords)</strong> (hoặc gõ tìm kiếm "App Passwords" ở thanh tìm kiếm trên trang Google).</li>
                                <li>Đặt tên ứng dụng là <code>MEnglish</code> rồi bấm <strong>Tạo (Create)</strong>.</li>
                                <li>Google sẽ hiện một mã <strong>16 chữ cái</strong> (ví dụ: <code>abcd efgh ijkl mnop</code>). Sao chép và dán vào ô <strong>Mật khẩu ứng dụng</strong> ở trên.</li>
                            </ol>
                        </UiAlert>
                    </div>

                    <!-- Thẻ 3: sự kiện tự động gửi email -->
                    <div class="space-y-6 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-xs">
                        <div class="border-b border-surface-container-highest pb-3">
                            <h2 class="flex items-center gap-2 text-sm font-bold text-on-surface">
                                <span class="material-symbols-outlined text-[20px] text-primary">notifications_active</span>
                                <span>Các sự kiện tự động kích hoạt gửi Email</span>
                            </h2>
                            <p class="mt-0.5 text-xs text-on-surface-variant">Tùy chỉnh các trường hợp hệ thống sẽ tự động gửi email thông báo tới danh sách email cấu hình ở trên</p>
                        </div>

                        <div v-for="([groupLabel, groupIcon, groupTone, triggers], gi) in eventGroups" :key="groupLabel" :class="['space-y-3', gi > 0 ? 'border-t border-surface-container-highest pt-2' : '']">
                            <div class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface-subtle">
                                <span :class="['material-symbols-outlined text-[16px]', groupTone]">{{ groupIcon }}</span>
                                <span>{{ groupLabel }}</span>
                            </div>
                            <label v-for="[field, event, title, badge, description] in triggers" :key="field" class="flex cursor-pointer items-start gap-3 rounded-xl border border-surface-container-highest p-3 transition hover:bg-surface-container-low/70">
                                <input type="checkbox" :name="field" value="1" :checked="events[event]" class="mt-0.5 h-4 w-4 cursor-pointer rounded border-outline-variant text-primary focus:ring-primary-container" />
                                <div>
                                    <div :class="['text-xs font-bold text-on-surface', badge ? 'flex items-center gap-2' : '']">
                                        <span>{{ title }}</span>
                                        <UiBadge v-if="badge" :color="badge[1]" pill>{{ badge[0] }}</UiBadge>
                                    </div>
                                    <p class="mt-0.5 text-xs text-on-surface-variant">{{ description }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-between rounded-2xl border border-surface-container-highest bg-surface-container-low p-4">
                        <div class="text-xs text-on-surface-variant">Cấu hình sẽ được lưu trực tiếp vào cơ sở dữ liệu và có hiệu lực ngay lập tức.</div>
                        <UiButton type="submit" icon="save">Lưu cấu hình</UiButton>
                    </div>
                </UiForm>
            </div>

            <!-- Cột phải: gửi thử & thông số SMTP -->
            <div class="space-y-6">
                <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-xs">
                    <div class="flex items-center gap-2 border-b border-surface-container-highest pb-3">
                        <span class="material-symbols-outlined text-[22px] text-primary">science</span>
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Gửi Thử Nghiệm (Test Email)</h3>
                            <p class="text-xs text-on-surface-variant">Bắn email thử để kiểm tra kết nối SMTP và tài khoản vừa cấu hình</p>
                        </div>
                    </div>

                    <UiForm :action="route('system-config.ticket-emails.test')" method="post" class="space-y-3">
                        <UiInput type="email" label="Gửi tới địa chỉ email kiểm tra:" name="test_email" :value="userEmail ?? ''" placeholder="Nhập email nhận thư test..." class="font-mono" required hint="Để trống nếu muốn bắn đồng loạt tới tất cả email đã cấu hình ở bên trái." />
                        <UiButton type="submit" icon="send" class="w-full">Bắn Thử Email Ngay</UiButton>
                    </UiForm>

                    <UiAlert v-if="testResult" :type="testResult.ok ? 'success' : 'error'" :title="testResult.ok ? 'Kết quả gửi thử: thành công' : 'Kết quả gửi thử: thất bại'" class="text-xs leading-relaxed" data-testid="test-mail-result">
                        <p>{{ testResult.message }}</p>
                        <p v-if="testResult.detail" class="mt-1 break-all font-mono">Chi tiết: {{ testResult.detail }}</p>
                        <p v-for="(hint, i) in testResult.hints ?? []" :key="i" class="mt-1">{{ hint }}</p>
                    </UiAlert>
                </div>

                <!-- Thông số SMTP đang áp dụng -->
                <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-xs">
                    <div class="flex items-center gap-2 border-b border-surface-container-highest pb-3">
                        <span class="material-symbols-outlined text-[22px] text-primary">dns</span>
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Trạng Thái Kết Nối SMTP</h3>
                            <p class="text-xs text-on-surface-variant">Thông số gửi thư đang áp dụng</p>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs">
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Trình gửi (Driver):</span>
                            <span class="rounded bg-surface-container px-2 py-0.5 font-mono text-xs font-bold uppercase text-on-surface">{{ mailConfig.driver }}</span>
                        </div>
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Máy chủ SMTP (Host):</span>
                            <span class="font-mono text-xs font-bold text-on-surface">{{ mailConfig.host ?? 'smtp.gmail.com' }}</span>
                        </div>
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Cổng kết nối (Port):</span>
                            <span class="font-mono text-on-surface">{{ mailConfig.port ?? 587 }}</span>
                        </div>
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Giao thức mã hóa:</span>
                            <span class="font-mono text-xs uppercase text-on-surface">{{ mailConfig.encryption ?? 'TLS' }}</span>
                        </div>
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Tài khoản SMTP:</span>
                            <span class="max-w-[150px] truncate font-mono text-xs text-on-surface" :title="mailConfig.username">{{ mailConfig.username || 'Chưa cấu hình' }}</span>
                        </div>
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Mật khẩu ứng dụng:</span>
                            <span class="font-medium text-tertiary">{{ mailConfig.has_password ? '●●●● Đã thiết lập' : 'Chưa có' }}</span>
                        </div>
                        <div class="flex items-center justify-between border-b border-surface-container-highest py-1">
                            <span class="text-on-surface-variant">Địa chỉ người gửi:</span>
                            <span class="max-w-[150px] truncate font-mono text-xs text-on-surface" :title="mailConfig.from_address">{{ mailConfig.from_address ?? 'noreply' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1">
                            <span class="text-on-surface-variant">Tên người gửi:</span>
                            <span class="font-medium text-on-surface">{{ mailConfig.from_name ?? 'MEnglish Support' }}</span>
                        </div>
                    </div>

                    <UiAlert type="info" class="text-xs leading-relaxed">
                        <strong>Lưu ý:</strong> Sau khi nhập Mật khẩu ứng dụng Gmail và nhấn <strong>Lưu Cấu Hình</strong>, bạn hãy dùng khung <strong>Gửi Thử Nghiệm</strong> ở trên để xác nhận email gửi đi thành công mà không cần kiểm tra log server.
                    </UiAlert>
                </div>
            </div>
        </div>
    </div>
</template>
