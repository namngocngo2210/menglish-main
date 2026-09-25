<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-600">notifications_active</span>
                    Mẫu Tin Nhắn Nhắc Nợ Học Phí Tự Động
                </h1>
                <p class="text-xs text-gray-500">Cấu hình mẫu tin nhắn gửi qua Zalo ZNS / SMS Brandname theo các mốc T-3, T0, T+3</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Top Nav Tabs matching System Config -->
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
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-primary-container bg-primary-container text-white font-bold shadow-xs shrink-0"
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

        <div class="max-w-4xl mx-auto space-y-6">
        @foreach ($rules as $rule)
            <form action="{{ route('system-config.debt-reminders.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-4">
                @csrf
                <input type="hidden" name="milestone_key" value="{{ $rule->milestone_key }}" />
                <input type="hidden" name="title" value="{{ $rule->title }}" />

                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <div class="flex items-center gap-2.5">
                        <span class="px-2.5 py-1 rounded-lg bg-orange-100 text-primary font-mono font-bold text-xs">{{ $rule->milestone_key }}</span>
                        <h3 class="font-bold text-gray-900 text-sm">{{ $rule->title }}</h3>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Đang bật</span>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nội dung mẫu thông điệp (Message Template):</label>
                    <textarea name="template_content" rows="3" class="w-full text-xs rounded-xl border border-gray-200 p-3 leading-relaxed text-gray-800 font-mono">{{ $rule->template_content }}</textarea>
                    <p class="text-[11px] text-gray-400 mt-1">Các biến tự động thay thế: <code>{TEN_HOC_VIEN}</code>, <code>{TEN_LOP}</code>, <code>{HAN_NOP}</code>, <code>{SO_TIEN}</code></p>
                </div>

                <div class="flex items-center justify-end pt-2">
                    <button type="submit" class="px-5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition">
                        Lưu mẫu tin nhắn
                    </button>
                </div>
            </form>
        @endforeach
        </div>
    </div>
</x-app-layout>
