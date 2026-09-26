<x-app-layout>
    <x-ui.page-header title="Cấu hình Email nhận & Hòm thư gửi" icon="forward_to_inbox">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="confirmation_number" :href="route('tickets.index')">Xem danh sách Ticket</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <!-- Global Component Function (Safe in <script> tag without breaking HTML attributes) -->
    <script>
        function ticketEmailManager(cfg) {
            return {
                emails: Array.isArray(cfg.emails) ? cfg.emails : [],
                newEmail: '',
                errorMessage: '',
                showPassword: false,
                host: cfg.host || 'smtp.gmail.com',
                port: cfg.port || 587,
                encryption: cfg.encryption || 'tls',
                username: cfg.username || '',
                fromAddress: cfg.fromAddress || '',
                fromName: cfg.fromName || 'MEnglish Support',

                addEmail() {
                    this.errorMessage = '';
                    var raw = (this.newEmail || '').trim();
                    if (!raw) return;

                    var parts = raw.split(/[\s,;]+/);
                    var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    var addedCount = 0;

                    for (var i = 0; i < parts.length; i++) {
                        var item = parts[i].trim().toLowerCase();
                        if (!item) continue;

                        if (!regex.test(item)) {
                            this.errorMessage = 'Email "' + item + '" không đúng định dạng!';
                            return;
                        }

                        if (!this.emails.includes(item)) {
                            this.emails.push(item);
                            addedCount++;
                        } else {
                            this.errorMessage = 'Email "' + item + '" đã có trong danh sách!';
                        }
                    }

                    if (addedCount > 0) {
                        this.newEmail = '';
                    }
                },

                removeEmail(idx) {
                    this.errorMessage = '';
                    if (idx >= 0 && idx < this.emails.length) {
                        this.emails.splice(idx, 1);
                    }
                },

                clearAllEmails() {
                    if (confirm('Bạn có chắc chắn muốn xóa toàn bộ email nhận thông báo khỏi danh sách?')) {
                        this.emails = [];
                        this.errorMessage = '';
                    }
                },

                addPreset(presetEmail) {
                    this.errorMessage = '';
                    var item = (presetEmail || '').trim().toLowerCase();
                    if (!item) return;

                    if (!this.emails.includes(item)) {
                        this.emails.push(item);
                    } else {
                        this.errorMessage = 'Email "' + item + '" đã có trong danh sách!';
                    }
                },

                applyGmailPreset() {
                    this.host = 'smtp.gmail.com';
                    this.port = 587;
                    this.encryption = 'tls';
                    if (!this.fromAddress && this.username) {
                        this.fromAddress = this.username;
                    }
                },

                applyDomainPreset() {
                    this.host = 'mail.meducation.vn';
                    this.port = 465;
                    this.encryption = 'ssl';
                }
            };
        }
    </script>

    <div class="space-y-6" x-data="ticketEmailManager({
        emails: @js($emails),
        host: @js($mailConfig['host']),
        port: @js($mailConfig['port']),
        encryption: @js($mailConfig['encryption']),
        username: @js($mailConfig['username']),
        fromAddress: @js($mailConfig['from_address']),
        fromName: @js($mailConfig['from_name'])
    })">
        {{-- Top Nav Tabs matching System Config --}}
        <div class="flex items-center gap-2 border-b border-surface-container-highest pb-2 overflow-x-auto">
            <x-ui.button variant="secondary" size="sm" icon="account_balance_wallet" :href="route('system-config.bank-accounts')">Tài khoản Ngân hàng</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="notifications_active" :href="route('system-config.debt-reminders')">Mẫu nhắc nợ</x-ui.button>
            <x-ui.button size="sm" icon="mail" :href="route('system-config.ticket-emails')"><span>Email &amp; SMTP Ticket (<span x-text="emails.length"></span>)</span></x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="dns" :href="route('system-config.hosting')">Hosting &amp; Máy chủ</x-ui.button>
        </div>

        {{-- Session Status & Alerts --}}

        

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left Column: Form config --}}
            <div class="lg:col-span-2 space-y-6">
                <form action="{{ route('system-config.ticket-emails.update') }}" method="POST" class="space-y-6">
                    @csrf

                    {{-- Card 1: Multi-email Tag Input (n emails) --}}
                    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-xs p-6 space-y-5">
                        <div class="flex items-center justify-between border-b border-surface-container-highest pb-4">
                            <div>
                                <h2 class="text-sm font-bold text-on-surface flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary text-[20px]">alternate_email</span>
                                    <span>Danh sách Email nhận thông báo Ticket (Không giới hạn)</span>
                                </h2>
                                <p class="text-xs text-on-surface-variant mt-0.5">Thêm n email kỹ thuật, quản lý trung tâm hoặc ban giám đốc để nhận thông báo tức thì</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.button variant="danger-text" size="sm" x-show="emails.length > 1" x-on:click="clearAllEmails()">
                                    Xóa tất cả
                                </x-ui.button>
                                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-primary-container/10 text-primary border border-primary-container/30" x-text="emails.length + ' email đã cấu hình'"></span>
                            </div>
                        </div>

                        {{-- Email Chips Area --}}
                        <div>
                            <label class="block text-xs font-semibold text-on-surface-variant mb-2">Các email đang nhận thông báo:</label>
                            
                            <div class="min-h-[85px] p-3 rounded-2xl border-2 border-dashed border-surface-container-highest bg-surface-container-low/50 flex flex-wrap gap-2 items-center">
                                <template x-for="(email, idx) in emails" :key="idx">
                                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-surface-container-lowest border border-outline-variant text-on-surface text-xs font-medium shadow-2xs hover:border-primary-container/30 transition group">
                                        <span class="material-symbols-outlined text-[16px] text-primary">mail</span>
                                        <span class="font-mono text-on-surface" x-text="email"></span>
                                        <button 
                                            type="button" 
                                            @click.stop.prevent="removeEmail(idx)" 
                                            class="w-5 h-5 ml-1 rounded-full bg-surface-container group-hover:bg-error/10 text-on-surface-variant/70 group-hover:text-error flex items-center justify-center transition cursor-pointer"
                                            title="Xóa email này khỏi danh sách"
                                        >
                                            <span class="material-symbols-outlined text-[14px]">close</span>
                                        </button>
                                        {{-- Hidden Input submitted with the form --}}
                                        <input type="hidden" name="emails[]" :value="email">
                                    </div>
                                </template>

                                <div x-show="emails.length === 0" class="text-xs text-on-surface-variant/70 italic py-2 px-1 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[18px]">info</span>
                                    <span>Chưa có email nào trong danh sách. Hãy nhập email bên dưới và nhấn <strong>Thêm</strong>.</span>
                                </div>
                            </div>
                        </div>

                        {{-- Add Email Input Bar --}}
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-on-surface-variant">Thêm email mới vào danh sách:</label>
                            <div class="flex gap-2">
                                <div class="flex-1">
                                    <x-ui.input icon="add_link" x-model="newEmail" x-on:keydown.enter.prevent="addEmail()"
                                        placeholder="Nhập email (ví dụ: cskh@menglish.edu.vn) rồi nhấn Enter hoặc bấm Thêm..." class="font-mono" />
                                </div>
                                <x-ui.button icon="add" x-on:click="addEmail()">Thêm</x-ui.button>
                            </div>

                            {{-- Error feedback for invalid or duplicate email --}}
                            <div x-show="errorMessage" x-cloak class="text-[11px] text-error font-semibold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">warning</span>
                                <span x-text="errorMessage"></span>
                            </div>
                        </div>

                        {{-- Quick Suggestions / Presets --}}
                        <div class="pt-1">
                            <span class="text-[11px] text-on-surface-variant font-medium mr-1.5">Gợi ý thêm nhanh:</span>
                            <div class="inline-flex flex-wrap gap-1.5 mt-1">
                                @if(auth()->user()?->email)
                                    <x-ui.button variant="secondary" size="sm" icon="person" x-on:click="addPreset('{{ auth()->user()->email }}')">Email của tôi ({{ auth()->user()->email }})</x-ui.button>
                                @endif
                                <x-ui.button variant="secondary" size="sm" icon="build" x-on:click="addPreset('tech.vmst@gmail.com')">tech.vmst@gmail.com</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" icon="mail" x-on:click="addPreset('nhungmagnet.001@gmail.com')">nhungmagnet.001@gmail.com</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" icon="shield_person" x-on:click="addPreset('admin@meducation.vn')">admin@meducation.vn</x-ui.button>
                            </div>
                        </div>
                    </div>

                    {{-- Card 2: Cấu hình Tài khoản Gửi thư SMTP --}}
                    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-xs p-6 space-y-5">
                        <div class="flex items-center justify-between border-b border-surface-container-highest pb-4">
                            <div>
                                <h2 class="text-sm font-bold text-on-surface flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary text-[20px]">outbox</span>
                                    <span>Cấu hình Hòm thư &amp; Tài khoản gửi SMTP</span>
                                </h2>
                                <p class="text-xs text-on-surface-variant mt-0.5">Hệ thống sẽ dùng tài khoản này để gửi mail thông báo và email thử nghiệm mà không phụ thuộc file .env</p>
                            </div>
                            <x-ui.badge color="success" :pill="true">Lưu Database</x-ui.badge>
                        </div>

                        {{-- Quick Presets for SMTP --}}
                        <div class="bg-surface-container-low p-3 rounded-xl border border-surface-container-highest flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-on-surface-variant flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-primary text-[18px]">bolt</span>
                                <span>Cấu hình nhanh 1 chạm:</span>
                            </span>
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button variant="secondary" size="sm" x-on:click="applyGmailPreset()"><span class="material-symbols-outlined text-error text-[16px]">mail</span><span>Gmail / Google Workspace (TLS 587)</span></x-ui.button>
                                <x-ui.button variant="secondary" size="sm" x-on:click="applyDomainPreset()"><span class="material-symbols-outlined text-secondary text-[16px]">dns</span><span>Mail Tên Miền / VPS (SSL 465)</span></x-ui.button>
                            </div>
                        </div>

                        {{-- SMTP Fields Grid --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                            {{-- Host --}}
                            <div>
                                <x-ui.input label="Máy chủ gửi thư (SMTP Host)" name="mail_host" x-model="host" required placeholder="smtp.gmail.com" class="font-mono" />
                                <span class="text-[10px] text-on-surface-variant/70 mt-1 block">Gmail: <code>smtp.gmail.com</code> | Mail tên miền: <code>mail.meducation.vn</code></span>
                            </div>

                            {{-- Port & Encryption --}}
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <x-ui.input type="number" label="Cổng (Port)" name="mail_port" x-model="port" required placeholder="587" class="font-mono" />
                                    <span class="text-[10px] text-on-surface-variant/70 mt-1 block">TLS: <code>587</code> | SSL: <code>465</code></span>
                                </div>
                                <div>
                                    <x-ui.select label="Mã hóa (Encryption)" name="mail_encryption" x-model="encryption" class="font-mono">
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                        <option value="">Không mã hóa</option>
                                    </x-ui.select>
                                </div>
                            </div>

                            {{-- Username --}}
                            <div>
                                <x-ui.input type="email" label="Tài khoản / Email đăng nhập SMTP" name="mail_username" x-model="username" required placeholder="tech.vmst@gmail.com" class="font-mono" />
                                <span class="text-[10px] text-on-surface-variant/70 mt-1 block">Tài khoản email dùng để xác thực với máy chủ SMTP</span>
                            </div>

                            {{-- Password / App Password --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label for="f_mail_password" class="block font-semibold text-on-surface-variant">Mật khẩu ứng dụng (App Password) <span class="text-error">*</span></label>
                                    <button 
                                        type="button" 
                                        @click="showPassword = !showPassword" 
                                        class="text-[11px] text-primary hover:underline flex items-center gap-1 cursor-pointer"
                                    >
                                        <span class="material-symbols-outlined text-[13px]" x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
                                        <span x-text="showPassword ? 'Ẩn' : 'Hiện'"></span>
                                    </button>
                                </div>
                                <div class="relative">
                                    <x-ui.input type="password" x-bind:type="showPassword ? 'text' : 'password'" name="mail_password"
                                        placeholder="{{ $mailConfig['has_password'] ? '•••••••••••••••• (Đã cấu hình mật khẩu, nhập mới nếu muốn đổi)' : 'Nhập 16 ký tự Mật khẩu ứng dụng Gmail...' }}"
                                        class="font-mono pr-8" />
                                </div>
                                <span class="text-[10px] text-on-surface-variant/70 mt-1 block">Đối với @gmail.com: dùng <strong>Mật khẩu ứng dụng 16 ký tự</strong> (không dùng mật khẩu đăng nhập cá nhân)</span>
                            </div>

                            {{-- From Address --}}
                            <div>
                                <x-ui.input type="email" label="Email người gửi hiển thị (From Address)" name="mail_from_address" x-model="fromAddress" placeholder="tech.vmst@gmail.com" class="font-mono" />
                                <span class="text-[10px] text-on-surface-variant/70 mt-1 block">Thường để trùng với email đăng nhập ở trên</span>
                            </div>

                            {{-- From Name --}}
                            <div>
                                <x-ui.input label="Tên người gửi hiển thị (From Name)" name="mail_from_name" x-model="fromName" placeholder="MEnglish Support" />
                                <span class="text-[10px] text-on-surface-variant/70 mt-1 block">Tên hiển thị trong hộp thư người nhận (ví dụ: MEnglish Support)</span>
                            </div>
                        </div>

                        {{-- Guide on Gmail App Password --}}
                        <x-ui.alert type="warning" title="Hướng dẫn lấy Mật khẩu ứng dụng (App Password) cho tài khoản @gmail.com:" class="text-xs">
                            <ol class="list-decimal list-inside space-y-1 text-[11px] pl-1 leading-relaxed">
                                <li>Truy cập <a href="https://myaccount.google.com/security" target="_blank" class="text-secondary underline font-semibold">myaccount.google.com/security</a> ➔ Đảm bảo đã bật <strong>Xác minh 2 bước (2-Step Verification)</strong>.</li>
                                <li>Vào mục <strong>Mật khẩu ứng dụng (App Passwords)</strong> (hoặc gõ tìm kiếm "App Passwords" ở thanh tìm kiếm trên trang Google).</li>
                                <li>Đặt tên ứng dụng là <code>MEnglish</code> rồi bấm <strong>Tạo (Create)</strong>.</li>
                                <li>Google sẽ hiện một mã <strong>16 chữ cái</strong> (ví dụ: <code>abcd efgh ijkl mnop</code>). Sao chép và dán vào ô <strong>Mật khẩu ứng dụng</strong> ở trên.</li>
                            </ol>
                        </x-ui.alert>
                    </div>

                    {{-- Card 3: Notification Triggers / Events --}}
                    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-xs p-6 space-y-6">
                        <div class="border-b border-surface-container-highest pb-3">
                            <h2 class="text-sm font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[20px]">notifications_active</span>
                                <span>Các sự kiện tự động kích hoạt gửi Email</span>
                            </h2>
                            <p class="text-xs text-on-surface-variant mt-0.5">Tùy chỉnh các trường hợp hệ thống sẽ tự động gửi email thông báo tới danh sách email cấu hình ở trên</p>
                        </div>

                        {{-- Group 1: Tickets & Support --}}
                        <div class="space-y-3">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-primary">confirmation_number</span>
                                <span>1. Hỗ trợ Kỹ thuật &amp; Ticket</span>
                            </div>

                            {{-- Trigger 1: On created --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_created" 
                                    value="1" 
                                    {{ $isCreatedEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface flex items-center gap-2">
                                        <span>Khi có Ticket mới được tạo</span>
                                        <x-ui.badge color="warning" :pill="true">Khuyên dùng</x-ui.badge>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Gửi email kèm mã ticket, phân loại, mức độ ưu tiên và mô tả chi tiết ngay khi có ticket mới.</p>
                                </div>
                            </label>

                            {{-- Trigger 2: On comment / message --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_comment" 
                                    value="1" 
                                    {{ $isCommentEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface">Khi có phản hồi / tin nhắn trao đổi mới</div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Gửi email thông báo nội dung trao đổi mới nhất để các bên nắm tiến độ mà không cần mở trang web liên tục.</p>
                                </div>
                            </label>

                            {{-- Trigger 3: On status changed --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_status_changed" 
                                    value="1" 
                                    {{ $isStatusChangedEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface">Khi cập nhật trạng thái Ticket (Đang xử lý, Hoàn thành, Đóng)</div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Bắn email thông báo người thực hiện và tiến độ giải quyết sự cố đến các bên liên quan.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Group 2: CRM & Leads --}}
                        <div class="space-y-3 pt-2 border-t border-surface-container-highest">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-secondary">campaign</span>
                                <span>2. CRM &amp; Tuyển sinh</span>
                            </div>

                            {{-- Trigger 4: Stale lead >24h --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_stale_lead" 
                                    value="1" 
                                    {{ $isStaleLeadEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface flex items-center gap-2">
                                        <span>Khi có thông báo Lead CRM trễ / sót chăm sóc (&gt;24h)</span>
                                        <x-ui.badge color="error" :pill="true">Cảnh báo</x-ui.badge>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Bắn email cảnh báo khi khách hàng tiềm năng tiếp nhận quá 24 giờ mà chưa được nhân sự liên hệ chăm sóc.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Group 3: Financial & Transactions --}}
                        <div class="space-y-3 pt-2 border-t border-surface-container-highest">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-tertiary">payments</span>
                                <span>3. Tài chính &amp; Thu chi (Giao dịch)</span>
                            </div>

                            {{-- Trigger 5: New transaction / receipt --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_transaction" 
                                    value="1" 
                                    {{ $isTransactionEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface flex items-center gap-2">
                                        <span>Khi có giao dịch thanh toán / phiếu thu mới (SePay, Chuyển khoản, Tiền mặt)</span>
                                        <x-ui.badge color="success" :pill="true">Kế toán</x-ui.badge>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Bắn email thông báo số tiền, mã phiếu thu và thông tin học viên ngay khi có giao dịch thanh toán được ghi nhận.</p>
                                </div>
                            </label>

                            {{-- Trigger 6: Overdue debt reminder --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_overdue_debt" 
                                    value="1" 
                                    {{ $isOverdueDebtEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface flex items-center gap-2">
                                        <span>Khi có phiếu thu trễ hẹn / học viên quá hạn đóng học phí</span>
                                        <x-ui.badge color="warning" :pill="true">Nhắc nợ</x-ui.badge>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Bắn email thông báo danh sách học viên và số tiền trễ hạn khi kích hoạt gửi nhắc nợ hoặc quét công nợ quá hạn.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Group 4: Academic & Homework --}}
                        <div class="space-y-3 pt-2 border-t border-surface-container-highest">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-secondary">school</span>
                                <span>4. Học vụ &amp; Đào tạo (Học viên &amp; Lớp học)</span>
                            </div>

                            {{-- Trigger 7: Homework / test submission --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-surface-container-highest hover:bg-surface-container-low/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_homework" 
                                    value="1" 
                                    {{ $isHomeworkEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-on-surface flex items-center gap-2">
                                        <span>Khi có học viên nộp bài / trễ nộp bài tập hoặc kiểm tra</span>
                                        <x-ui.badge color="secondary" :pill="true">Học vụ</x-ui.badge>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Bắn email thông báo khi học viên nộp bài thi trên Portal, nộp bài tập video/ghi âm hoặc có cảnh báo trễ hạn nộp bài.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- Save Action Bar --}}
                    <div class="flex items-center justify-between p-4 bg-surface-container-low border border-surface-container-highest rounded-2xl">
                        <div class="text-xs text-on-surface-variant">
                            Cấu hình sẽ được lưu trực tiếp vào cơ sở dữ liệu và có hiệu lực ngay lập tức.
                        </div>
                        <x-ui.button type="submit" icon="save">Lưu cấu hình</x-ui.button>
                    </div>
                </form>
            </div>

            {{-- Right Column: Test Email Box & SMTP Specs --}}
            <div class="space-y-6">
                {{-- Test Email Trigger Box --}}
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-xs p-6 space-y-4">
                    <div class="flex items-center gap-2 border-b border-surface-container-highest pb-3">
                        <span class="material-symbols-outlined text-primary text-[22px]">science</span>
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Gửi Thử Nghiệm (Test Email)</h3>
                            <p class="text-[11px] text-on-surface-variant">Bắn email thử để kiểm tra kết nối SMTP và tài khoản vừa cấu hình</p>
                        </div>
                    </div>

                    <form action="{{ route('system-config.ticket-emails.test') }}" method="POST" class="space-y-3">
                        @csrf
                        <x-ui.input type="email" label="Gửi tới địa chỉ email kiểm tra:" name="test_email" :value="auth()->user()?->email ?? ''"
                            placeholder="Nhập email nhận thư test..." class="font-mono" required
                            hint="Để trống nếu muốn bắn đồng loạt tới tất cả email đã cấu hình ở bên trái." />

                        <x-ui.button type="submit" icon="send" class="w-full">Bắn Thử Email Ngay</x-ui.button>
                    </form>
                </div>

                {{-- SMTP Server Diagnostics (Live DB values) --}}
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-xs p-6 space-y-4">
                    <div class="flex items-center gap-2 border-b border-surface-container-highest pb-3">
                        <span class="material-symbols-outlined text-primary text-[22px]">dns</span>
                        <div>
                            <h3 class="text-sm font-bold text-on-surface">Trạng Thái Kết Nối SMTP</h3>
                            <p class="text-[11px] text-on-surface-variant">Thông số gửi thư đang áp dụng</p>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Trình gửi (Driver):</span>
                            <span class="font-mono font-bold text-on-surface uppercase px-2 py-0.5 rounded bg-surface-container text-[10px]">{{ $mailConfig['driver'] }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Máy chủ SMTP (Host):</span>
                            <span class="font-mono text-on-surface text-[11px] font-bold">{{ $mailConfig['host'] ?? 'smtp.gmail.com' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Cổng kết nối (Port):</span>
                            <span class="font-mono text-on-surface">{{ $mailConfig['port'] ?? 587 }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Giao thức mã hóa:</span>
                            <span class="font-mono text-on-surface uppercase text-[11px]">{{ $mailConfig['encryption'] ?? 'TLS' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Tài khoản SMTP:</span>
                            <span class="font-mono text-on-surface text-[11px] truncate max-w-[150px]" title="{{ $mailConfig['username'] }}">{{ $mailConfig['username'] ?: 'Chưa cấu hình' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Mật khẩu ứng dụng:</span>
                            <span class="text-tertiary font-medium">{{ $mailConfig['has_password'] ? '●●●● Đã thiết lập' : 'Chưa có' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-surface-container-highest">
                            <span class="text-on-surface-variant">Địa chỉ người gửi:</span>
                            <span class="font-mono text-on-surface text-[11px] truncate max-w-[150px]" title="{{ $mailConfig['from_address'] }}">{{ $mailConfig['from_address'] ?? 'noreply' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1">
                            <span class="text-on-surface-variant">Tên người gửi:</span>
                            <span class="text-on-surface font-medium">{{ $mailConfig['from_name'] ?? 'MEnglish Support' }}</span>
                        </div>
                    </div>

                    <x-ui.alert type="info" class="text-[11px] leading-relaxed">
                        <strong>Lưu ý:</strong> Sau khi nhập Mật khẩu ứng dụng Gmail và nhấn <strong>Lưu Cấu Hình</strong>, bạn hãy dùng khung <strong>Gửi Thử Nghiệm</strong> ở trên để xác nhận email gửi đi thành công mà không cần kiểm tra log server.
                    </x-ui.alert>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
