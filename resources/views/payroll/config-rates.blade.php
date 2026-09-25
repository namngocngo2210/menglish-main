<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.index') }}" class="w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600">price_change</span>
                        <span>Cấu Hình Đơn Giá Giáo Viên</span>
                    </h1>
                    <p class="text-xs text-gray-500">Quản lý và cập nhật định mức thù lao giảng dạy theo giờ/buổi cho từng cấp bậc và giáo viên</p>
                </div>
            </div>
            <a href="{{ route('payroll.config.commission-tiers') }}" class="px-3.5 py-2 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">percent</span>
                <span>Cấu hình Hoa hồng</span>
            </a>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{
        selectedTeacherName: 'Nguyễn Văn A',
        selectedTeacherCode: 'GV-2026-045',
        currentRate: '350.000',
        teacherType: 'gv_vietnam'
    }">
        
        <!-- Bento Grid Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- Left Column: Search & Current Rate Status (Col 4) -->
            <div class="lg:col-span-4 space-y-6">
                <!-- 1. Chọn giáo viên -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm space-y-4">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5 pb-2 border-b border-gray-100">
                        <span class="material-symbols-outlined text-orange-600 text-base">person_search</span>
                        <span>1. Chọn giáo viên</span>
                    </h3>

                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                        <input type="text" placeholder="Tìm tên hoặc mã giáo viên..." class="w-full text-xs rounded-xl border border-gray-300 pl-9 pr-3 py-2 bg-slate-50 focus:bg-white focus:ring-primary-container focus:border-primary-container transition" />
                    </div>

                    <!-- Selected Teacher Profile Box -->
                    <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-orange-600 text-white flex items-center justify-center font-black text-sm shrink-0 shadow-sm">
                            NA
                        </div>
                        <div class="space-y-0.5">
                            <p class="font-bold text-gray-900 text-xs" x-text="selectedTeacherName">Nguyễn Văn A</p>
                            <p class="text-[10px] text-gray-400 font-mono" x-text="'Mã NV: ' + selectedTeacherCode">Mã NV: GV-2026-045</p>
                            <div class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[9px] font-extrabold uppercase">
                                Đang giảng dạy
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Đơn giá hiện hành -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm space-y-3 relative overflow-hidden">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-orange-100/60 rounded-full blur-xl pointer-events-none"></div>
                    
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5 pb-2 border-b border-gray-100">
                        <span class="material-symbols-outlined text-orange-600 text-base">receipt</span>
                        <span>Đơn giá hiện hành</span>
                    </h3>

                    <div class="space-y-3 text-xs">
                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block">Phân loại giảng viên</span>
                            <p class="font-bold text-gray-900 flex items-center gap-1 mt-0.5">
                                <span class="material-symbols-outlined text-orange-600 text-[16px]">school</span>
                                <span>Giáo viên Tiêu chuẩn (Senior Trainer)</span>
                            </p>
                        </div>

                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block">Mức thù lao đang áp dụng</span>
                            <div class="flex items-baseline gap-1 mt-0.5">
                                <span class="text-2xl font-black text-orange-600 font-mono" x-text="currentRate">350.000</span>
                                <span class="text-xs font-semibold text-gray-500">VNĐ / giờ</span>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-100 text-[11px] text-gray-500 font-mono">
                            Hiệu lực từ: 01/01/2026
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Update Rate Form & Global Matrix (Col 8) -->
            <div class="lg:col-span-8 space-y-6">
                <!-- 2. Cập nhật đơn giá mới Form -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-4">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5 pb-2 border-b border-gray-100">
                        <span class="material-symbols-outlined text-orange-600 text-base">edit_calendar</span>
                        <span>2. Thiết lập &amp; Cập nhật đơn giá mới</span>
                    </h3>

                    <form action="{{ route('payroll.config.teacher-rates.store') }}" method="POST" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Cấp bậc / Bậc xếp hạng <span class="text-rose-500">*</span></label>
                                <input type="text" name="rank_title" value="Senior IELTS Trainer" required placeholder="VD: Lead Trainer, Master Trainer" class="w-full text-xs font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50 focus:bg-white focus:ring-primary-container focus:border-primary-container" />
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Yêu cầu chứng chỉ &amp; Kinh nghiệm</label>
                                <input type="text" name="criteria" value="IELTS 8.0+, TESOL, 3 năm KN" placeholder="IELTS 8.0+, 3 năm KN" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50 focus:bg-white focus:ring-primary-container focus:border-primary-container" />
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Đơn giá lớp Giao tiếp (VNĐ/giờ) <span class="text-rose-500">*</span></label>
                                <input type="number" name="communication_rate" value="300000" step="10000" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50 focus:bg-white focus:ring-primary-container focus:border-primary-container" />
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Đơn giá lớp IELTS / Cambridge (VNĐ/giờ) <span class="text-rose-500">*</span></label>
                                <input type="number" name="ielts_rate" value="400000" step="10000" required class="w-full text-xs font-mono font-bold text-orange-600 rounded-xl border border-gray-300 p-2.5 bg-slate-50 focus:bg-white focus:ring-primary-container focus:border-primary-container" />
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-100 flex items-center justify-end gap-2">
                            <button type="submit" class="px-5 py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                                <span class="material-symbols-outlined text-[16px]">save</span>
                                <span>Lưu &amp; Áp Dụng Đơn Giá Mới</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- 3. Bảng Ma Trận Đơn Giá Toàn Hệ Thống -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-orange-600 text-base">table_chart</span>
                            <span>Bảng Ma Trận Cấp Bậc Đơn Giá Giảng Dạy MEnglish</span>
                        </h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                                    <th class="py-3 px-4">Cấp bậc Giáo viên</th>
                                    <th class="py-3 px-4">Yêu cầu chứng chỉ / Kinh nghiệm</th>
                                    <th class="py-3 px-4 text-right">Lớp Giao tiếp</th>
                                    <th class="py-3 px-4 text-right">Lớp IELTS / Cambridge</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                                @forelse ($rates as $r)
                                    <tr class="hover:bg-orange-50/20 transition">
                                        <td class="py-3.5 px-4 font-bold text-gray-900">{{ $r->rank_title }}</td>
                                        <td class="py-3.5 px-4 text-gray-600">{{ $r->criteria }}</td>
                                        <td class="py-3.5 px-4 text-right font-mono font-bold text-gray-800">{{ number_format($r->communication_rate) }}đ / h</td>
                                        <td class="py-3.5 px-4 text-right font-mono font-black text-orange-600">{{ number_format($r->ielts_rate) }}đ / h</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-6 text-gray-400 text-xs">Chưa có bảng đơn giá.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>

    </div>
</x-app-layout>
