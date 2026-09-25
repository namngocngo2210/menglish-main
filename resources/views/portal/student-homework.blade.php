<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('students.index') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">upload_file</span>
                        Học tập của tôi — Nộp bài tập (Cổng Học sinh / Phụ huynh)
                    </h1>
                    <p class="text-xs text-gray-500">Màn hình học sinh / phụ huynh theo dõi nhận xét buổi học, bảng điểm Mini/Big Test và thực hiện nộp bài tập về nhà.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.teacher.submissions') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">video_library</span>
                    <span>Cổng GV: Xem bài nộp của lớp</span>
                </a>
            </div>
        </div>
    </x-slot>

    

    <div class="max-w-4xl mx-auto space-y-6" x-data="{
        uploadModal: false,
        uploadType: 'video',
        uploadTitle: 'Quay video bài học',
        studentId: '{{ $student?->id ?? 1 }}',
        editModal: false,
        editId: null,
        editNotes: '',
        openUpload(type, title) {
            this.uploadType = type;
            this.uploadTitle = title;
            this.uploadModal = true;
        },
        openEdit(id, notes) {
            this.editId = id;
            this.editNotes = notes;
            this.editModal = true;
        }
    }">
        <!-- Student Switcher Bar (For Testing / Admin Viewing) -->
        <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined">account_circle</span>
                </div>
                <div>
                    <span class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Tài khoản học viên:</span>
                    <strong class="text-sm text-gray-900">{{ $student?->name ?? 'Nguyễn Minh Anh' }}</strong>
                    <span class="text-xs text-gray-500 font-mono">({{ $student?->code ?? 'HV-00109' }})</span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-500 font-medium">Đổi học viên:</span>
                <select class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container cursor-pointer"
                        onchange="window.location.href = '{{ route('portal.student.homework') }}/' + this.value">
                    @foreach($students as $st)
                        <option value="{{ $st->id }}" {{ ($student && $student->id === $st->id) ? 'selected' : '' }}>
                            {{ $st->name }} ({{ $st->currentClass?->name ?? 'Lớp IELTS' }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Student Mobile Frame: Exact Match to 04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap -->
        <div class="max-w-[420px] mx-auto bg-white rounded-3xl border border-gray-200 shadow-xl overflow-hidden pb-8">
            <!-- Decorative Header Area -->
            <div class="w-full h-[140px] bg-gradient-to-br from-primary-container to-orange-400 p-6 flex flex-col justify-end text-white relative">
                <div class="absolute top-3 right-3 bg-white/20 backdrop-blur-xs px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wider uppercase">
                    MENGLISH LMS
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-white drop-shadow-sm">Học tập của tôi</h1>
                <p class="text-xs text-white/80 mt-0.5">Lớp: {{ $student?->currentClass?->name ?? 'Starters 1A - Tuần 4' }}</p>
            </div>

            <!-- Subtab Switcher: Nộp bài tập / Luyện phát âm -->
            <div class="flex items-center border-b border-gray-200 bg-gray-50 px-3 pt-2">
                <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}"
                   class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                    <span class="material-symbols-outlined text-[16px]">assignment</span>
                    <span>Nộp bài tập</span>
                </a>
                <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}"
                   class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-gray-500 hover:text-gray-900 font-semibold text-xs transition">
                    <span class="material-symbols-outlined text-[16px]">mic</span>
                    <span>Luyện phát âm AI</span>
                </a>
            </div>

            <div class="px-4 flex flex-col gap-6 mt-4 pb-20">
                <!-- 1. BÁO CÁO BUỔI HỌC -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">insights</span>
                        <h2 class="text-base font-bold text-gray-900">Nhận xét buổi học</h2>
                    </div>

                    <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-2xs flex flex-col gap-3">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-[11px] text-gray-400 font-medium mb-0.5">Ngày 15/10/2023</p>
                                <h3 class="text-xs font-bold text-gray-900">Unit 4: Present Continuous &amp; Vocabulary</h3>
                            </div>
                            <span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-full text-[10px] font-bold border border-emerald-200">
                                Đã nhận xét
                            </span>
                        </div>

                        <!-- 4 Stat Badges -->
                        <div class="grid grid-cols-2 gap-2">
                            <div class="bg-gray-50 rounded-xl p-2.5 flex items-center gap-2 border border-gray-100">
                                <span class="material-symbols-outlined text-amber-500 text-lg">hotel_class</span>
                                <div>
                                    <p class="text-[10px] text-gray-400 font-medium">Monsters</p>
                                    <p class="text-xs font-bold text-gray-900">850 pt</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-2.5 flex items-center gap-2 border border-gray-100">
                                <span class="material-symbols-outlined text-blue-500 text-lg">psychology</span>
                                <div>
                                    <p class="text-[10px] text-gray-400 font-medium">Ngữ pháp</p>
                                    <p class="text-xs font-bold text-gray-900">Tốt</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-2.5 flex items-center gap-2 border border-gray-100">
                                <span class="material-symbols-outlined text-emerald-500 text-lg">mood</span>
                                <div>
                                    <p class="text-[10px] text-gray-400 font-medium">Tinh thần</p>
                                    <p class="text-xs font-bold text-gray-900">Tích cực</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-2.5 flex items-center gap-2 border border-gray-100">
                                <span class="material-symbols-outlined text-purple-500 text-lg">checklist</span>
                                <div>
                                    <p class="text-[10px] text-gray-400 font-medium">Kết quả</p>
                                    <p class="text-xs font-bold text-gray-900">Đạt (8/10)</p>
                                </div>
                            </div>
                        </div>

                        <!-- Teacher Comment -->
                        <div class="border-t border-gray-100 pt-3">
                            <p class="text-[11px] text-gray-500 mb-1 flex items-center gap-1 font-bold">
                                <span class="material-symbols-outlined text-[15px] text-primary">edit_note</span>
                                Nhận xét chi tiết từ giáo viên
                            </p>
                            <p class="text-xs text-gray-700 leading-relaxed bg-orange-50/40 p-2.5 rounded-xl border border-orange-100">
                                "Hôm nay con học rất tốt, hăng hái phát biểu xây dựng bài. Phần Present Continuous con đã nắm vững cấu trúc nhưng đôi khi quên thêm 'ing' vào động từ. Về từ vựng, con nhớ bài cũ khá tốt. Cố gắng phát huy nhé!"
                            </p>
                        </div>
                    </div>
                </section>

                <!-- 2. BẢNG ĐIỂM -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">school</span>
                        <h2 class="text-base font-bold text-gray-900">Bảng điểm</h2>
                    </div>
                    <div class="flex flex-col gap-2">
                        <div class="bg-white rounded-xl p-3.5 border border-gray-200 shadow-2xs flex items-center justify-between">
                            <div>
                                <h4 class="text-xs font-bold text-gray-900">Mini Test 1</h4>
                                <p class="text-[10px] text-gray-400 font-mono">Ngày thi: 01/10/2023</p>
                            </div>
                            <div class="text-lg font-black text-primary font-mono">8.5</div>
                        </div>
                        <div class="bg-white rounded-xl p-3.5 border border-gray-200 shadow-2xs flex items-center justify-between">
                            <div>
                                <h4 class="text-xs font-bold text-gray-900">Big Test Mid-term</h4>
                                <p class="text-[10px] text-gray-400 font-mono">Ngày thi: 12/10/2023</p>
                            </div>
                            <div class="bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full text-[10px] font-bold border border-gray-200">
                                Đang chờ kết quả
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 3. BÀI TẬP VỀ NHÀ (INTERACTIVE SUBMISSION) -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">assignment</span>
                        <h2 class="text-base font-bold text-gray-900">Bài tập về nhà</h2>
                    </div>

                    <!-- Ghi chú nhắc nhở từ GV Banner -->
                    <div class="bg-rose-50 border border-rose-200 rounded-2xl p-3.5 flex gap-2.5">
                        <span class="material-symbols-outlined text-rose-500 text-[20px] shrink-0">campaign</span>
                        <div>
                            <h4 class="text-xs font-bold text-rose-700">Ghi chú nhắc nhở từ Giáo viên</h4>
                            <p class="text-[11px] text-rose-900/80 mt-0.5 leading-normal">
                                Các con nhớ ôn lại từ vựng Unit 4 và hoàn thành bài tập quay video trước thứ 6 nhé!
                            </p>
                        </div>
                    </div>

                    @php
                        $homeworkCategories = [
                            'video' => [
                                'title' => 'Quay video bài học',
                                'icon' => 'videocam',
                                'color' => 'text-primary',
                                'btn_text' => 'Tải lên video',
                                'is_quiz' => false,
                            ],
                            'vocabulary' => [
                                'title' => 'Viết từ vựng & Chụp ảnh',
                                'icon' => 'edit_document',
                                'color' => 'text-blue-600',
                                'btn_text' => 'Tải lên bài viết',
                                'is_quiz' => false,
                            ],
                            'workbook' => [
                                'title' => 'Làm Workbook bài tập',
                                'icon' => 'menu_book',
                                'color' => 'text-indigo-600',
                                'btn_text' => 'Tải lên Workbook',
                                'is_quiz' => false,
                            ],
                            'extra_book' => [
                                'title' => 'Sách bổ trợ',
                                'icon' => 'library_books',
                                'color' => 'text-emerald-600',
                                'btn_text' => 'Tải lên bài làm',
                                'is_quiz' => false,
                            ],
                            'bgd_book' => [
                                'title' => 'Sách Bộ Giáo dục',
                                'icon' => 'import_contacts',
                                'color' => 'text-purple-600',
                                'btn_text' => 'Tải lên bài làm',
                                'is_quiz' => false,
                            ],
                            'quiz' => [
                                'title' => 'Làm Quiz trực tuyến',
                                'icon' => 'quiz',
                                'color' => 'text-amber-600',
                                'btn_text' => 'Nộp kết quả Quiz',
                                'is_quiz' => true,
                            ],
                        ];
                    @endphp

                    <!-- Progress Bar -->
                    <div class="flex items-center justify-between mt-1">
                        <p class="text-xs font-bold text-gray-800">Đã hoàn thành: {{ $completedCount }}/6 hạng mục</p>
                        <div class="w-1/3 bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div class="bg-emerald-500 h-full rounded-full transition-all duration-300" style="width: {{ round(($completedCount / 6) * 100) }}%"></div>
                        </div>
                    </div>

                    <!-- Homework Cards List (Real DB Integration) -->
                    <div class="flex flex-col gap-2.5">
                        @foreach($homeworkCategories as $key => $cat)
                            @php
                                $sub = $submissionsByType[$key] ?? null;
                            @endphp
                            <div class="bg-white rounded-2xl p-3.5 border border-gray-200 shadow-2xs flex flex-col gap-2">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined {{ $cat['color'] }} text-lg">{{ $cat['icon'] }}</span>
                                        <h4 class="text-xs font-bold text-gray-900">{{ $cat['title'] }}</h4>
                                    </div>
                                    @if($sub)
                                        <span class="text-emerald-700 font-bold text-[10px] bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                            <span class="material-symbols-outlined text-[12px]">check_circle</span> Đã nộp
                                        </span>
                                    @else
                                        <span class="text-rose-600 font-bold text-[10px] bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-full">
                                            Chưa nộp
                                        </span>
                                    @endif
                                </div>

                                @if($sub)
                                    <div class="space-y-1 bg-gray-50 p-2.5 rounded-xl text-xs border border-gray-100">
                                        <div class="flex items-center justify-between text-[10px] text-gray-500">
                                            <span>Nộp lúc: <strong class="font-mono text-gray-700">{{ $sub->data['submitted_at'] ?? $sub->created_at->format('d/m/Y H:i') }}</strong></span>
                                            @if($sub->status === 'reviewed')
                                                <span class="text-emerald-600 font-bold bg-emerald-100 px-1.5 py-0.5 rounded">Đã chấm: {{ $sub->data['score'] ?? '10/10' }}</span>
                                            @else
                                                <span class="text-amber-600 font-medium">Chờ giáo viên chấm</span>
                                            @endif
                                        </div>

                                        @if(!empty($sub->data['attachment_path']))
                                            <div class="pt-1">
                                                <a href="{{ $sub->data['attachment_path'] }}" target="_blank" class="inline-flex items-center gap-1 text-[11px] text-primary font-bold hover:underline">
                                                    <span class="material-symbols-outlined text-[14px]">attachment</span>
                                                    <span>{{ $sub->data['attachment_name'] ?? 'Xem tệp đính kèm' }}</span>
                                                </a>
                                            </div>
                                        @endif

                                        @if(!empty($sub->data['notes']))
                                            <p class="text-[11px] text-gray-600 italic">"{{ $sub->data['notes'] }}"</p>
                                        @endif

                                        @if(!empty($sub->data['feedback']))
                                            <div class="bg-blue-50 text-blue-800 p-2 rounded-lg text-[11px] mt-1 border border-blue-100">
                                                <strong>Nhận xét của GV:</strong> {{ $sub->data['feedback'] }}
                                            </div>
                                        @endif
                                    </div>

                                    <!-- CRUD Actions: Sửa ghi chú / Nộp lại & Hủy nộp -->
                                    <div class="flex items-center gap-2 pt-1">
                                        <button type="button"
                                                @click="openEdit({{ $sub->id }}, '{{ addslashes($sub->data['notes'] ?? '') }}')"
                                                class="flex-1 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 font-bold text-xs rounded-xl flex items-center justify-center gap-1 border border-gray-200 transition">
                                            <span class="material-symbols-outlined text-[14px]">edit</span>
                                            Sửa / Nộp lại
                                        </button>
                                        <form action="{{ route('portal.student.homework.destroy', $sub->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn hủy bài nộp này không?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs rounded-xl border border-rose-200 transition flex items-center gap-0.5" title="Hủy nộp">
                                                <span class="material-symbols-outlined text-[15px]">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <div class="flex flex-col gap-1.5 pt-1">
                                        <button type="button"
                                                @click="openUpload('{{ $key }}', '{{ $cat['title'] }}')"
                                                class="w-full py-2 bg-primary-container hover:bg-primary-dark text-white font-bold text-xs rounded-xl flex items-center justify-center gap-1.5 shadow-sm transition">
                                            <span class="material-symbols-outlined text-[18px]">upload</span>
                                            {{ $cat['btn_text'] }}
                                        </button>
                                        @if($cat['is_quiz'])
                                            <a href="https://quizizz.com" target="_blank" class="inline-flex items-center justify-center gap-1 text-secondary font-bold text-xs hover:underline mt-0.5">
                                                <span>Mở link Quiz trực tuyến</span>
                                                <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <!-- 4. LỘ TRÌNH HỌC TẬP -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">map</span>
                        <h2 class="text-base font-bold text-gray-900">Lộ trình học tập</h2>
                    </div>
                    <div class="bg-white rounded-2xl p-3 border border-gray-200 shadow-2xs">
                        <div class="p-4 bg-gray-50 rounded-xl flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center">
                                <span class="material-symbols-outlined text-[28px]">trending_up</span>
                            </div>
                            <h3 class="text-xs font-bold text-gray-900">Lộ trình mục tiêu: IELTS 6.5</h3>
                            <p class="text-[11px] text-gray-500 max-w-[280px]">Đang học Chặng 2 (Intermediate). Đạt 65% thời lượng chương trình.</p>
                            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden mt-2">
                                <div class="bg-primary-container h-full rounded-full" style="width: 65%"></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Bottom Navigation Bar Component -->
            @include('portal.partials.bottom-nav', ['activeTab' => 'learning', 'student' => $student])
        </div>

        <!-- Upload Modal (Submits real homework record) -->
        <div x-show="uploadModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4" @click.away="uploadModal = false">
                <div class="flex justify-between items-center border-b pb-3">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">cloud_upload</span>
                        <span x-text="'Nộp bài: ' + uploadTitle"></span>
                    </h3>
                    <button @click="uploadModal = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form action="{{ route('portal.student.homework.submit') }}" method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                    @csrf
                    <input type="hidden" name="student_id" :value="studentId">
                    <input type="hidden" name="homework_type" :value="uploadType">

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Chọn tệp tin bài làm (Video / Ảnh / PDF)</label>
                        <div class="border-2 border-dashed border-gray-300 hover:border-primary-container rounded-xl p-6 text-center cursor-pointer bg-gray-50 transition">
                            <span class="material-symbols-outlined text-[36px] text-gray-400 block mb-1">attach_file</span>
                            <span class="text-xs text-gray-600 block">Kéo thả hoặc bấm để chọn tệp tải lên</span>
                            <span class="text-[10px] text-gray-400 block mt-1">Hỗ trợ MP4, MOV, PNG, JPG, PDF (tối đa 50MB)</span>
                            <input type="file" name="attachment" class="mt-3 text-xs w-full text-center">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Ghi chú gửi thầy cô (Tùy chọn)</label>
                        <textarea name="notes" rows="3" placeholder="Nhập ghi chú cho bài nộp..." class="w-full p-2.5 border rounded-xl bg-gray-50 text-xs focus:ring-primary-container"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t">
                        <button type="button" @click="uploadModal = false" class="px-4 py-2 border rounded-xl font-semibold text-gray-600 hover:bg-gray-100">
                            Hủy
                        </button>
                        <button type="submit" class="px-5 py-2 bg-primary-container text-white rounded-xl font-bold shadow-sm hover:bg-primary-dark transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">send</span>
                            <span>Xác nhận nộp bài</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Edit Modal (Updates existing homework submission) -->
        <div x-show="editModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4" @click.away="editModal = false">
                <div class="flex justify-between items-center border-b pb-3">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">edit_note</span>
                        <span>Chỉnh sửa / Cập nhật bài nộp</span>
                    </h3>
                    <button @click="editModal = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form :action="'{{ url('portal/student-homework') }}/' + editId + '/update'" method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                    @csrf
                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Cập nhật lại tệp tin bài làm (Nếu muốn thay thế tệp cũ)</label>
                        <input type="file" name="attachment" class="w-full text-xs p-2 border rounded-xl bg-gray-50">
                        <span class="text-[10px] text-gray-400 block mt-1">Để trống nếu muốn giữ nguyên tệp tin đã tải lên trước đó.</span>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Ghi chú gửi thầy cô</label>
                        <textarea name="notes" x-model="editNotes" rows="3" class="w-full p-2.5 border rounded-xl bg-gray-50 text-xs focus:ring-primary-container"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t">
                        <button type="button" @click="editModal = false" class="px-4 py-2 border rounded-xl font-semibold text-gray-600 hover:bg-gray-100">
                            Hủy
                        </button>
                        <button type="submit" class="px-5 py-2 bg-primary-container text-white rounded-xl font-bold shadow-sm hover:bg-primary-dark transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">save</span>
                            <span>Lưu cập nhật</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
