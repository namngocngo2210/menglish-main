{{-- Mockup: ui-full-tinh-nang-menglish/epic-5/cau-hinh-tai-khoan-ngan-hang --}}
<x-app-layout title="Tài khoản ngân hàng thu tiền">
    <x-ui.page-header title="Cấu hình Tài khoản ngân hàng thu tiền"
                      description="Quản lý các tài khoản nhận thanh toán học phí trên toàn hệ thống MENGLISH, mã VietQR và kết nối SePay tự động gạch nợ.">
        <x-slot:actions>
            <x-ui.button icon="add_circle" onclick="window.dispatchEvent(new CustomEvent('bank-form-new'))">Thêm tài khoản mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

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

        <!-- TAB 1: TÀI KHOẢN NGÂN HÀNG (mockup: bảng + form Thêm / Sửa bên phải) -->
        <div x-show="activeTab === 'banks'" class="space-y-4">
            <x-ui.alert type="info">
                <strong>Lưu ý:</strong> Mọi tài khoản ngân hàng được cấu hình tại đây đều tự động tích hợp mã QR gắn mã học sinh.
                Chỉ có <strong>01 tài khoản "Mặc định"</strong> hiển thị tự động trên màn hình Lập phiếu thu khi hợp đồng / chi nhánh của học viên chưa có tài khoản riêng.
            </x-ui.alert>

            @if ($errors->has('is_active'))
                <x-ui.alert type="error">{{ $errors->first('is_active') }}</x-ui.alert>
            @endif

            <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
                <div class="lg:col-span-8">
                    <x-ui.data-table min-width="720px">
                        <x-slot:header>
                            <h3 class="font-h3 text-h3 text-on-surface">Danh sách tài khoản</h3>
                            <form method="GET" action="{{ route('system-config.bank-accounts') }}" class="relative">
                                <input type="search" name="q" value="{{ $search }}" placeholder="Tìm kiếm tài khoản..." aria-label="Tìm kiếm tài khoản"
                                       class="w-64 rounded-lg border-outline-variant py-xs pl-sm pr-10 font-body-small text-body-small" />
                                <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant" aria-label="Tìm"><span class="material-symbols-outlined text-[20px]">search</span></button>
                            </form>
                        </x-slot:header>
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
                                @forelse ($accounts as $acc)
                                    <tr @class(['opacity-60' => ! $acc->is_active])>
                                        <td><x-ui.badge :color="$acc->account_type === 'other' ? 'neutral' : 'primary'" :dot="false">{{ mb_strtoupper($acc->type_label) }}</x-ui.badge></td>
                                        <td>
                                            <div class="font-body-medium text-body-medium">{{ $acc->bank_name }} <span class="font-code text-caption text-on-surface-variant">({{ $acc->bank_code }})</span></div>
                                            <div class="flex items-center gap-xs font-code text-code text-primary">
                                                {{ $acc->account_number }}
                                                <button type="button" @click="copyVal(@js(preg_replace('/\s+/', '', $acc->account_number)), 'stk_{{ $acc->id }}')" class="text-on-surface-variant hover:text-on-surface" title="Sao chép STK" aria-label="Sao chép STK">
                                                    <span class="material-symbols-outlined text-[16px]" x-text="copiedTag === 'stk_{{ $acc->id }}' ? 'check' : 'content_copy'"></span>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="uppercase">{{ $acc->account_holder }}</td>
                                        <td>{{ $acc->branch?->name ?? $acc->branch_location ?? 'Toàn hệ thống' }}</td>
                                        <td class="whitespace-nowrap">
                                            @if ($acc->is_default_vietqr)
                                                <span class="inline-flex items-center gap-xs font-body-medium text-primary"><span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1" aria-hidden="true">star</span>Mặc định</span>
                                            @elseif (! $acc->is_active)
                                                <x-ui.badge color="neutral">Ngừng dùng</x-ui.badge>
                                            @else
                                                <form action="{{ route('system-config.bank-accounts.default', $acc->id) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="font-body-small text-body-small text-on-surface-variant underline hover:text-primary">Đặt làm mặc định</button>
                                                </form>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <x-ui.button size="sm" variant="ghost" icon="edit" @click="openEdit({{ Js::from($acc->only(['id', 'account_type', 'bank_code', 'bank_name', 'account_number', 'account_holder', 'branch_id', 'is_default_vietqr', 'is_active'])) }})" title="Sửa tài khoản" aria-label="Sửa tài khoản" />
                                            <form action="{{ route('system-config.bank-accounts.destroy', $acc->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa tài khoản ngân hàng này?')" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" size="sm" variant="danger-text" icon="delete" title="Xóa" aria-label="Xóa tài khoản" />
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6"><x-ui.empty-state icon="account_balance" :title="$search !== '' ? 'Không tìm thấy tài khoản phù hợp' : 'Chưa có tài khoản ngân hàng nào'" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <x-slot:footer>
                            <p class="px-md py-sm font-body-small text-body-small text-on-surface-variant">Hiển thị {{ $accounts->count() }} trên {{ $totalAccounts }} tài khoản</p>
                        </x-slot:footer>
                    </x-ui.data-table>
                </div>

                {{-- Form Thêm / Sửa --}}
                <div class="space-y-md lg:col-span-4">
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
                        <h3 class="mb-md flex items-center gap-sm font-h3 text-h3 text-on-surface">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">account_balance</span>
                            <span x-text="form.id ? 'Sửa tài khoản' : 'Thêm / Sửa tài khoản'"></span>
                        </h3>
                        <form method="POST" :action="form.id ? @js(url('/system-config/bank-accounts')) + '/' + form.id : @js(route('system-config.bank-accounts.store'))" class="space-y-sm">
                            @csrf
                            <template x-if="form.id"><input type="hidden" name="_method" value="PUT"></template>
                            <label class="block">
                                <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Loại tài khoản</span>
                                <select name="account_type" x-model="form.account_type" class="w-full rounded-lg border-outline-variant font-body-base text-body-base">
                                    <option value="company">Công ty (Chủ sở hữu chính)</option>
                                    <option value="other">Khác (Cá nhân/Đại diện)</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Số tài khoản <span class="text-error">*</span></span>
                                <input type="text" name="account_number" x-model="form.account_number" required placeholder="Nhập số tài khoản ngân hàng" class="w-full rounded-lg border-outline-variant font-code text-code" />
                            </label>
                            <div class="grid grid-cols-3 gap-sm">
                                <label class="block">
                                    <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Mã NH <span class="text-error">*</span></span>
                                    <input type="text" name="bank_code" x-model="form.bank_code" required placeholder="VCB" title="Mã ngân hàng NAPAS dùng tạo VietQR" class="w-full rounded-lg border-outline-variant font-code text-code uppercase" />
                                </label>
                                <label class="col-span-2 block">
                                    <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Tên ngân hàng <span class="text-error">*</span></span>
                                    <input type="text" name="bank_name" x-model="form.bank_name" required placeholder="VD: Vietcombank, Techcombank..." class="w-full rounded-lg border-outline-variant font-body-base text-body-base" />
                                </label>
                            </div>
                            <label class="block">
                                <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Chủ tài khoản <span class="text-error">*</span></span>
                                <input type="text" name="account_holder" x-model="form.account_holder" required placeholder="Nhập tên đầy đủ chủ tài khoản" class="w-full rounded-lg border-outline-variant font-body-base text-body-base uppercase" />
                            </label>
                            <label class="block">
                                <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Cơ sở áp dụng</span>
                                <select name="branch_id" x-model="form.branch_id" class="w-full rounded-lg border-outline-variant font-body-base text-body-base">
                                    <option value="">Toàn hệ thống</option>
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="flex items-center justify-between gap-sm rounded-lg border border-outline-variant p-sm">
                                <span>
                                    <span class="block font-body-medium text-body-medium">Đặt làm tài khoản mặc định</span>
                                    <span class="block font-caption text-caption text-on-surface-variant">Sử dụng cho toàn bộ phiếu thu tự động</span>
                                </span>
                                <input type="checkbox" name="is_default_vietqr" value="1" x-model="form.is_default_vietqr" class="rounded text-primary-container focus:ring-primary-container" />
                            </label>
                            <template x-if="form.id">
                                <label class="flex items-center justify-between gap-sm rounded-lg border border-outline-variant p-sm">
                                    <span class="font-body-medium text-body-medium">Đang sử dụng</span>
                                    <span>
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="rounded text-primary-container focus:ring-primary-container" />
                                    </span>
                                </label>
                            </template>
                            <div class="flex gap-sm pt-sm">
                                <x-ui.button type="submit" icon="save" class="flex-1">Lưu cấu hình</x-ui.button>
                                <x-ui.button variant="secondary" @click="resetForm()">Hủy</x-ui.button>
                            </div>
                        </form>
                    </section>

                    <section class="flex items-start gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                        <span class="material-symbols-outlined text-[32px] text-primary" aria-hidden="true">qr_code_2</span>
                        <div class="min-w-0 flex-1">
                            <h4 class="font-body-medium text-body-medium text-on-surface">Tích hợp VietQR</h4>
                            <p class="font-body-small text-body-small text-on-surface-variant">Mã QR được tự động tạo dựa trên số tài khoản và nội dung nộp học phí.</p>
                            @if ($defaultAccount?->vietqr_preview_url)
                                <img src="{{ $defaultAccount->vietqr_preview_url }}" alt="VietQR tài khoản mặc định" class="mt-sm h-32 w-32 rounded-lg border border-outline-variant object-contain" loading="lazy" />
                                <p class="font-caption text-caption text-on-surface-variant">Tài khoản mặc định: {{ $defaultAccount->bank_name }} · {{ $defaultAccount->account_number }}</p>
                            @else
                                <p class="mt-sm font-caption text-caption text-error">Chưa có tài khoản đang hoạt động để tạo mã QR.</p>
                            @endif
                        </div>
                    </section>
                </div>
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
                                        +{{ number_format((float) $tx->transfer_amount, 0, ',', '.') }}đ
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
                                                {{ \App\Support\StatusLabel::for($tx->status) }}
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

    </div>

    <script>
        function systemBankSepayManager() {
            const blank = { id: '', account_type: 'company', bank_code: '', bank_name: '', account_number: '', account_holder: '', branch_id: '', is_default_vietqr: false, is_active: true };
            return {
                activeTab: 'banks',
                showSecret: false,
                copiedTag: '',
                form: { ...blank },

                init() {
                    window.addEventListener('bank-form-new', () => { this.activeTab = 'banks'; this.resetForm(); });
                },

                copyVal(text, tag) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copiedTag = tag;
                        setTimeout(() => {
                            if (this.copiedTag === tag) this.copiedTag = '';
                        }, 2000);
                    });
                },

                resetForm() {
                    this.form = { ...blank };
                },

                openEdit(acc) {
                    this.form = {
                        id: acc.id,
                        account_type: acc.account_type || 'company',
                        bank_code: acc.bank_code,
                        bank_name: acc.bank_name,
                        account_number: acc.account_number,
                        account_holder: acc.account_holder,
                        branch_id: acc.branch_id ? String(acc.branch_id) : '',
                        is_default_vietqr: Boolean(acc.is_default_vietqr),
                        is_active: acc.is_active === undefined ? true : Boolean(acc.is_active),
                    };
                },
            };
        }
    </script>
</x-app-layout>
