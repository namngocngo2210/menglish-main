<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">rule</span>
                        Duyệt yêu cầu xin điều chỉnh tiến độ
                    </h1>
                    <p class="text-xs text-gray-500">Quản lý các yêu cầu giãn tiến độ, tăng ca bổ trợ hoặc lùi lịch thi từ giáo viên.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('teacher-portal.shortcut', '14_xin_dieu_chinh_tien_do') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">speed</span>
                    <span>Cổng GV gửi đơn (Bước #7)</span>
                </a>
                <button type="button" onclick="document.getElementById('newAdjModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Tạo yêu cầu mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 8])

    <!-- Create Adjustment Request Modal -->
    <div id="newAdjModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-gray-100">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">speed</span>
                    <h3 class="font-bold text-sm text-gray-900">Gửi Đề Xuất Điều Chỉnh Tiến Độ</h3>
                </div>
                <button type="button" onclick="document.getElementById('newAdjModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('syllabus.adjustment-requests.store') }}" method="POST" class="space-y-3.5">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Lớp học cần điều chỉnh <span class="text-rose-500">*</span></label>
                    <select name="class_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold text-primary focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none bg-white">
                        @foreach ($classes as $cl)
                            <option value="{{ $cl->id }}">{{ $cl->name }} ({{ $cl->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Loại yêu cầu <span class="text-rose-500">*</span></label>
                    <select name="request_type" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none">
                        <option value="Xin thêm 02 buổi phụ đạo Speaking">Xin thêm 02 buổi phụ đạo Speaking</option>
                        <option value="Xin thêm 01 buổi ôn tập ngữ pháp">Xin thêm 01 buổi ôn tập ngữ pháp</option>
                        <option value="Lùi lịch thi Big Test 1 tuần">Lùi lịch thi Big Test 1 tuần</option>
                        <option value="Dạy bù ca nghỉ lễ trung tâm">Dạy bù ca nghỉ lễ trung tâm</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Lý do chi tiết <span class="text-rose-500">*</span></label>
                    <textarea name="reason" rows="3" required placeholder="Ghi rõ lý do và tình hình học tập của lớp..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('newAdjModal').classList.add('hidden')" class="px-3.5 py-2 rounded-xl border border-gray-200 text-xs text-gray-600 hover:bg-gray-50">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm">Gửi đề xuất</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Master-Detail Layout matching 04_duyet_yeu_cau_dieu_chinh_tien_do -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[600px]">
        <!-- Left Pane: List of Requests (Cards) -->
        <div class="lg:col-span-7 flex flex-col bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <!-- List Header -->
            <div class="px-5 py-3.5 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">pending_actions</span>
                    <span class="text-sm font-bold text-gray-900">Danh sách chờ duyệt</span>
                </div>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                    {{ $requests->count() }} Yêu cầu
                </span>
            </div>

            <!-- Scrollable List of Requests -->
            <div class="flex-1 overflow-y-auto p-4 space-y-3 custom-scrollbar" id="request-list">
                @forelse ($requests as $idx => $req)
                    @php
                        $isSelected = ($idx === 0);
                        $teacherName = $req->teacher?->name ?? 'Nguyễn Văn An';
                        $teacherInitial = substr($teacherName, 0, 1);
                        $isPending = ($req->status === 'pending');
                    @endphp
                    <div 
                        class="group request-card cursor-pointer border rounded-2xl p-4 transition-all relative overflow-hidden {{ $isSelected ? 'border-primary-container bg-orange-50/30 ring-1 ring-primary-container/20' : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-2xs' }}"
                        onclick="selectRequest({{ $req->id }}, this, '{{ addslashes($teacherName) }}', 'GV-{{ str_pad($req->user_id, 3, '0', STR_PAD_LEFT) }}', '{{ addslashes($req->classModel?->name ?? 'Lớp IELTS') }}', '{{ $req->created_at->format('d/m/Y') }}', '{{ addslashes($req->request_type) }}', '{{ addslashes($req->reason) }}', '{{ $req->status }}')"
                    >
                        <div class="absolute left-0 top-0 bottom-0 w-1 {{ $isSelected ? 'bg-primary-container' : 'bg-transparent group-hover:bg-gray-200' }}"></div>

                        <div class="flex justify-between items-start mb-2 pl-2">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-full bg-orange-100 text-primary flex items-center justify-center font-bold text-xs">
                                    {{ $teacherInitial }}
                                </div>
                                <div>
                                    <h3 class="text-xs font-bold text-gray-900">{{ $teacherName }}</h3>
                                    <p class="text-[10px] text-gray-400">Giáo viên phụ trách</p>
                                </div>
                            </div>

                            <!-- Status / SLA Badge -->
                            @if ($req->status === 'approved')
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Đã duyệt</span>
                            @elseif ($req->status === 'rejected')
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">Đã từ chối</span>
                            @else
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Còn hạn xử lý</span>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-2 mb-2.5 pl-2 text-[11px] text-gray-600">
                            <div class="flex items-center gap-1.5 truncate">
                                <span class="material-symbols-outlined text-[15px] text-gray-400">school</span>
                                <span class="truncate">{{ $req->classModel?->name ?? 'Lớp học' }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 font-mono text-gray-400 justify-end">
                                <span class="material-symbols-outlined text-[15px]">calendar_today</span>
                                <span>Gửi: {{ $req->created_at->format('d/m/Y') }}</span>
                            </div>
                        </div>

                        <div class="bg-gray-50/80 border border-gray-100 rounded-xl p-2.5 flex items-start gap-2 ml-2">
                            <span class="material-symbols-outlined text-[17px] text-primary mt-0.5">add_circle</span>
                            <div class="text-xs">
                                <p class="font-bold text-gray-800">{{ $req->request_type }}</p>
                                <p class="text-[11px] text-gray-500 line-clamp-1 mt-0.5">{{ $req->reason }}</p>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-12 text-center text-gray-400 text-xs">
                        Chưa có yêu cầu xin điều chỉnh tiến độ nào trong danh sách.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Right Pane: Detail & Processing Area -->
        <div class="lg:col-span-5 flex flex-col bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden h-full">
            <!-- Detail Header -->
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">assignment</span>
                    <span class="text-sm font-bold text-gray-900">Chi tiết yêu cầu &amp; Xử lý</span>
                </div>
            </div>

            <!-- Detail Content -->
            <div class="flex-1 overflow-y-auto p-5 space-y-5 custom-scrollbar">
                @php
                    $firstReq = $requests->first();
                @endphp

                <!-- Meta Info Block -->
                <div class="flex items-center gap-3">
                    <div id="detail-avatar" class="w-11 h-11 rounded-full bg-orange-100 text-primary flex items-center justify-center font-bold text-sm shadow-2xs">
                        {{ substr($firstReq?->teacher?->name ?? 'N', 0, 1) }}
                    </div>
                    <div>
                        <h2 id="detail-teacher" class="text-sm font-bold text-gray-900">{{ $firstReq?->teacher?->name ?? 'Nguyễn Văn An' }}</h2>
                        <p id="detail-code" class="text-[11px] text-gray-400 font-mono">GV-{{ str_pad($firstReq?->user_id ?? 1, 3, '0', STR_PAD_LEFT) }}</p>
                    </div>
                    <div class="ml-auto">
                        <span id="detail-badge" class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                            {{ ($firstReq?->status === 'approved') ? 'Đã duyệt' : (($firstReq?->status === 'rejected') ? 'Đã từ chối' : 'Chờ xử lý') }}
                        </span>
                    </div>
                </div>

                <!-- Details Grid -->
                <div class="bg-gray-50 p-3.5 rounded-xl border border-gray-100 space-y-2.5 text-xs">
                    <div>
                        <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block mb-0.5">Lớp / Chặng học</span>
                        <div class="flex items-center gap-1.5 font-bold text-gray-800">
                            <span class="material-symbols-outlined text-[16px] text-primary">school</span>
                            <span id="detail-class">{{ $firstReq?->classModel?->name ?? 'IELTS Intensive - Chặng 2' }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 border-t border-gray-200/60">
                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block mb-0.5">Ngày gửi yêu cầu</span>
                            <div class="flex items-center gap-1 font-mono text-gray-600">
                                <span class="material-symbols-outlined text-[15px]">calendar_today</span>
                                <span id="detail-date">{{ $firstReq?->created_at ? $firstReq->created_at->format('d/m/Y') : date('d/m/Y') }}</span>
                            </div>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block mb-0.5">Yêu cầu cụ thể</span>
                            <div class="flex items-center gap-1 font-bold text-primary">
                                <span class="material-symbols-outlined text-[16px]">add_circle</span>
                                <span id="detail-type">{{ $firstReq?->request_type ?? 'Tăng 01 buổi phụ đạo Speaking' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reason Block -->
                <div>
                    <span class="text-xs font-bold text-gray-800 block mb-2">Lý do xin giãn tiến độ từ Giáo viên</span>
                    <div class="bg-orange-50/30 border border-orange-200/60 rounded-xl p-4 text-xs text-gray-700 leading-relaxed relative">
                        <span class="material-symbols-outlined absolute top-3 left-3 text-orange-200 text-3xl pointer-events-none -mt-1 -ml-1">format_quote</span>
                        <p id="detail-reason" class="relative z-10 pl-6 italic">
                            {{ $firstReq?->reason ?? 'Học viên trong lớp đa số phản hồi phần Speaking part 2 và 3 còn yếu, cần thêm thời gian luyện tập thực tế trên lớp. Kính đề nghị phòng đào tạo duyệt cấp thêm 1 buổi phụ đạo theo quy chế.' }}
                        </p>
                    </div>
                </div>

                <!-- Reject Form (Hidden by default) -->
                <div id="reject-form" class="hidden bg-rose-50 border border-rose-200 rounded-xl p-3.5 text-xs space-y-2">
                    <label class="font-bold text-rose-700 block flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">warning</span>
                        <span>Lý do từ chối (Bắt buộc)</span>
                    </label>
                    <textarea id="reject-reason" class="w-full bg-white border border-rose-300 rounded-xl p-2.5 text-xs focus:border-rose-500 focus:ring-1 focus:ring-rose-500 outline-none" placeholder="Nhập lý do chi tiết để phản hồi lại giáo viên..." rows="3"></textarea>
                </div>
            </div>

            <!-- Action Footer -->
            <div class="p-4 border-t border-gray-100 bg-gray-50/50 flex justify-end gap-2">
                @if ($firstReq)
                    <!-- Default Actions -->
                    <div class="flex gap-2 w-full justify-end" id="default-actions">
                        <form id="reject-form-action" action="{{ route('syllabus.adjustment-requests.reject', $firstReq->id) }}" method="POST">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-xl border border-gray-200 bg-white hover:bg-rose-50 hover:text-rose-600 text-gray-700 text-xs font-bold transition flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px]">cancel</span>
                                <span>Từ chối</span>
                            </button>
                        </form>

                        <form id="approve-form-action" action="{{ route('syllabus.adjustment-requests.approve', $firstReq->id) }}" method="POST">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                <span>Phê duyệt</span>
                            </button>
                        </form>
                    </div>
                @else
                    <span class="text-xs text-gray-400">Không có yêu cầu để xử lý</span>
                @endif
            </div>
        </div>
    </div>

    <script>
        function selectRequest(id, cardEl, teacher, code, className, date, reqType, reason, status) {
            // Update active state on cards
            document.querySelectorAll('.request-card').forEach(c => {
                c.classList.remove('border-primary-container', 'bg-orange-50/30', 'ring-1', 'ring-primary-container/20');
                c.classList.add('border-gray-200', 'bg-white');
                c.querySelector('div.absolute').classList.remove('bg-primary-container');
                c.querySelector('div.absolute').classList.add('bg-transparent');
            });

            cardEl.classList.remove('border-gray-200', 'bg-white');
            cardEl.classList.add('border-primary-container', 'bg-orange-50/30', 'ring-1', 'ring-primary-container/20');
            cardEl.querySelector('div.absolute').classList.remove('bg-transparent');
            cardEl.querySelector('div.absolute').classList.add('bg-primary-container');

            // Update Right Pane data
            document.getElementById('detail-avatar').textContent = teacher.charAt(0);
            document.getElementById('detail-teacher').textContent = teacher;
            document.getElementById('detail-code').textContent = code;
            document.getElementById('detail-class').textContent = className;
            document.getElementById('detail-date').textContent = date;
            document.getElementById('detail-type').textContent = reqType;
            document.getElementById('detail-reason').textContent = reason;

            const badge = document.getElementById('detail-badge');
            if (status === 'approved') {
                badge.className = 'px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200';
                badge.textContent = 'Đã duyệt';
            } else if (status === 'rejected') {
                badge.className = 'px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200';
                badge.textContent = 'Đã từ chối';
            } else {
                badge.className = 'px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200';
                badge.textContent = 'Chờ xử lý';
            }

            // Update form actions
            const approveForm = document.getElementById('approve-form-action');
            const rejectForm = document.getElementById('reject-form-action');
            if (approveForm) {
                approveForm.action = `/syllabus/adjustment-requests/${id}/approve`;
            }
            if (rejectForm) {
                rejectForm.action = `/syllabus/adjustment-requests/${id}/reject`;
            }
        }
    </script>
</x-app-layout>
