<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">account_balance</span>
                    <span>Tài Khoản Ngân Hàng &amp; Kết Nối SePay Gateway</span>
                </h1>
                <p class="text-xs text-gray-500">Quản lý số tài khoản thụ hưởng học phí, cấu hình mã VietQR và tích hợp Webhook SePay tự động gạch nợ</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="document.getElementById('newBankModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-semibold shadow-xs transition">
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Thêm tài khoản ngân hàng</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="systemBankSepayManager()">
        <!-- Top Nav Tabs matching System Config -->
        <div class="flex items-center gap-2 border-b border-gray-200 pb-2 overflow-x-auto">
            <a 
                href="{{ route('system-config.bank-accounts') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-primary-container bg-primary-container text-white font-bold shadow-xs shrink-0"
            >
                <span class="material-symbols-outlined text-base">account_balance_wallet</span>
                <span>Tài khoản Ngân hàng &amp; SePay</span>
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
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">mail</span>
                <span>Email nhận Ticket</span>
            </a>

            <a 
                href="{{ route('system-config.hosting') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">dns</span>
                <span>Hosting &amp; Máy chủ</span>
            </a>
        </div>

        <!-- Sub Nav Tabs (Banks, SePay, Logs) -->
        <div class="flex items-center gap-2 pb-1 overflow-x-auto">
            <button 
                type="button" 
                @click="activeTab = 'banks'"
                :class="activeTab === 'banks' ? 'bg-primary-container text-white font-bold shadow-xs' : 'bg-white text-gray-600 hover:bg-gray-100 font-semibold'"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200"
            >
                <span class="material-symbols-outlined text-base">account_balance_wallet</span>
                <span>Tài khoản Ngân hàng ({{ count($accounts) }})</span>
            </button>

            @if ($sepayEnabled)
            <button
                type="button"
                @click="activeTab = 'sepay'"
                :class="activeTab === 'sepay' ? 'bg-primary-container text-white font-bold shadow-xs' : 'bg-white text-gray-600 hover:bg-gray-100 font-semibold'"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200"
            >
                <span class="material-symbols-outlined text-base">webhook</span>
                <span>Cấu hình Webhook SePay Gateway</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold {{ $sepayConfig->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-600' }}">
                    {{ $sepayConfig->is_active ? 'ĐANG BẬT' : 'TẮT' }}
                </span>
            </button>

            <button
                type="button"
                @click="activeTab = 'logs'"
                :class="activeTab === 'logs' ? 'bg-primary-container text-white font-bold shadow-xs' : 'bg-white text-gray-600 hover:bg-gray-100 font-semibold'"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200"
            >
                <span class="material-symbols-outlined text-base">receipt_long</span>
                <span>Nhật ký Giao dịch SePay ({{ count($recentTransactions) }})</span>
            </button>
            @else
            <div class="px-4 py-2 rounded-xl text-xs bg-gray-100 text-gray-500 border border-dashed border-gray-300 flex items-center gap-1.5" title="Đặt SEPAY_WEBHOOK_ENABLED=true trong .env để bật lại">
                <span class="material-symbols-outlined text-base">webhook_off</span>
                <span>SePay webhook đang tắt tạm thời</span>
            </div>
            @endif
        </div>

        <!-- ═════════════════════════════════════════════════════════════════
             TAB 1: QUẢN LÝ TÀI KHOẢN NGÂN HÀNG (ĐỔI STK DỄ DÀNG TỪ ADMIN)
             ═════════════════════════════════════════════════════════════════ -->
        <div x-show="activeTab === 'banks'" class="space-y-4">
            <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-900 flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-600 text-lg shrink-0 mt-0.5">info</span>
                <div>
                    <strong>Quản trị STK linh hoạt:</strong> Bạn có thể thêm, sửa đổi STK, tên chủ tài khoản hoặc ngân hàng bất kỳ lúc nào tại đây. Tài khoản được tích chọn <strong>"Mặc định VietQR"</strong> sẽ tự động được sử dụng trong Closing Wizard và tạo mã QR in trên Thông báo học phí.
                </div>
            </div>

            <!-- Bank Accounts Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                @forelse ($accounts as $acc)
                    <div class="bg-white rounded-2xl p-5 border {{ $acc->is_default_vietqr ? 'border-primary-container ring-2 ring-primary-container/10' : 'border-gray-200' }} shadow-xs space-y-4 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="px-2.5 py-1 rounded-lg bg-orange-50 text-primary-container font-mono font-black text-xs border border-orange-100">{{ $acc->bank_code }}</span>
                                <div class="flex items-center gap-1.5">
                                    @if ($acc->is_default_vietqr)
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Mặc định VietQR
                                        </span>
                                    @else
                                        <form action="{{ route('system-config.bank-accounts.default', $acc->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-[10px] text-gray-500 hover:text-primary-container underline">Đặt làm mặc định</button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="text-xs text-gray-400 font-semibold truncate" title="{{ $acc->bank_name }}">{{ $acc->bank_name }}</div>
                                <div class="text-xl font-black font-mono text-gray-900 mt-1 tracking-wider flex items-center justify-between">
                                    <span>{{ $acc->account_number }}</span>
                                    <button 
                                        type="button" 
                                        @click="copyVal('{{ preg_replace('/\s+/', '', $acc->account_number) }}', 'stk_{{ $acc->id }}')" 
                                        class="p-1 rounded text-gray-400 hover:text-gray-700" 
                                        title="Sao chép STK"
                                    >
                                        <span class="material-symbols-outlined text-sm" x-text="copiedTag === 'stk_{{ $acc->id }}' ? 'check' : 'content_copy'"></span>
                                    </button>
                                </div>
                                <div class="text-xs font-bold text-gray-700 mt-1 uppercase">{{ $acc->account_holder }}</div>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                            <span class="text-gray-400 truncate max-w-[150px]">{{ $acc->branch?->name ?? $acc->branch_location ?? 'Toàn hệ thống' }}</span>
                            <div class="flex items-center gap-2">
                                <button 
                                    type="button" 
                                    @click="openEditModal({{ json_encode($acc) }})" 
                                    class="p-1 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" 
                                    title="Sửa thông tin STK"
                                >
                                    <span class="material-symbols-outlined text-sm">edit</span>
                                </button>
                                <form action="{{ route('system-config.bank-accounts.destroy', $acc->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa tài khoản ngân hàng này?')" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                                        <span class="material-symbols-outlined text-sm">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-3 text-center py-12 text-gray-400 text-xs bg-white rounded-2xl border border-gray-200">
                        Chưa có tài khoản ngân hàng nào. Vui lòng bấm "Thêm tài khoản ngân hàng".
                    </div>
                @endforelse
            </div>
        </div>

        <!-- ═════════════════════════════════════════════════════════════════
             TAB 2: CẤU HÌNH WEBHOOK SEPAY GATEWAY
             ═════════════════════════════════════════════════════════════════ -->
        @if ($sepayEnabled)
        <div x-show="activeTab === 'sepay'" class="space-y-6">
            <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-xs space-y-6 max-w-4xl">
                <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                    <div>
                        <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary-container">lock_reset</span>
                            <span>Cấu hình Webhook SePay Gateway (Tự động Gạch Nợ &amp; Xác Thực)</span>
                        </h2>
                        <p class="text-xs text-gray-500 mt-0.5">Copy đường dẫn URL Webhook và Secret Key này lên trang quản trị SePay để kết nối</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-bold {{ $sepayConfig->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-100 text-gray-600' }}">
                        {{ $sepayConfig->is_active ? '● Đang Kích Hoạt' : '○ Tạm Dừng' }}
                    </span>
                </div>

                <form action="{{ route('system-config.sepay.update') }}" method="POST" class="space-y-5">
                    @csrf

                    <!-- 1. Thông tin cơ bản -->
                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider text-primary flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm">tune</span>
                            <span>Thông tin cơ bản Webhook</span>
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">
                                    Tên webhook <span class="text-rose-500">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    name="webhook_name" 
                                    value="{{ old('webhook_name', $sepayConfig->webhook_name) }}" 
                                    required 
                                    class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold text-gray-800 focus:border-primary-container focus:ring-primary-container" 
                                    placeholder="Xác Thực Thanh Toán Meducation" 
                                />
                                <p class="text-[11px] text-gray-400 mt-1">Đặt tên dễ nhớ để phân biệt các webhook trong danh sách SePay.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">
                                    Loại giao dịch <span class="text-rose-500">*</span>
                                </label>
                                <select name="transaction_type" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold text-gray-800 focus:border-primary-container focus:ring-primary-container">
                                    <option value="in" {{ $sepayConfig->transaction_type === 'in' ? 'selected' : '' }}>Tiền vào (Thu học phí - Khuyên dùng)</option>
                                    <option value="out" {{ $sepayConfig->transaction_type === 'out' ? 'selected' : '' }}>Tiền ra</option>
                                    <option value="all" {{ $sepayConfig->transaction_type === 'all' ? 'selected' : '' }}>Tất cả (Tiền vào &amp; Tiền ra)</option>
                                </select>
                            </div>
                        </div>

                        @php
                            $currentEndpoint = url('/hook/sepay-gateway/v1/add-payment');
                            $savedHost = parse_url($sepayConfig->webhook_url, PHP_URL_HOST);
                            $currentHost = request()->getHost();
                            $isDifferentHost = $savedHost && $savedHost !== $currentHost && !in_array($currentHost, ['127.0.0.1', 'localhost']);
                        @endphp

                        @if ($isDifferentHost)
                            <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">
                                <div class="flex items-start gap-2">
                                    <span class="material-symbols-outlined text-amber-600 text-base shrink-0 mt-0.5">warning</span>
                                    <div>
                                        <span class="font-bold">Cảnh báo khác tên miền:</span> URL Webhook trong CSDL đang trỏ đến <code class="bg-amber-100 px-1 py-0.5 rounded text-amber-800 font-bold font-mono">{{ $savedHost }}</code>, khác với tên miền bạn đang truy cập (<code class="bg-white px-1 py-0.5 rounded text-gray-900 font-bold font-mono">{{ $currentHost }}</code>).
                                    </div>
                                </div>
                                <button 
                                    type="button" 
                                    @click="document.getElementById('sepayWebhookUrlInput').value = '{{ $currentEndpoint }}'"
                                    class="px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold transition flex items-center justify-center gap-1 shrink-0 shadow-2xs"
                                >
                                    <span class="material-symbols-outlined text-sm">sync</span>
                                    <span>Đổi sang {{ $currentHost }}</span>
                                </button>
                            </div>
                        @endif

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1 flex items-center justify-between">
                                <span>URL nhận webhook <span class="text-rose-500">*</span></span>
                                <span class="text-[11px] text-gray-400">SePay gửi dữ liệu giao dịch đến URL này khi có tiền vào</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <input 
                                    type="url" 
                                    id="sepayWebhookUrlInput" 
                                    name="webhook_url" 
                                    value="{{ old('webhook_url', $sepayConfig->webhook_url ?: $currentEndpoint) }}" 
                                    required 
                                    class="flex-1 text-xs font-mono font-bold text-primary-container bg-orange-50/50 rounded-xl border border-orange-200 p-2.5 focus:border-primary-container focus:ring-primary-container" 
                                />
                                <button 
                                    type="button" 
                                    @click="copyVal(document.getElementById('sepayWebhookUrlInput').value, 'webhook_url')" 
                                    class="px-3.5 py-2.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold transition flex items-center gap-1.5 shrink-0 border border-gray-200"
                                >
                                    <span class="material-symbols-outlined text-sm" x-text="copiedTag === 'webhook_url' ? 'check' : 'content_copy'"></span>
                                    <span x-text="copiedTag === 'webhook_url' ? 'Đã sao chép!' : 'Sao chép URL'"></span>
                                </button>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-[11px] text-gray-500">
                                <div class="flex items-center gap-2">
                                    <span>Đường dẫn endpoint chuẩn theo domain đang mở:</span>
                                    <code class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded select-all">{{ $currentEndpoint }}</code>
                                </div>
                                <button 
                                    type="button" 
                                    @click="document.getElementById('sepayWebhookUrlInput').value = '{{ $currentEndpoint }}'"
                                    class="text-indigo-600 hover:text-indigo-800 font-semibold underline flex items-center gap-1 transition"
                                >
                                    <span class="material-symbols-outlined text-xs">sync</span>
                                    <span>Điền nhanh URL theo domain hiện tại</span>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">
                                    Định dạng dữ liệu <span class="text-rose-500">*</span>
                                </label>
                                <select name="data_format" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold text-gray-800 focus:border-primary-container focus:ring-primary-container">
                                    <option value="json" selected>JSON (khuyến nghị) — application/json</option>
                                    <option value="form">Form (hỗ trợ tệp đính kèm) — multipart/form-data</option>
                                    <option value="urlencoded">Form (URL-encoded) — application/x-www-form-urlencoded</option>
                                </select>
                            </div>

                            <div class="flex items-center gap-2 pt-6">
                                <label class="relative flex items-center gap-2 cursor-pointer text-xs font-medium text-gray-700">
                                    <input type="checkbox" name="auto_retry" value="1" {{ $sepayConfig->auto_retry ? 'checked' : '' }} class="rounded text-primary-container focus:ring-primary-container" />
                                    <span>Tự động gửi lại khi server trả lỗi (tối đa 7 lần)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Bảo mật & Xác thực HMAC-SHA256 -->
                    <div class="space-y-4 pt-4 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider text-primary flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-sm">security</span>
                                <span>Bảo mật &amp; Xác thực Chống Giả Mạo</span>
                            </h3>
                            <span class="text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                Khuyến nghị: HMAC-SHA256
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Phương thức xác thực <span class="text-rose-500">*</span></label>
                                <select name="auth_method" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold text-gray-800 focus:border-primary-container focus:ring-primary-container">
                                    <option value="hmac_sha256" {{ $sepayConfig->auth_method === 'hmac_sha256' ? 'selected' : '' }}>HMAC-SHA256 (Khuyến nghị)</option>
                                    <option value="api_key" {{ $sepayConfig->auth_method === 'api_key' ? 'selected' : '' }}>API Key</option>
                                    <option value="none" {{ $sepayConfig->auth_method === 'none' ? 'selected' : '' }}>Không xác thực</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1 flex items-center justify-between">
                                    <span>Secret Key HMAC-SHA256 <span class="text-rose-500">*</span></span>
                                    <button type="button" @click="showSecret = !showSecret" class="text-[11px] text-gray-400 hover:text-gray-700 font-normal">
                                        <span x-text="showSecret ? 'Ẩn' : 'Hiện'"></span>
                                    </button>
                                </label>
                                <div class="flex items-center gap-2">
                                    <input 
                                        :type="showSecret ? 'text' : 'password'" 
                                        id="sepaySecretKeyInput" 
                                        name="secret_key" 
                                        value="{{ old('secret_key', $sepayConfig->secret_key) }}" 
                                        required 
                                        class="flex-1 text-xs font-mono font-bold text-gray-900 rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container bg-white" 
                                        placeholder="whsec_..."
                                    />
                                    <button 
                                        type="button" 
                                        @click="copyVal(document.getElementById('sepaySecretKeyInput').value, 'secret_key')" 
                                        class="p-2.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold transition flex items-center gap-1 border border-gray-200"
                                        title="Sao chép Secret Key"
                                    >
                                        <span class="material-symbols-outlined text-sm" x-text="copiedTag === 'secret_key' ? 'check' : 'content_copy'"></span>
                                    </button>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1">SePay ký dữ liệu bằng HMAC-SHA256 qua header <code class="font-mono text-gray-600">X-SePay-Signature</code>.</p>
                            </div>
                        </div>

                        <div class="pt-2">
                            <label class="relative flex items-center gap-2 cursor-pointer text-xs font-semibold text-gray-800">
                                <input type="checkbox" name="is_active" value="1" {{ $sepayConfig->is_active ? 'checked' : '' }} class="rounded text-primary-container focus:ring-primary-container" />
                                <span>Kích hoạt Webhook (Bật tính năng tự động gạch nợ khi có thông báo tiền về)</span>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <span class="text-[11px] text-gray-400">Sau khi lưu, vui lòng đối soát URL và Secret Key khớp với trang SePay.vn</span>
                        <button type="submit" class="px-6 py-2.5 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">save</span>
                            <span>Lưu Cấu Hình SePay Webhook</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═════════════════════════════════════════════════════════════════
             TAB 3: NHẬT KÝ GIAO DỊCH SEPAY
             ═════════════════════════════════════════════════════════════════ -->
        <div x-show="activeTab === 'logs'" class="space-y-4">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Giao dịch SePay Webhook gần nhất</h3>
                        <p class="text-[11px] text-gray-400">Tự động đối soát nội dung chuyển khoản và gạch nợ học phí</p>
                    </div>
                    <span class="text-xs font-bold text-gray-500">Tổng cộng: {{ count($recentTransactions) }} giao dịch</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50/80 text-gray-500 uppercase text-[10px] font-bold border-b border-gray-100">
                            <tr>
                                <th class="p-3">Thời gian</th>
                                <th class="p-3">ID SePay</th>
                                <th class="p-3">STK Nhận</th>
                                <th class="p-3 text-right">Số tiền</th>
                                <th class="p-3">Nội dung chuyển khoản</th>
                                <th class="p-3">Trạng thái</th>
                                <th class="p-3">Kết quả đối soát</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recentTransactions as $tx)
                                <tr class="hover:bg-gray-50/60 transition">
                                    <td class="p-3 text-gray-500 font-mono text-[11px]">
                                        {{ $tx->transaction_date ? $tx->transaction_date->format('d/m/Y H:i') : $tx->created_at->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="p-3 font-mono font-semibold text-gray-800">
                                        #{{ $tx->sepay_id ?? $tx->id }}
                                    </td>
                                    <td class="p-3 font-mono">
                                        {{ $tx->account_number }}
                                        <div class="text-[10px] text-gray-400">{{ $tx->gateway }}</div>
                                    </td>
                                    <td class="p-3 text-right font-mono font-bold text-emerald-600 text-sm">
                                        +{{ number_format($tx->transfer_amount) }}đ
                                    </td>
                                    <td class="p-3 max-w-xs truncate" title="{{ $tx->content }}">
                                        <span class="font-mono font-semibold text-gray-800">{{ $tx->content }}</span>
                                    </td>
                                    <td class="p-3">
                                        @if($tx->status === 'matched')
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                ✓ Đã khớp học viên
                                            </span>
                                        @elseif($tx->status === 'unmatched')
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                ? Chưa khớp mã HS
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-600">
                                                {{ $tx->status }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-[11px] text-gray-600 max-w-sm">
                                        {{ $tx->response_message ?? 'Đang chờ xử lý' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-gray-400 text-xs">
                                        Chưa có giao dịch webhook nào từ SePay. Khi phụ huynh chuyển khoản quét mã VietQR, giao dịch sẽ tự động xuất hiện tại đây.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        <!-- ═════════════════════════════════════════════════════════════════
             MODAL 1: THÊM TÀI KHOẢN NGÂN HÀNG MỚI
             ═════════════════════════════════════════════════════════════════ -->
        <div id="newBankModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-xs">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <h3 class="font-bold text-sm text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary-container text-lg">account_balance</span>
                        <span>Thêm Tài Khoản Ngân Hàng Mới</span>
                    </h3>
                    <button type="button" onclick="document.getElementById('newBankModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form action="{{ route('system-config.bank-accounts.store') }}" method="POST" class="space-y-3.5">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Mã ngân hàng (NAPAS)</label>
                            <input type="text" name="bank_code" placeholder="HDB / VCB / TCB" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono font-bold uppercase focus:border-primary-container focus:ring-primary-container" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Tên ngân hàng</label>
                            <input type="text" name="bank_name" placeholder="HDBank / Vietcombank" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số tài khoản (STK)</label>
                        <input type="text" name="account_number" placeholder="108704070014516" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono font-bold text-primary-container focus:border-primary-container focus:ring-primary-container" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tên chủ tài khoản (In hoa không dấu)</label>
                        <input type="text" name="account_holder" placeholder="CTCP PTGD MS KATY&DTXD ML" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold uppercase focus:border-primary-container focus:ring-primary-container" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chi nhánh / Cơ sở áp dụng</label>
                        <select name="branch_id" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container">
                            <option value="">Toàn hệ thống (Mọi chi nhánh)</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pt-1">
                        <label class="relative flex items-center gap-2 cursor-pointer text-xs font-semibold text-gray-800">
                            <input type="checkbox" name="is_default_vietqr" value="1" class="rounded text-primary-container focus:ring-primary-container" />
                            <span>Đặt làm tài khoản mặc định sinh mã VietQR</span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                        <button type="button" onclick="document.getElementById('newBankModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border text-xs font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                        <button type="submit" class="px-5 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition">Lưu Tài Khoản</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═════════════════════════════════════════════════════════════════
             MODAL 2: SỬA TÀI KHOẢN NGÂN HÀNG
             ═════════════════════════════════════════════════════════════════ -->
        <div x-show="showEditModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-xs">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <h3 class="font-bold text-sm text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-blue-600 text-lg">edit</span>
                        <span>Cập Nhật Tài Khoản Ngân Hàng</span>
                    </h3>
                    <button type="button" @click="showEditModal = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form :action="'/system-config/bank-accounts/' + editForm.id" method="POST" class="space-y-3.5">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Mã ngân hàng (NAPAS)</label>
                            <input type="text" name="bank_code" x-model="editForm.bank_code" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono font-bold uppercase focus:border-primary-container focus:ring-primary-container" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Tên ngân hàng</label>
                            <input type="text" name="bank_name" x-model="editForm.bank_name" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số tài khoản (STK)</label>
                        <input type="text" name="account_number" x-model="editForm.account_number" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono font-bold text-primary-container focus:border-primary-container focus:ring-primary-container" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tên chủ tài khoản (In hoa không dấu)</label>
                        <input type="text" name="account_holder" x-model="editForm.account_holder" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold uppercase focus:border-primary-container focus:ring-primary-container" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chi nhánh / Cơ sở áp dụng</label>
                        <select name="branch_id" x-model="editForm.branch_id" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container">
                            <option value="">Toàn hệ thống (Mọi chi nhánh)</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pt-1 space-y-2">
                        <label class="relative flex items-center gap-2 cursor-pointer text-xs font-semibold text-gray-800">
                            <input type="checkbox" name="is_default_vietqr" value="1" x-model="editForm.is_default_vietqr" class="rounded text-primary-container focus:ring-primary-container" />
                            <span>Đặt làm tài khoản mặc định sinh mã VietQR</span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                        <button type="button" @click="showEditModal = false" class="px-4 py-2 rounded-xl border text-xs font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition">Lưu Thay Đổi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function systemBankSepayManager() {
            return {
                activeTab: 'banks',
                showSecret: false,
                copiedTag: '',
                showEditModal: false,
                editForm: {
                    id: '',
                    bank_code: '',
                    bank_name: '',
                    account_number: '',
                    account_holder: '',
                    branch_id: '',
                    is_default_vietqr: false,
                },

                copyVal(text, tag) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copiedTag = tag;
                        setTimeout(() => {
                            if (this.copiedTag === tag) this.copiedTag = '';
                        }, 2000);
                    });
                },

                openEditModal(acc) {
                    this.editForm = {
                        id: acc.id,
                        bank_code: acc.bank_code,
                        bank_name: acc.bank_name,
                        account_number: acc.account_number,
                        account_holder: acc.account_holder,
                        branch_id: acc.branch_id || '',
                        is_default_vietqr: Boolean(acc.is_default_vietqr),
                    };
                    this.showEditModal = true;
                }
            };
        }
    </script>
</x-app-layout>
