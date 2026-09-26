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
        <div class="flex items-center gap-2 border-b border-gray-200 pb-2 overflow-x-auto">
            <a 
                href="{{ route('system-config.bank-accounts') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">account_balance_wallet</span>
                <span>Tài khoản Ngân hàng</span>
            </a>

            <a 
                href="{{ route('system-config.debt-reminders') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">notifications_active</span>
                <span>Mẫu nhắc nợ</span>
            </a>

            <a 
                href="{{ route('system-config.ticket-emails') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-primary-container bg-primary-container text-white font-bold shadow-xs shrink-0"
            >
                <span class="material-symbols-outlined text-base">mail</span>
                <span>Email &amp; SMTP Ticket (<span x-text="emails.length"></span>)</span>
            </a>

            <a 
                href="{{ route('system-config.hosting') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">dns</span>
                <span>Hosting &amp; Máy chủ</span>
            </a>
        </div>

        {{-- Session Status & Alerts --}}

        

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left Column: Form config --}}
            <div class="lg:col-span-2 space-y-6">
                <form action="{{ route('system-config.ticket-emails.update') }}" method="POST" class="space-y-6">
                    @csrf

                    {{-- Card 1: Multi-email Tag Input (n emails) --}}
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-xs p-6 space-y-5">
                        <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                            <div>
                                <h2 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary text-[20px]">alternate_email</span>
                                    <span>Danh sách Email nhận thông báo Ticket (Không giới hạn)</span>
                                </h2>
                                <p class="text-xs text-gray-500 mt-0.5">Thêm n email kỹ thuật, quản lý trung tâm hoặc ban giám đốc để nhận thông báo tức thì</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button 
                                    type="button" 
                                    x-show="emails.length > 1" 
                                    @click="clearAllEmails()" 
                                    class="text-[11px] text-rose-600 hover:text-rose-800 font-semibold hover:underline cursor-pointer"
                                >
                                    Xóa tất cả
                                </button>
                                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-orange-50 text-primary border border-orange-200" x-text="emails.length + ' email đã cấu hình'"></span>
                            </div>
                        </div>

                        {{-- Email Chips Area --}}
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-2">Các email đang nhận thông báo:</label>
                            
                            <div class="min-h-[85px] p-3 rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/50 flex flex-wrap gap-2 items-center">
                                <template x-for="(email, idx) in emails" :key="idx">
                                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-gray-300 text-gray-800 text-xs font-medium shadow-2xs hover:border-orange-300 transition group">
                                        <span class="material-symbols-outlined text-[16px] text-primary">mail</span>
                                        <span class="font-mono text-gray-900" x-text="email"></span>
                                        <button 
                                            type="button" 
                                            @click.stop.prevent="removeEmail(idx)" 
                                            class="w-5 h-5 ml-1 rounded-full bg-gray-100 group-hover:bg-rose-100 text-gray-400 group-hover:text-rose-600 flex items-center justify-center transition cursor-pointer"
                                            title="Xóa email này khỏi danh sách"
                                        >
                                            <span class="material-symbols-outlined text-[14px]">close</span>
                                        </button>
                                        {{-- Hidden Input submitted with the form --}}
                                        <input type="hidden" name="emails[]" :value="email">
                                    </div>
                                </template>

                                <div x-show="emails.length === 0" class="text-xs text-gray-400 italic py-2 px-1 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[18px]">info</span>
                                    <span>Chưa có email nào trong danh sách. Hãy nhập email bên dưới và nhấn <strong>Thêm</strong>.</span>
                                </div>
                            </div>
                        </div>

                        {{-- Add Email Input Bar --}}
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-gray-700">Thêm email mới vào danh sách:</label>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-gray-400 text-[18px]">add_link</span>
                                    <input 
                                        type="text" 
                                        x-model="newEmail" 
                                        @keydown.enter.prevent="addEmail()" 
                                        placeholder="Nhập email (ví dụ: cskh@menglish.edu.vn) rồi nhấn Enter hoặc bấm Thêm..." 
                                        class="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-gray-300 focus:ring-2 focus:ring-primary-container focus:border-primary-container font-mono"
                                    />
                                </div>
                                <button 
                                    type="button" 
                                    @click="addEmail()" 
                                    class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5 shrink-0 cursor-pointer"
                                >
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    <span>Thêm</span>
                                </button>
                            </div>

                            {{-- Error feedback for invalid or duplicate email --}}
                            <div x-show="errorMessage" x-cloak class="text-[11px] text-rose-600 font-semibold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">warning</span>
                                <span x-text="errorMessage"></span>
                            </div>
                        </div>

                        {{-- Quick Suggestions / Presets --}}
                        <div class="pt-1">
                            <span class="text-[11px] text-gray-500 font-medium mr-1.5">Gợi ý thêm nhanh:</span>
                            <div class="inline-flex flex-wrap gap-1.5 mt-1">
                                @if(auth()->user()?->email)
                                    <button 
                                        type="button" 
                                        @click="addPreset('{{ auth()->user()->email }}')"
                                        class="px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-[11px] font-medium transition inline-flex items-center gap-1 cursor-pointer"
                                    >
                                        <span class="material-symbols-outlined text-[13px] text-gray-500">person</span>
                                        <span>Email của tôi ({{ auth()->user()->email }})</span>
                                    </button>
                                @endif
                                <button 
                                    type="button" 
                                    @click="addPreset('tech.vmst@gmail.com')"
                                    class="px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-[11px] font-medium transition inline-flex items-center gap-1 cursor-pointer"
                                >
                                    <span class="material-symbols-outlined text-[13px] text-gray-500">build</span>
                                    <span>tech.vmst@gmail.com</span>
                                </button>
                                <button 
                                    type="button" 
                                    @click="addPreset('nhungmagnet.001@gmail.com')"
                                    class="px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-[11px] font-medium transition inline-flex items-center gap-1 cursor-pointer"
                                >
                                    <span class="material-symbols-outlined text-[13px] text-gray-500">mail</span>
                                    <span>nhungmagnet.001@gmail.com</span>
                                </button>
                                <button 
                                    type="button" 
                                    @click="addPreset('admin@meducation.vn')"
                                    class="px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-[11px] font-medium transition inline-flex items-center gap-1 cursor-pointer"
                                >
                                    <span class="material-symbols-outlined text-[13px] text-gray-500">shield_person</span>
                                    <span>admin@meducation.vn</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Card 2: Cấu hình Tài khoản Gửi thư SMTP --}}
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-xs p-6 space-y-5">
                        <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                            <div>
                                <h2 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary text-[20px]">outbox</span>
                                    <span>Cấu hình Hòm thư &amp; Tài khoản gửi SMTP</span>
                                </h2>
                                <p class="text-xs text-gray-500 mt-0.5">Hệ thống sẽ dùng tài khoản này để gửi mail thông báo và email thử nghiệm mà không phụ thuộc file .env</p>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Lưu Database</span>
                        </div>

                        {{-- Quick Presets for SMTP --}}
                        <div class="bg-gray-50 p-3 rounded-xl border border-gray-200 flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-primary text-[18px]">bolt</span>
                                <span>Cấu hình nhanh 1 chạm:</span>
                            </span>
                            <div class="flex flex-wrap gap-2">
                                <button 
                                    type="button" 
                                    @click="applyGmailPreset()"
                                    class="px-3 py-1.5 bg-white border border-gray-300 hover:border-orange-400 text-gray-700 hover:text-orange-600 rounded-lg text-xs font-semibold transition flex items-center gap-1 shadow-2xs cursor-pointer"
                                >
                                    <span class="material-symbols-outlined text-red-500 text-[16px]">mail</span>
                                    <span>Gmail / Google Workspace (TLS 587)</span>
                                </button>
                                <button 
                                    type="button" 
                                    @click="applyDomainPreset()"
                                    class="px-3 py-1.5 bg-white border border-gray-300 hover:border-orange-400 text-gray-700 hover:text-orange-600 rounded-lg text-xs font-semibold transition flex items-center gap-1 shadow-2xs cursor-pointer"
                                >
                                    <span class="material-symbols-outlined text-blue-500 text-[16px]">dns</span>
                                    <span>Mail Tên Miền / VPS (SSL 465)</span>
                                </button>
                            </div>
                        </div>

                        {{-- SMTP Fields Grid --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                            {{-- Host --}}
                            <div>
                                <label class="block font-semibold text-gray-700 mb-1">Máy chủ gửi thư (SMTP Host) <span class="text-rose-500">*</span></label>
                                <input 
                                    type="text" 
                                    name="mail_host" 
                                    x-model="host" 
                                    required 
                                    placeholder="smtp.gmail.com" 
                                    class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container"
                                />
                                <span class="text-[10px] text-gray-400 mt-1 block">Gmail: <code>smtp.gmail.com</code> | Mail tên miền: <code>mail.meducation.vn</code></span>
                            </div>

                            {{-- Port & Encryption --}}
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block font-semibold text-gray-700 mb-1">Cổng (Port) <span class="text-rose-500">*</span></label>
                                    <input 
                                        type="number" 
                                        name="mail_port" 
                                        x-model="port" 
                                        required 
                                        placeholder="587" 
                                        class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container"
                                    />
                                    <span class="text-[10px] text-gray-400 mt-1 block">TLS: <code>587</code> | SSL: <code>465</code></span>
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-700 mb-1">Mã hóa (Encryption)</label>
                                    <select 
                                        name="mail_encryption" 
                                        x-model="encryption" 
                                        class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container"
                                    >
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                        <option value="">Không mã hóa</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Username --}}
                            <div>
                                <label class="block font-semibold text-gray-700 mb-1">Tài khoản / Email đăng nhập SMTP <span class="text-rose-500">*</span></label>
                                <input 
                                    type="email" 
                                    name="mail_username" 
                                    x-model="username" 
                                    required 
                                    placeholder="tech.vmst@gmail.com" 
                                    class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container"
                                />
                                <span class="text-[10px] text-gray-400 mt-1 block">Tài khoản email dùng để xác thực với máy chủ SMTP</span>
                            </div>

                            {{-- Password / App Password --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block font-semibold text-gray-700">Mật khẩu ứng dụng (App Password) <span class="text-rose-500">*</span></label>
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
                                    <input 
                                        :type="showPassword ? 'text' : 'password'" 
                                        name="mail_password" 
                                        placeholder="{{ $mailConfig['has_password'] ? '•••••••••••••••• (Đã cấu hình mật khẩu, nhập mới nếu muốn đổi)' : 'Nhập 16 ký tự Mật khẩu ứng dụng Gmail...' }}" 
                                        class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container pr-8"
                                    />
                                </div>
                                <span class="text-[10px] text-gray-400 mt-1 block">Đối với @gmail.com: dùng <strong>Mật khẩu ứng dụng 16 ký tự</strong> (không dùng mật khẩu đăng nhập cá nhân)</span>
                            </div>

                            {{-- From Address --}}
                            <div>
                                <label class="block font-semibold text-gray-700 mb-1">Email người gửi hiển thị (From Address)</label>
                                <input 
                                    type="email" 
                                    name="mail_from_address" 
                                    x-model="fromAddress" 
                                    placeholder="tech.vmst@gmail.com" 
                                    class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container"
                                />
                                <span class="text-[10px] text-gray-400 mt-1 block">Thường để trùng với email đăng nhập ở trên</span>
                            </div>

                            {{-- From Name --}}
                            <div>
                                <label class="block font-semibold text-gray-700 mb-1">Tên người gửi hiển thị (From Name)</label>
                                <input 
                                    type="text" 
                                    name="mail_from_name" 
                                    x-model="fromName" 
                                    placeholder="MEnglish Support" 
                                    class="w-full text-xs rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container"
                                />
                                <span class="text-[10px] text-gray-400 mt-1 block">Tên hiển thị trong hộp thư người nhận (ví dụ: MEnglish Support)</span>
                            </div>
                        </div>

                        {{-- Guide on Gmail App Password --}}
                        <div class="p-4 bg-amber-50/80 border border-amber-200 rounded-2xl text-xs text-amber-900 space-y-2">
                            <div class="font-bold flex items-center gap-1.5 text-amber-950">
                                <span class="material-symbols-outlined text-amber-600 text-[18px]">key</span>
                                <span>Hướng dẫn lấy Mật khẩu ứng dụng (App Password) cho tài khoản @gmail.com:</span>
                            </div>
                            <ol class="list-decimal list-inside space-y-1 text-[11px] text-amber-900/90 pl-1 leading-relaxed">
                                <li>Truy cập <a href="https://myaccount.google.com/security" target="_blank" class="text-blue-600 underline font-semibold">myaccount.google.com/security</a> ➔ Đảm bảo đã bật <strong>Xác minh 2 bước (2-Step Verification)</strong>.</li>
                                <li>Vào mục <strong>Mật khẩu ứng dụng (App Passwords)</strong> (hoặc gõ tìm kiếm "App Passwords" ở thanh tìm kiếm trên trang Google).</li>
                                <li>Đặt tên ứng dụng là <code>MEnglish</code> rồi bấm <strong>Tạo (Create)</strong>.</li>
                                <li>Google sẽ hiện một mã <strong>16 chữ cái</strong> (ví dụ: <code>abcd efgh ijkl mnop</code>). Sao chép và dán vào ô <strong>Mật khẩu ứng dụng</strong> ở trên.</li>
                            </ol>
                        </div>
                    </div>

                    {{-- Card 3: Notification Triggers / Events --}}
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-xs p-6 space-y-6">
                        <div class="border-b border-gray-100 pb-3">
                            <h2 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[20px]">notifications_active</span>
                                <span>Các sự kiện tự động kích hoạt gửi Email</span>
                            </h2>
                            <p class="text-xs text-gray-500 mt-0.5">Tùy chỉnh các trường hợp hệ thống sẽ tự động gửi email thông báo tới danh sách email cấu hình ở trên</p>
                        </div>

                        {{-- Group 1: Tickets & Support --}}
                        <div class="space-y-3">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-orange-500">confirmation_number</span>
                                <span>1. Hỗ trợ Kỹ thuật &amp; Ticket</span>
                            </div>

                            {{-- Trigger 1: On created --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_created" 
                                    value="1" 
                                    {{ $isCreatedEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900 flex items-center gap-2">
                                        <span>Khi có Ticket mới được tạo</span>
                                        <span class="px-2 py-0.2 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Khuyên dùng</span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Gửi email kèm mã ticket, phân loại, mức độ ưu tiên và mô tả chi tiết ngay khi có ticket mới.</p>
                                </div>
                            </label>

                            {{-- Trigger 2: On comment / message --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_comment" 
                                    value="1" 
                                    {{ $isCommentEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900">Khi có phản hồi / tin nhắn trao đổi mới</div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Gửi email thông báo nội dung trao đổi mới nhất để các bên nắm tiến độ mà không cần mở trang web liên tục.</p>
                                </div>
                            </label>

                            {{-- Trigger 3: On status changed --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_status_changed" 
                                    value="1" 
                                    {{ $isStatusChangedEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900">Khi cập nhật trạng thái Ticket (Đang xử lý, Hoàn thành, Đóng)</div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Bắn email thông báo người thực hiện và tiến độ giải quyết sự cố đến các bên liên quan.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Group 2: CRM & Leads --}}
                        <div class="space-y-3 pt-2 border-t border-gray-100">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-blue-500">campaign</span>
                                <span>2. CRM &amp; Tuyển sinh</span>
                            </div>

                            {{-- Trigger 4: Stale lead >24h --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_stale_lead" 
                                    value="1" 
                                    {{ $isStaleLeadEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900 flex items-center gap-2">
                                        <span>Khi có thông báo Lead CRM trễ / sót chăm sóc (&gt;24h)</span>
                                        <span class="px-2 py-0.2 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">Cảnh báo</span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Bắn email cảnh báo khi khách hàng tiềm năng tiếp nhận quá 24 giờ mà chưa được nhân sự liên hệ chăm sóc.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Group 3: Financial & Transactions --}}
                        <div class="space-y-3 pt-2 border-t border-gray-100">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-emerald-500">payments</span>
                                <span>3. Tài chính &amp; Thu chi (Giao dịch)</span>
                            </div>

                            {{-- Trigger 5: New transaction / receipt --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_transaction" 
                                    value="1" 
                                    {{ $isTransactionEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900 flex items-center gap-2">
                                        <span>Khi có giao dịch thanh toán / phiếu thu mới (SePay, Chuyển khoản, Tiền mặt)</span>
                                        <span class="px-2 py-0.2 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Kế toán</span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Bắn email thông báo số tiền, mã phiếu thu và thông tin học viên ngay khi có giao dịch thanh toán được ghi nhận.</p>
                                </div>
                            </label>

                            {{-- Trigger 6: Overdue debt reminder --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_overdue_debt" 
                                    value="1" 
                                    {{ $isOverdueDebtEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900 flex items-center gap-2">
                                        <span>Khi có phiếu thu trễ hẹn / học viên quá hạn đóng học phí</span>
                                        <span class="px-2 py-0.2 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Nhắc nợ</span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Bắn email thông báo danh sách học viên và số tiền trễ hạn khi kích hoạt gửi nhắc nợ hoặc quét công nợ quá hạn.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Group 4: Academic & Homework --}}
                        <div class="space-y-3 pt-2 border-t border-gray-100">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-purple-500">school</span>
                                <span>4. Học vụ &amp; Đào tạo (Học viên &amp; Lớp học)</span>
                            </div>

                            {{-- Trigger 7: Homework / test submission --}}
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50/70 transition cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    name="notify_homework" 
                                    value="1" 
                                    {{ $isHomeworkEnabled ? 'checked' : '' }}
                                    class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container w-4 h-4 cursor-pointer"
                                />
                                <div>
                                    <div class="text-xs font-bold text-gray-900 flex items-center gap-2">
                                        <span>Khi có học viên nộp bài / trễ nộp bài tập hoặc kiểm tra</span>
                                        <span class="px-2 py-0.2 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">Học vụ</span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Bắn email thông báo khi học viên nộp bài thi trên Portal, nộp bài tập video/ghi âm hoặc có cảnh báo trễ hạn nộp bài.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- Save Action Bar --}}
                    <div class="flex items-center justify-between p-4 bg-gray-50 border border-gray-200 rounded-2xl">
                        <div class="text-xs text-gray-500">
                            Cấu hình sẽ được lưu trực tiếp vào cơ sở dữ liệu và có hiệu lực ngay lập tức.
                        </div>
                        <button 
                            type="submit" 
                            class="px-6 py-2.5 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-md transition flex items-center gap-2 cursor-pointer"
                        >
                            <span class="material-symbols-outlined text-[20px]">save</span>
                            <span>Lưu cấu hình</span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Right Column: Test Email Box & SMTP Specs --}}
            <div class="space-y-6">
                {{-- Test Email Trigger Box --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-xs p-6 space-y-4">
                    <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                        <span class="material-symbols-outlined text-primary text-[22px]">science</span>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Gửi Thử Nghiệm (Test Email)</h3>
                            <p class="text-[11px] text-gray-500">Bắn email thử để kiểm tra kết nối SMTP và tài khoản vừa cấu hình</p>
                        </div>
                    </div>

                    <form action="{{ route('system-config.ticket-emails.test') }}" method="POST" class="space-y-3">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Gửi tới địa chỉ email kiểm tra:</label>
                            <input 
                                type="email" 
                                name="test_email" 
                                value="{{ auth()->user()?->email ?? '' }}"
                                placeholder="Nhập email nhận thư test..." 
                                class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-mono focus:border-primary-container focus:ring-primary-container" 
                                required
                            />
                            <p class="text-[10px] text-gray-400 mt-1">Để trống nếu muốn bắn đồng loạt tới tất cả email đã cấu hình ở bên trái.</p>
                        </div>

                        <button 
                            type="submit" 
                            class="w-full py-2.5 bg-gray-900 hover:bg-black text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <span class="material-symbols-outlined text-[18px]">send</span>
                            <span>Bắn Thử Email Ngay</span>
                        </button>
                    </form>
                </div>

                {{-- SMTP Server Diagnostics (Live DB values) --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-xs p-6 space-y-4">
                    <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                        <span class="material-symbols-outlined text-primary text-[22px]">dns</span>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Trạng Thái Kết Nối SMTP</h3>
                            <p class="text-[11px] text-gray-500">Thông số gửi thư đang áp dụng</p>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Trình gửi (Driver):</span>
                            <span class="font-mono font-bold text-gray-800 uppercase px-2 py-0.5 rounded bg-gray-100 text-[10px]">{{ $mailConfig['driver'] }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Máy chủ SMTP (Host):</span>
                            <span class="font-mono text-gray-800 text-[11px] font-bold">{{ $mailConfig['host'] ?? 'smtp.gmail.com' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Cổng kết nối (Port):</span>
                            <span class="font-mono text-gray-800">{{ $mailConfig['port'] ?? 587 }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Giao thức mã hóa:</span>
                            <span class="font-mono text-gray-800 uppercase text-[11px]">{{ $mailConfig['encryption'] ?? 'TLS' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Tài khoản SMTP:</span>
                            <span class="font-mono text-gray-800 text-[11px] truncate max-w-[150px]" title="{{ $mailConfig['username'] }}">{{ $mailConfig['username'] ?: 'Chưa cấu hình' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Mật khẩu ứng dụng:</span>
                            <span class="text-emerald-600 font-medium">{{ $mailConfig['has_password'] ? '●●●● Đã thiết lập' : 'Chưa có' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-gray-50">
                            <span class="text-gray-500">Địa chỉ người gửi:</span>
                            <span class="font-mono text-gray-800 text-[11px] truncate max-w-[150px]" title="{{ $mailConfig['from_address'] }}">{{ $mailConfig['from_address'] ?? 'noreply' }}</span>
                        </div>
                        <div class="flex justify-between items-center py-1">
                            <span class="text-gray-500">Tên người gửi:</span>
                            <span class="text-gray-800 font-medium">{{ $mailConfig['from_name'] ?? 'MEnglish Support' }}</span>
                        </div>
                    </div>

                    <div class="p-3 bg-blue-50 border border-blue-100 rounded-xl text-[11px] text-blue-800 leading-relaxed">
                        <strong>Lưu ý:</strong> Sau khi nhập Mật khẩu ứng dụng Gmail và nhấn <strong>Lưu Cấu Hình</strong>, bạn hãy dùng khung <strong>Gửi Thử Nghiệm</strong> ở trên để xác nhận email gửi đi thành công mà không cần kiểm tra log server.
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
