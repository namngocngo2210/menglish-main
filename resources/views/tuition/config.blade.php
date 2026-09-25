<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">tune</span>
                    Cấu Hình Dải Số Hóa Đơn &amp; Mẫu Hóa Đơn Điện Tử (Database)
                </h1>
                <p class="text-xs text-gray-500">Thiết lập ký hiệu mẫu số, ký hiệu hóa đơn, số hiện tại và nhà cung cấp HĐĐT</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6">
        <form action="{{ route('tuition.config.update') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf
            <div>
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">receipt</span>
                    Thông tin dải số hóa đơn
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Mẫu số hóa đơn <span class="text-rose-500">*</span></label>
                        <input type="text" name="template_code" value="{{ $invoiceConfig->template_code ?? '1/001' }}" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Ký hiệu hóa đơn (Series) <span class="text-rose-500">*</span></label>
                        <input type="text" name="series_code" value="{{ $invoiceConfig->series_code ?? 'C26MEN' }}" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Số bắt đầu</label>
                        <input type="number" name="start_number" value="{{ $invoiceConfig->start_number ?? 1 }}" class="w-full text-xs font-mono rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Số hiện tại (Next Number) <span class="text-rose-500">*</span></label>
                        <input type="number" name="current_number" value="{{ $invoiceConfig->current_number ?? 1001 }}" required class="w-full text-xs font-mono font-bold text-primary rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Nhà cung cấp HĐĐT</label>
                        <select name="provider" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold">
                            <option value="vnpt" {{ ($invoiceConfig->provider ?? '') === 'vnpt' ? 'selected' : '' }}>VNPT Invoice</option>
                            <option value="viettel" {{ ($invoiceConfig->provider ?? '') === 'viettel' ? 'selected' : '' }}>Viettel S-Invoice</option>
                            <option value="misa" {{ ($invoiceConfig->provider ?? '') === 'misa' ? 'selected' : '' }}>MISA meInvoice</option>
                            <option value="easyinvoice" {{ ($invoiceConfig->provider ?? '') === 'easyinvoice' ? 'selected' : '' }}>EasyInvoice</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Tự động phát hành HĐĐT khi duyệt thu</label>
                        <div class="mt-2 flex items-center gap-2">
                            <input type="checkbox" name="auto_issue" value="1" {{ ($invoiceConfig->auto_issue ?? true) ? 'checked' : '' }} id="autoIssue" class="rounded text-primary focus:ring-primary" />
                            <label for="autoIssue" class="text-xs text-gray-700 font-medium">Bật tự động xuất HĐĐT</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="px-5 py-2.5 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition">
                    Lưu cấu hình hóa đơn
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
