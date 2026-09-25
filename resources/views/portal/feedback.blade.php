<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-600">rate_review</span>
                        Flow 4 — Bước 7: Phụ huynh gửi Feedback chặng học (MH6)
                    </h1>
                    <p class="text-xs text-gray-500">Màn hình đánh giá chặng học 5 sao, lĩnh vực góp ý và gửi phản ánh chất lượng đào tạo.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">assignment</span>
                    <span>Khảo sát định kỳ</span>
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $existingData = $lastFeedback?->data ?? [];
        $savedRating = $existingData['muc_do_hai_long'] ?? 0;
        $savedContent = $existingData['noi_dung_feedback'] ?? '';
        $savedHocThuat = !empty($existingData['fb_hoc_thuat']);
        $savedGiaoVien = !empty($existingData['fb_giao_vien']);
        $savedKhac = !empty($existingData['fb_khac']);
        $hasSaved = !empty($lastFeedback);
    @endphp

    <!-- Mobile Frame for Feedback (Matches 04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback) -->
    <div class="max-w-[430px] mx-auto bg-white min-h-[844px] shadow-2xl rounded-3xl border border-gray-200 overflow-hidden flex flex-col relative pb-24 my-4"
         x-data="{
            viewState: '{{ $hasSaved ? 'form-updated' : 'form-new' }}', // 'form-new', 'form-updated', 'state-closed', 'state-empty'
            rating: {{ $savedRating }},
            ratingLabels: ['Chưa chọn', 'Rất không hài lòng', 'Không hài lòng', 'Bình thường', 'Hài lòng', 'Rất hài lòng'],
            fbHocThuat: {{ $savedHocThuat ? 'true' : 'false' }},
            fbGiaoVien: {{ $savedGiaoVien ? 'true' : 'false' }},
            fbKhac: {{ $savedKhac ? 'true' : 'false' }},
            noiDung: '{{ addslashes($savedContent) }}',
            showValidationError: false,
            serverError: null,
            setRating(stars) {
                this.rating = stars;
                this.showValidationError = false;
            },
            validateForm(e) {
                let hasRating = this.rating > 0;
                let hasCat = this.fbHocThuat || this.fbGiaoVien || this.fbKhac;
                let hasText = this.noiDung.trim().length > 0;

                if (!hasRating && !hasCat && !hasText) {
                    e.preventDefault();
                    this.showValidationError = true;
                    return false;
                }
                this.showValidationError = false;
                return true;
            }
         }">

        <!-- State Demo Switcher Bar (Exact Match Prototype for Testing 4 Business States) -->
        <div class="bg-gray-900 text-white px-3 py-2 text-[11px] flex items-center justify-between sticky top-0 z-50 shadow-md">
            <div class="flex items-center gap-1.5 font-medium text-gray-300">
                <span class="inline-block w-2 h-2 rounded-full bg-primary-container animate-pulse"></span>
                <span>Trạng thái:</span>
            </div>
            <div class="flex gap-1 overflow-x-auto">
                <button type="button"
                        @click="viewState = 'form-new'; serverError = null;"
                        class="px-2 py-0.5 rounded text-[10px] font-medium transition"
                        :class="viewState === 'form-new' ? 'bg-primary-container text-white font-bold' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'">
                    Lần đầu
                </button>
                <button type="button"
                        @click="viewState = 'form-updated'; serverError = null;"
                        class="px-2 py-0.5 rounded text-[10px] font-medium transition"
                        :class="viewState === 'form-updated' ? 'bg-primary-container text-white font-bold' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'">
                    Đã gửi (sửa)
                </button>
                <button type="button"
                        @click="viewState = 'state-closed'; serverError = null;"
                        class="px-2 py-0.5 rounded text-[10px] font-medium transition"
                        :class="viewState === 'state-closed' ? 'bg-primary-container text-white font-bold' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'">
                    Đợt đóng
                </button>
                <button type="button"
                        @click="viewState = 'state-empty'; serverError = null;"
                        class="px-2 py-0.5 rounded text-[10px] font-medium transition"
                        :class="viewState === 'state-empty' ? 'bg-primary-container text-white font-bold' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'">
                    Chưa mở
                </button>
            </div>
        </div>

        <!-- Header Partial -->
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Feedback chặng',
            'showBack' => true,
            'backUrl' => route('portal.student.survey', ['studentId' => $student?->id])
        ])

        <!-- Subtab Switcher -->
        <div class="flex items-center border-b border-gray-200 bg-gray-50 px-3 pt-2">
            <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-gray-500 hover:text-gray-900 font-semibold text-xs transition">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Khảo sát định kỳ</span>
            </a>
            <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                <span class="material-symbols-outlined text-[16px]">rate_review</span>
                <span>Feedback chặng học (MH6)</span>
            </a>
        </div>

        <!-- Context Header -->
        <div class="px-4 pt-3 pb-2 border-b border-gray-100 flex items-center justify-between">
            <div>
                <span class="text-[10px] font-bold text-primary uppercase tracking-wider bg-orange-50 px-2 py-0.5 rounded-full inline-block mb-0.5">
                    Ý kiến đóng góp
                </span>
                <h2 class="text-base font-bold text-gray-900">Đánh giá chặng học</h2>
            </div>
            <div class="text-right">
                <span class="text-[10px] text-gray-400 font-medium block">Học sinh</span>
                <span class="text-xs font-bold text-gray-800">{{ $student?->name ?? 'Trần Minh Khang' }}</span>
            </div>
        </div>

        <!-- Main Body Content -->
        <main class="flex-1 p-4 space-y-4 overflow-y-auto">

            <!-- ======================================================== -->
            <!-- VIEW 1: FORM NHẬP / CHỈNH SỬA (Lần đầu & Đã gửi sửa tiếp) -->
            <!-- ======================================================== -->
            <div x-show="viewState === 'form-new' || viewState === 'form-updated'" class="space-y-4">

                <!-- Success Banner (When submitted or simulated updated) -->
                @if(session('success') || session('feedback_success'))
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 flex items-start gap-2.5">
                        <span class="material-symbols-outlined text-emerald-600 text-[20px] shrink-0 mt-0.5">check_circle</span>
                        <div class="flex-1 text-xs text-emerald-800">
                            <strong class="font-bold text-emerald-900 block">Đã ghi nhận feedback thành công</strong>
                            <span>Bạn có thể điều chỉnh và bấm "Cập nhật" bất cứ lúc nào trong thời gian đợt thu thập còn mở.</span>
                        </div>
                    </div>
                @endif

                <div x-show="viewState === 'form-updated' && !{{ session('feedback_success') ? 'true' : 'false' }}"
                     class="bg-blue-50 border border-blue-200 rounded-xl p-3 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-blue-600 text-[20px] shrink-0 mt-0.5">info</span>
                    <div class="flex-1 text-xs text-blue-800">
                        <strong class="font-bold text-blue-900 block">Bạn đã gửi đánh giá trước đó</strong>
                        <span>Bạn có thể thay đổi số sao hoặc nội dung góp ý bên dưới rồi bấm "Cập nhật feedback".</span>
                    </div>
                </div>

                <!-- 1. Tên Chặng Đang Mở Thu Thập (Read-only, R-02) -->
                <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3.5 shadow-2xs">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Chặng học đang mở thu thập</span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                            Đang mở thu thập
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-xl bg-orange-100 flex items-center justify-center text-primary font-black text-sm shrink-0">
                                C2
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-gray-900 leading-tight">{{ $stageName }}</h3>
                                <p class="text-[11px] text-gray-500 mt-0.5">Lớp: <span class="font-bold text-gray-800">{{ $className }}</span></p>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-[18px]" title="Cố định theo chặng đang mở của lớp">lock</span>
                    </div>
                </div>

                <!-- FORM CHÍNH -->
                <form action="{{ route('portal.student.feedback.store') }}" method="POST" @submit="validateForm($event)" class="space-y-4">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                    <input type="hidden" name="stage_name" value="{{ $stageName }}">
                    <input type="hidden" name="muc_do_hai_long" :value="rating">

                    <!-- 1. Chọn Mức Hài Lòng 1-5 Sao (R-04) -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-2xs">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-gray-800 flex items-center gap-1">
                                <span>1. Mức độ hài lòng chung</span>
                                <span class="text-[10px] font-normal text-gray-400">(Tùy chọn)</span>
                            </label>
                            <span class="text-xs font-bold text-primary" x-text="ratingLabels[rating]"></span>
                        </div>

                        <!-- 5 Stars Rating Bar -->
                        <div class="flex items-center justify-between py-1 px-1">
                            <template x-for="star in [1, 2, 3, 4, 5]" :key="star">
                                <button type="button"
                                        @click="setRating(star)"
                                        class="p-2 rounded-xl hover:bg-orange-50 focus:outline-none flex flex-col items-center gap-1 transition active:scale-95 group">
                                    <span class="material-symbols-outlined text-3xl transition-transform group-hover:scale-110"
                                          :class="star <= rating ? 'text-amber-400' : 'text-gray-300'"
                                          :style="star <= rating ? 'font-variation-settings: \'FILL\' 1;' : ''">
                                        star
                                    </span>
                                    <span class="text-[10px] font-mono font-bold"
                                          :class="star <= rating ? 'text-amber-600' : 'text-gray-400'"
                                          x-text="star"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- 2. Lĩnh Vực Cần Góp Ý (3 Checkboxes - R-04) -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-2xs">
                        <label class="text-xs font-bold text-gray-800 flex items-center justify-between mb-2.5">
                            <span>2. Lĩnh vực cần góp ý</span>
                            <span class="text-[10px] font-normal text-gray-400">(Tùy chọn)</span>
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            <!-- Checkbox 1: fb_hoc_thuat -->
                            <label class="cursor-pointer flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all"
                                   :class="fbHocThuat ? 'border-primary-container bg-orange-50/50 shadow-2xs' : 'border-gray-200 hover:border-gray-300'">
                                <input type="checkbox" name="fb_hoc_thuat" value="1" x-model="fbHocThuat" @change="showValidationError = false" class="rounded border-gray-300 text-primary focus:ring-primary-container mb-1">
                                <span class="text-xs font-semibold text-gray-700 select-none">Học thuật</span>
                            </label>

                            <!-- Checkbox 2: fb_giao_vien -->
                            <label class="cursor-pointer flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all"
                                   :class="fbGiaoVien ? 'border-primary-container bg-orange-50/50 shadow-2xs' : 'border-gray-200 hover:border-gray-300'">
                                <input type="checkbox" name="fb_giao_vien" value="1" x-model="fbGiaoVien" @change="showValidationError = false" class="rounded border-gray-300 text-primary focus:ring-primary-container mb-1">
                                <span class="text-xs font-semibold text-gray-700 select-none">Giáo viên</span>
                            </label>

                            <!-- Checkbox 3: fb_khac -->
                            <label class="cursor-pointer flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all"
                                   :class="fbKhac ? 'border-primary-container bg-orange-50/50 shadow-2xs' : 'border-gray-200 hover:border-gray-300'">
                                <input type="checkbox" name="fb_khac" value="1" x-model="fbKhac" @change="showValidationError = false" class="rounded border-gray-300 text-primary focus:ring-primary-container mb-1">
                                <span class="text-xs font-semibold text-gray-700 select-none">Khác</span>
                            </label>
                        </div>
                    </div>

                    <!-- 3. Textarea Nội Dung Feedback Chi Tiết (R-04) -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-2xs">
                        <div class="flex items-center justify-between mb-2">
                            <label for="feedback-content" class="text-xs font-bold text-gray-800">
                                3. Nội dung feedback chi tiết
                            </label>
                            <span class="text-[10px] text-gray-400">(Tùy chọn)</span>
                        </div>
                        <textarea id="feedback-content"
                                  name="noi_dung_feedback"
                                  rows="4"
                                  x-model="noiDung"
                                  @input="showValidationError = false"
                                  placeholder="Chia sẻ cảm nhận của phụ huynh/học sinh về giáo trình, phương pháp giảng dạy hoặc điểm cần hỗ trợ thêm..."
                                  class="w-full text-xs text-gray-800 placeholder-gray-400 border border-gray-300 rounded-xl p-3 focus:outline-none focus:border-primary-container focus:ring-1 focus:ring-primary-container resize-none transition-all"></textarea>
                        <p class="text-[10px] text-gray-400 mt-1">Ý kiến chân thực giúp trung tâm nâng cao chất lượng dạy học.</p>
                    </div>

                    <!-- Thông Báo Lỗi Validation: Khi cả 3 mục đều trống (R-04) -->
                    <div x-show="showValidationError" x-cloak class="bg-amber-50 border border-amber-300 rounded-xl p-3 flex items-center gap-2.5 shadow-2xs">
                        <span class="material-symbols-outlined text-amber-600 text-[20px] shrink-0">warning</span>
                        <p class="text-xs font-medium text-amber-900 leading-snug">
                            Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.
                        </p>
                    </div>

                    <!-- Nút Gửi / Cập nhật feedback -->
                    <div>
                        <button type="submit"
                                class="w-full py-3.5 px-4 bg-primary-container hover:bg-primary text-white font-bold text-xs rounded-xl shadow-md transition-all flex items-center justify-center gap-2 active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">send</span>
                            <span x-text="viewState === 'form-updated' ? 'Cập nhật feedback' : 'Gửi feedback'"></span>
                        </button>
                        <p class="text-center text-[10px] text-gray-400 mt-2">
                            Sau khi gửi, bạn vẫn có thể chỉnh sửa lại trong thời gian đợt thu thập còn mở.
                        </p>
                    </div>
                </form>

                @if($hasSaved)
                    <div x-show="viewState === 'form-updated'" class="pt-1">
                        <form action="{{ route('portal.student.feedback.destroy', ['id' => $lastFeedback->id]) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa bản feedback này không?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="w-full py-2.5 px-4 border border-rose-200 bg-rose-50/50 hover:bg-rose-100/60 text-rose-600 text-xs font-semibold rounded-xl transition flex items-center justify-center gap-1.5 active:scale-95">
                                <span class="material-symbols-outlined text-[16px]">delete_sweep</span>
                                <span>Xóa phản hồi đã lưu & nhập lại</span>
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            <!-- ======================================================== -->
            <!-- VIEW 2: READ-ONLY KHI ĐỢT THU THẬP ĐÃ ĐÓNG (AC-R06b) -->
            <!-- ======================================================== -->
            <div x-show="viewState === 'state-closed'" x-cloak class="space-y-4">
                <!-- Banner cảnh báo Đợt thu thập đã đóng -->
                <div class="bg-gray-100 border border-gray-300 rounded-2xl p-3.5 flex items-start gap-3">
                    <div class="w-6 h-6 rounded-full bg-gray-600 text-white flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-[14px]">lock</span>
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-gray-900">Đợt thu thập feedback đã đóng</h4>
                        <p class="text-xs text-gray-600 mt-0.5 leading-relaxed">
                            Trung tâm đã kết thúc đợt thu thập ý kiến cho chặng này. Dưới đây là nội dung bạn đã gửi (chỉ xem, không thể chỉnh sửa thêm).
                        </p>
                    </div>
                </div>

                <!-- Tên chặng đã đóng -->
                <div class="bg-gray-50 border border-gray-200 rounded-2xl p-4 shadow-2xs">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Chặng học đã hoàn thành</span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-gray-600 bg-gray-200 px-2 py-0.5 rounded-full">
                            Đã đóng
                        </span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-gray-200 flex items-center justify-center text-gray-700 font-bold text-sm shrink-0">
                            C1
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-gray-900 leading-tight">Chặng 1: Nền tảng Ngữ pháp & Từ vựng</h3>
                            <p class="text-[11px] text-gray-500 mt-0.5">Lớp: {{ $className }}</p>
                        </div>
                    </div>
                </div>

                <!-- Read-only Card Nội dung đã nộp -->
                <div class="bg-white border border-gray-200 rounded-2xl p-4 space-y-3.5 shadow-2xs">
                    <!-- Mức độ hài lòng -->
                    <div class="border-b border-gray-100 pb-3">
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider block mb-1">Mức độ hài lòng đã gửi</span>
                        <div class="flex items-center gap-2">
                            <div class="flex text-amber-400">
                                @for($star = 1; $star <= 5; $star++)
                                    <span class="material-symbols-outlined text-[20px] {{ $star <= ($savedRating > 0 ? $savedRating : 4) ? 'text-amber-400' : 'text-gray-200' }}" {!! $star <= ($savedRating > 0 ? $savedRating : 4) ? 'style="font-variation-settings: \'FILL\' 1;"' : '' !!}>star</span>
                                @endfor
                            </div>
                            <span class="text-xs font-bold text-gray-900">{{ $savedRating > 0 ? $savedRating : 4 }} / 5</span>
                            <span class="text-[11px] text-gray-500 font-medium">({{ ['Chưa chọn', 'Rất không hài lòng', 'Không hài lòng', 'Bình thường', 'Hài lòng', 'Rất hài lòng'][$savedRating > 0 ? $savedRating : 4] ?? 'Hài lòng' }})</span>
                        </div>
                    </div>

                    <!-- Lĩnh vực góp ý -->
                    <div class="border-b border-gray-100 pb-3">
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider block mb-1.5">Lĩnh vực đã chọn</span>
                        <div class="flex flex-wrap gap-2">
                            @if($savedHocThuat || (!$savedHocThuat && !$savedGiaoVien && !$savedKhac))
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-secondary border border-blue-200">
                                    ✓ Học thuật
                                </span>
                            @endif
                            @if($savedGiaoVien || (!$savedHocThuat && !$savedGiaoVien && !$savedKhac))
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-secondary border border-blue-200">
                                    ✓ Giáo viên
                                </span>
                            @endif
                            @if($savedKhac)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-secondary border border-blue-200">
                                    ✓ Khác
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Nội dung chi tiết -->
                    <div>
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider block mb-1">Nội dung đã gửi</span>
                        <p class="text-xs text-gray-800 bg-gray-50 rounded-xl p-3 border border-gray-200 leading-relaxed font-normal">
                            {{ !empty($savedContent) ? $savedContent : 'Giáo viên giảng dạy nhiệt tình, bài tập ngữ pháp sát thực tế. Tuy nhiên phần bài tập nghe về nhà đôi khi file audio hơi nhanh với sức học của con, mong thầy cô có thêm phiên bản phát âm chậm.' }}
                        </p>
                    </div>
                </div>

                <div class="text-center py-2 text-xs text-gray-400">
                    Cảm ơn bạn đã đóng góp ý kiến xây dựng chất lượng đào tạo.
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- VIEW 3: TRẠNG THÁI RỖNG (Chưa có đợt thu thập nào mở - R-02) -->
            <!-- ======================================================== -->
            <div x-show="viewState === 'state-empty'" x-cloak class="flex flex-col items-center justify-center py-10 px-4 text-center">
                <div class="w-16 h-16 rounded-full bg-orange-50 border border-orange-200 flex items-center justify-center text-primary mb-4 shadow-inner">
                    <span class="material-symbols-outlined text-3xl">chat_bubble_outline</span>
                </div>
                <h3 class="text-sm font-bold text-gray-900 mb-1">Hiện chưa có đợt thu thập nào đang mở</h3>
                <p class="text-xs text-gray-500 max-w-[280px] leading-relaxed mb-6">
                    Giáo viên và Ban Học vụ sẽ mở cổng tiếp nhận đánh giá khi hoàn thành từng chặng của khóa học.
                </p>
                <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3.5 text-left w-full shadow-2xs">
                    <div class="flex items-center gap-1.5 text-xs font-bold text-gray-800 mb-1">
                        <span class="material-symbols-outlined text-primary text-[16px]">info</span>
                        <span>Lưu ý từ Trung tâm</span>
                    </div>
                    <p class="text-[11px] text-gray-600 leading-relaxed">
                        Nếu phụ huynh hoặc học sinh cần phản ánh khẩn cấp về việc học tập, vui lòng liên hệ trực tiếp với Cố vấn Học tập (CM) qua mục Thông báo hoặc hotline trung tâm.
                    </p>
                </div>
            </div>

        </main>

        <!-- Bottom Navigation Bar Component -->
        @include('portal.partials.bottom-nav', ['activeTab' => 'survey', 'student' => $student])
    </div>
</x-app-layout>
