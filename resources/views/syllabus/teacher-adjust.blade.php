<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.versions') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">speed</span>
                        Xin điều chỉnh tiến độ (Cổng Giáo viên — Bước #7)
                    </h1>
                    <p class="text-xs text-gray-500">Giáo viên gửi yêu cầu xin giãn tiến độ, tăng ca bổ trợ hoặc lùi lịch thi gửi lên Ban Đào tạo.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.adjustment-requests') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">rule</span>
                    <span>Admin duyệt tiến độ (Bước #8)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 7])

    <!-- 2-Column Bento Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Form Gửi Yêu Cầu (Left 4 cols) -->
        <div class="lg:col-span-5 flex flex-col gap-4 min-w-0">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">edit_calendar</span>
                    <h2 class="text-sm font-bold text-gray-900">Gửi yêu cầu điều chỉnh</h2>
                </div>

                <form action="{{ route('syllabus.adjustment-requests.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Lớp học / Chặng học <span class="text-rose-500">*</span></label>
                        <select name="class_id" required class="w-full rounded-xl border border-gray-200 bg-white text-xs p-2.5 font-bold text-primary focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none">
                            <option disabled selected value="">Chọn lớp học đang giảng dạy...</option>
                            @foreach ($classes as $cl)
                                <option value="{{ $cl->id }}">{{ $cl->name }} ({{ $cl->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Loại điều chỉnh <span class="text-rose-500">*</span></label>
                        <select name="request_type" required class="w-full rounded-xl border border-gray-200 bg-white text-xs p-2.5 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none">
                            <option value="Xin thêm 02 buổi phụ đạo Speaking">Xin thêm 02 buổi phụ đạo Speaking</option>
                            <option value="Xin thêm 01 buổi ôn tập ngữ pháp">Xin thêm 01 buổi ôn tập ngữ pháp</option>
                            <option value="Lùi lịch thi Big Test 1 tuần">Lùi lịch thi Big Test 1 tuần</option>
                            <option value="Dạy bù ca nghỉ lễ trung tâm">Dạy bù ca nghỉ lễ trung tâm</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số buổi cần thêm (giãn tiến độ)</label>
                        <input type="number" name="extra_sessions" min="0" max="{{ \App\Models\SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS }}" value="{{ old('extra_sessions', 0) }}" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs" />
                        <p class="text-[11px] text-gray-500 mt-1">Khi được duyệt, hệ thống thêm đúng số buổi này vào cuối lịch học của lớp (theo TKB, bỏ qua ngày nghỉ) và lùi ngày kết thúc lớp.</p>
                        @error('extra_sessions') <p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Lý do điều chỉnh chi tiết <span class="text-rose-500">*</span></label>
                        <textarea name="reason" required rows="4" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none resize-none" placeholder="Học viên phản hồi phần Speaking còn yếu, cần thêm thời gian luyện phản xạ trước bài thi..."></textarea>
                    </div>

                    <!-- SLA Notice -->
                    <div class="bg-amber-50 p-3 rounded-xl border border-amber-200/70 text-xs text-amber-900 flex items-start gap-2">
                        <span class="material-symbols-outlined text-amber-600 shrink-0 text-[18px]">timer</span>
                        <p class="leading-relaxed"><strong>Quy định SLA:</strong> Yêu cầu của bạn sẽ được Admin / Ban Đào tạo xem xét và phản hồi phê duyệt trong vòng 24h làm việc tại Bước #8.</p>
                    </div>

                    <button type="submit" class="w-full bg-primary-container hover:bg-primary-hover text-white py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        <span>Gửi đơn xin điều chỉnh tiến độ</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Danh sách các yêu cầu đã gửi (Right 7 cols) -->
        <div class="lg:col-span-7 flex flex-col gap-4 min-w-0">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col h-full">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">fact_check</span>
                        <h2 class="text-sm font-bold text-gray-900">Trạng thái các yêu cầu đã gửi</h2>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-gray-200 text-gray-700">{{ $requests->total() }} đơn</span>
                </div>

                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="py-3 px-4">Lớp học</th>
                                <th class="py-3 px-4">Nội dung xin điều chỉnh</th>
                                <th class="py-3 px-4 whitespace-nowrap">Ngày gửi</th>
                                <th class="py-3 px-4 text-right">Trạng thái duyệt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                            @forelse ($requests as $req)
                                <tr class="hover:bg-gray-50/80 transition-colors">
                                    <td class="py-3.5 px-4">
                                        <div class="font-bold text-gray-900">{{ $req->classModel?->name ?? 'Lớp học' }}</div>
                                        <div class="text-[10px] text-gray-400 font-mono">{{ $req->classModel?->code ?? '' }}</div>
                                    </td>
                                    <td class="py-3.5 px-4 max-w-xs">
                                        <div class="font-semibold text-primary mb-0.5">{{ $req->request_type }}{{ $req->extra_sessions ? ' · +'.$req->extra_sessions.' buổi' : '' }}</div>
                                        <p class="text-gray-600 line-clamp-1 text-[11px]">{{ $req->reason }}</p>
                                        @if ($req->status === 'rejected' && $req->rejection_reason)
                                            <p class="text-rose-600 text-[11px] mt-0.5">Lý do từ chối: {{ $req->rejection_reason }}</p>
                                        @elseif ($req->status === 'approved' && $req->applied_note)
                                            <p class="text-emerald-700 text-[11px] mt-0.5">{{ $req->applied_note }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 font-mono text-gray-500 whitespace-nowrap">
                                        {{ $req->created_at->format('d/m/Y') }}
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        @if ($req->status === 'approved')
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Đã phê duyệt
                                            </span>
                                        @elseif ($req->status === 'rejected')
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                Đã từ chối
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                Chờ Học thuật duyệt
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-12 text-center text-gray-400">Bạn chưa gửi yêu cầu xin điều chỉnh tiến độ nào.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-100"><x-ui.pagination :paginator="$requests" unit="đơn" /></div>
            </div>
        </div>
    </div>
</x-app-layout>
