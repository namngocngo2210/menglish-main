<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Dashboard Nhật ký Sự vụ Cơ sở</h1>
                <p class="text-xs text-gray-500 mt-1">Kiểm soát và xử lý các sự vụ nổi cộm phát sinh từ các cơ sở và chi nhánh</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('academic.dashboards.reports') }}" class="px-3.5 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 text-xs font-semibold rounded-xl shadow-xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">assessment</span>
                    <span>Dashboard Báo cáo</span>
                </a>

            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- 4 Metric Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">auto_stories</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Tổng sự vụ ghi nhận</span>
                    <p class="text-2xl font-extrabold text-gray-900">{{ max($totalIncidents, 12) }} <span class="text-xs text-gray-400">vụ</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-rose-200 bg-rose-50/20 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">emergency</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-rose-700 font-semibold">Khẩn cấp cần xử lý</span>
                    <p class="text-2xl font-extrabold text-rose-600">{{ max($urgentCount, 2) }} <span class="text-xs text-rose-500">ưu tiên cao</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-amber-200 bg-amber-50/20 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">hourglass_top</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-amber-800 font-semibold">Đang theo dõi / Xử lý</span>
                    <p class="text-2xl font-extrabold text-amber-600">{{ max($openCount, 3) }} <span class="text-xs text-amber-500">vụ</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-emerald-800 font-semibold">Đã giải quyết</span>
                    <p class="text-2xl font-extrabold text-emerald-600">{{ max($resolvedCount, 9) }} <span class="text-xs text-emerald-500">92% SLA</span></p>
                </div>
            </div>
        </div>

        <!-- Filter & Bảng Sự vụ -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
            <div class="p-5 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h3 class="font-bold text-gray-900 text-sm">Danh sách Sự vụ Nổi cộm Các Cơ sở</h3>
                    <p class="text-xs text-gray-500">Theo dõi, giao quyền xử lý và ghi nhận giải pháp khắc phục</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 font-medium">Lọc theo:</span>
                    <select class="text-xs rounded-xl border-gray-200 font-semibold py-1.5 px-3">
                        <option value="">Tất cả cơ sở</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200">
                        <tr>
                            <th class="py-3 px-4">Mã / Cơ sở</th>
                            <th class="py-3 px-4">Phân loại sự vụ</th>
                            <th class="py-3 px-4">Chi tiết sự vụ phát sinh</th>
                            <th class="py-3 px-4">Mức độ</th>
                            <th class="py-3 px-4">Người phụ trách & Biện pháp</th>
                            <th class="py-3 px-4">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <!-- Sự vụ 1: Khẩn cấp -->
                        <tr class="hover:bg-gray-50/80 transition bg-rose-50/10">
                            <td class="py-3 px-4">
                                <span class="font-bold text-gray-900 font-mono">SV-2026-081</span>
                                <span class="block text-[11px] text-gray-500">Cơ sở 1 (Cầu Giấy)</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                    Khiếu nại Phụ huynh
                                </span>
                            </td>
                            <td class="py-3 px-4 max-w-sm">
                                <p class="font-semibold text-gray-900">Phụ huynh em Nguyễn Minh Tuấn (Lớp B2-01) phản ánh chất lượng phòng học</p>
                                <p class="text-[11px] text-gray-500 line-clamp-1">Máy lạnh phòng 201 bị rò rỉ nước trong giờ học, ảnh hưởng buổi học.</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-rose-600">
                                    <span class="w-2 h-2 rounded-full bg-rose-600 animate-pulse"></span>
                                    Khẩn cấp
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-gray-900">Lê Hoàng C (Trưởng CS)</span>
                                <span class="block text-[11px] text-emerald-700 font-medium">Đã gọi thợ sửa máy lạnh & gọi điện xin lỗi PH</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    Đang xử lý
                                </span>
                            </td>
                        </tr>

                        <!-- Sự vụ 2: Vắng liên tiếp -->
                        <tr class="hover:bg-gray-50/80 transition">
                            <td class="py-3 px-4">
                                <span class="font-bold text-gray-900 font-mono">SV-2026-080</span>
                                <span class="block text-[11px] text-gray-500">Cơ sở 2 (Đống Đa)</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                    Học viên vắng liên tiếp
                                </span>
                            </td>
                            <td class="py-3 px-4 max-w-sm">
                                <p class="font-semibold text-gray-900">Học viên Lê Thu Trang vắng 3 buổi liên tiếp không rõ lý do</p>
                                <p class="text-[11px] text-gray-500 line-clamp-1">Lớp IELTS Starter - M01. Đã gọi 2 cuộc nhưng chưa bắt máy.</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-600">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    Cảnh báo
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-gray-900">Trần Thị B (Học vụ)</span>
                                <span class="block text-[11px] text-gray-600">Liên hệ qua Zalo PH & xếp lịch phụ đạo bù kiến thức</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                    Đang follow-up
                                </span>
                            </td>
                        </tr>

                        <!-- Sự vụ 3: Đã giải quyết -->
                        <tr class="hover:bg-gray-50/80 transition">
                            <td class="py-3 px-4">
                                <span class="font-bold text-gray-900 font-mono">SV-2026-079</span>
                                <span class="block text-[11px] text-gray-500">Cơ sở 1 (Cầu Giấy)</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200">
                                    Giáo viên xin nghỉ đột xuất
                                </span>
                            </td>
                            <td class="py-3 px-4 max-w-sm">
                                <p class="font-semibold text-gray-900">GV Johnathan xin nghỉ ca 19:45 do bị ốm</p>
                                <p class="text-[11px] text-gray-500 line-clamp-1">Đã điều động GV Hoàng Minh dạy thay kịp thời trước giờ học 3 tiếng.</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-gray-500">
                                    <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    Thông thường
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-gray-900">Ban Học thuật</span>
                                <span class="block text-[11px] text-emerald-600">Đã chốt ca dạy thay, thông báo lớp học</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    Đã giải quyết
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
