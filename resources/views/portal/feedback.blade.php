<x-app-layout>
    <x-ui.page-header title="Góp ý chặng học" icon="rate_review" :back="route('portal.student.survey', ['studentId' => $student?->id])">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="assignment" :href="route('portal.student.survey', ['studentId' => $student?->id])">Khảo sát định kỳ</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $existingData = $lastFeedback?->data ?? [];
        $savedRating = $existingData['muc_do_hai_long'] ?? 0;
        $savedContent = $existingData['noi_dung_feedback'] ?? '';
        $savedHocThuat = !empty($existingData['fb_hoc_thuat']);
        $savedGiaoVien = !empty($existingData['fb_giao_vien']);
        $savedKhac = !empty($existingData['fb_khac']);
        $hasSaved = !empty($lastFeedback);
    @endphp

    {{-- Mobile Frame for Feedback --}}
    <div class="max-w-[430px] mx-auto bg-surface-container-lowest min-h-[844px] shadow-2xl rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-24 my-4"
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

        {{-- Header Partial --}}
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Feedback chặng',
            'showBack' => true,
            'backUrl' => route('portal.student.survey', ['studentId' => $student?->id])
        ])

        {{-- Subtab Switcher --}}
        <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
            <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-on-surface-variant hover:text-on-surface font-semibold text-xs transition">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Khảo sát định kỳ</span>
            </a>
            <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                <span class="material-symbols-outlined text-[16px]">rate_review</span>
                <span>Feedback chặng học</span>
            </a>
        </div>

        {{-- Context Header --}}
        <div class="px-4 pt-3 pb-2 border-b border-surface-container-highest flex items-center justify-between">
            <div>
                <x-ui.badge color="primary" :pill="true" :dot="false" class="mb-0.5 uppercase tracking-wider">
                    Ý kiến đóng góp
                </x-ui.badge>
                <h2 class="text-base font-bold text-on-surface">Đánh giá chặng học</h2>
            </div>
            <div class="text-right">
                <span class="text-[10px] text-on-surface-variant/70 font-medium block">Học sinh</span>
                <span class="text-xs font-bold text-on-surface">{{ $student?->name ?? '—' }}</span>
            </div>
        </div>

        {{-- Main Body Content --}}
        <main class="flex-1 p-4 space-y-4 overflow-y-auto">

            {{-- ======================================================== --}}
            {{-- VIEW 1: FORM NHẬP / CHỈNH SỬA (Lần đầu & Đã gửi sửa tiếp) --}}
            {{-- ======================================================== --}}
            <div x-show="viewState === 'form-new' || viewState === 'form-updated'" class="space-y-4">

                {{-- Success Banner (When submitted or simulated updated) --}}
                @if(session('success') || session('feedback_success'))
                    <x-ui.alert type="success" title="Đã ghi nhận feedback thành công">
                        Bạn có thể điều chỉnh và bấm "Cập nhật" bất cứ lúc nào trong thời gian đợt thu thập còn mở.
                    </x-ui.alert>
                @endif

                <x-ui.alert type="info" title="Bạn đã gửi đánh giá trước đó"
                            x-show="viewState === 'form-updated' && !{{ session('feedback_success') ? 'true' : 'false' }}">
                    Bạn có thể thay đổi số sao hoặc nội dung góp ý bên dưới rồi bấm "Cập nhật feedback".
                </x-ui.alert>

                {{-- 1. Tên Chặng Đang Mở Thu Thập (Read-only, R-02) --}}
                <div class="bg-surface-container-low border border-surface-container-highest rounded-2xl p-3.5 shadow-2xs">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider">Chặng học đang mở thu thập</span>
                        <x-ui.badge color="success" :pill="true">
                            Đang mở thu thập
                        </x-ui.badge>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-xl bg-primary-container/10 flex items-center justify-center text-primary font-black text-sm shrink-0">
                                C2
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-on-surface leading-tight">{{ $stageName }}</h3>
                                <p class="text-[11px] text-on-surface-variant mt-0.5">Lớp: <span class="font-bold text-on-surface">{{ $className }}</span></p>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-on-surface-variant/70 text-[18px]" title="Cố định theo chặng đang mở của lớp">lock</span>
                    </div>
                </div>

                {{-- FORM CHÍNH --}}
                <form action="{{ route('portal.student.feedback.store') }}" method="POST" @submit="validateForm($event)" class="space-y-4">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                    <input type="hidden" name="stage_name" value="{{ $stageName }}">
                    <input type="hidden" name="muc_do_hai_long" :value="rating">

                    {{-- 1. Chọn Mức Hài Lòng 1-5 Sao (R-04) --}}
                    <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-4 shadow-2xs">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-on-surface flex items-center gap-1">
                                <span>1. Mức độ hài lòng chung</span>
                                <span class="text-[10px] font-normal text-on-surface-variant/70">(Tùy chọn)</span>
                            </label>
                            <span class="text-xs font-bold text-primary" x-text="ratingLabels[rating]"></span>
                        </div>

                        {{-- 5 Stars Rating Bar --}}
                        <div class="flex items-center justify-between py-1 px-1">
                            <template x-for="star in [1, 2, 3, 4, 5]" :key="star">
                                <button type="button"
                                        @click="setRating(star)"
                                        class="p-2 rounded-xl hover:bg-primary-container/10 focus:outline-none flex flex-col items-center gap-1 transition active:scale-95 group">
                                    <span class="material-symbols-outlined text-3xl transition-transform group-hover:scale-110"
                                          :class="star <= rating ? 'text-warning/70' : 'text-on-surface-variant/70'"
                                          :style="star <= rating ? 'font-variation-settings: \'FILL\' 1;' : ''">
                                        star
                                    </span>
                                    <span class="text-[10px] font-mono font-bold"
                                          :class="star <= rating ? 'text-warning' : 'text-on-surface-variant/70'"
                                          x-text="star"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- 2. Lĩnh Vực Cần Góp Ý (3 Checkboxes - R-04) --}}
                    <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-4 shadow-2xs">
                        <label class="text-xs font-bold text-on-surface flex items-center justify-between mb-2.5">
                            <span>2. Lĩnh vực cần góp ý</span>
                            <span class="text-[10px] font-normal text-on-surface-variant/70">(Tùy chọn)</span>
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            {{-- Checkbox 1: fb_hoc_thuat --}}
                            <label class="cursor-pointer flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all"
                                   :class="fbHocThuat ? 'border-primary-container bg-primary-container/10 shadow-2xs' : 'border-surface-container-highest hover:border-outline-variant'">
                                <input type="checkbox" name="fb_hoc_thuat" value="1" x-model="fbHocThuat" @change="showValidationError = false" class="rounded border-outline-variant text-primary focus:ring-primary-container mb-1">
                                <span class="text-xs font-semibold text-on-surface-variant select-none">Học thuật</span>
                            </label>

                            {{-- Checkbox 2: fb_giao_vien --}}
                            <label class="cursor-pointer flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all"
                                   :class="fbGiaoVien ? 'border-primary-container bg-primary-container/10 shadow-2xs' : 'border-surface-container-highest hover:border-outline-variant'">
                                <input type="checkbox" name="fb_giao_vien" value="1" x-model="fbGiaoVien" @change="showValidationError = false" class="rounded border-outline-variant text-primary focus:ring-primary-container mb-1">
                                <span class="text-xs font-semibold text-on-surface-variant select-none">Giáo viên</span>
                            </label>

                            {{-- Checkbox 3: fb_khac --}}
                            <label class="cursor-pointer flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all"
                                   :class="fbKhac ? 'border-primary-container bg-primary-container/10 shadow-2xs' : 'border-surface-container-highest hover:border-outline-variant'">
                                <input type="checkbox" name="fb_khac" value="1" x-model="fbKhac" @change="showValidationError = false" class="rounded border-outline-variant text-primary focus:ring-primary-container mb-1">
                                <span class="text-xs font-semibold text-on-surface-variant select-none">Khác</span>
                            </label>
                        </div>
                    </div>

                    {{-- 3. Textarea Nội Dung Feedback Chi Tiết (R-04) --}}
                    <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-4 shadow-2xs">
                        <div class="flex items-center justify-between mb-2">
                            <label for="feedback-content" class="text-xs font-bold text-on-surface">
                                3. Nội dung feedback chi tiết
                            </label>
                            <span class="text-[10px] text-on-surface-variant/70">(Tùy chọn)</span>
                        </div>
                        <x-ui.textarea id="feedback-content"
                                       name="noi_dung_feedback"
                                       rows="4"
                                       x-model="noiDung"
                                       x-on:input="showValidationError = false"
                                       placeholder="Chia sẻ cảm nhận của phụ huynh/học sinh về giáo trình, phương pháp giảng dạy hoặc điểm cần hỗ trợ thêm..."
                                       class="resize-none" />
                        <p class="text-[10px] text-on-surface-variant/70 mt-1">Ý kiến chân thực giúp trung tâm nâng cao chất lượng dạy học.</p>
                    </div>

                    {{-- Thông Báo Lỗi Validation: Khi cả 3 mục đều trống (R-04) --}}
                    <x-ui.alert type="warning" x-show="showValidationError" x-cloak>
                        Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.
                    </x-ui.alert>

                    {{-- Nút Gửi / Cập nhật feedback --}}
                    <div>
                        <x-ui.button type="submit" icon="send" class="w-full">
                            <span x-text="viewState === 'form-updated' ? 'Cập nhật feedback' : 'Gửi feedback'"></span>
                        </x-ui.button>
                        <p class="text-center text-[10px] text-on-surface-variant/70 mt-2">
                            Sau khi gửi, bạn vẫn có thể chỉnh sửa lại trong thời gian đợt thu thập còn mở.
                        </p>
                    </div>
                </form>

                @if($hasSaved)
                    <div x-show="viewState === 'form-updated'" class="pt-1">
                        <form action="{{ route('portal.student.feedback.destroy', ['id' => $lastFeedback->id]) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa bản feedback này không?');">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger-text" icon="delete_sweep" class="w-full">
                                <span>Xóa phản hồi đã lưu & nhập lại</span>
                            </x-ui.button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- ======================================================== --}}
            {{-- VIEW 2: READ-ONLY KHI ĐỢT THU THẬP ĐÃ ĐÓNG (AC-R06b) --}}
            {{-- ======================================================== --}}
            <div x-show="viewState === 'state-closed'" x-cloak class="space-y-4">
                {{-- Banner cảnh báo Đợt thu thập đã đóng --}}
                <div class="bg-surface-container border border-outline-variant rounded-2xl p-3.5 flex items-start gap-3">
                    <div class="w-6 h-6 rounded-full bg-outline text-white flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-[14px]">lock</span>
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-on-surface">Đợt thu thập feedback đã đóng</h4>
                        <p class="text-xs text-on-surface-variant mt-0.5 leading-relaxed">
                            Trung tâm đã kết thúc đợt thu thập ý kiến cho chặng này. Dưới đây là nội dung bạn đã gửi (chỉ xem, không thể chỉnh sửa thêm).
                        </p>
                    </div>
                </div>

                {{-- Tên chặng đã đóng --}}
                <div class="bg-surface-container-low border border-surface-container-highest rounded-2xl p-4 shadow-2xs">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider">Chặng học đã hoàn thành</span>
                        <x-ui.badge color="neutral" :pill="true">
                            Đã đóng
                        </x-ui.badge>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-surface-container-high flex items-center justify-center text-on-surface-variant font-bold text-sm shrink-0">
                            C1
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-on-surface leading-tight">Chặng 1: Nền tảng Ngữ pháp & Từ vựng</h3>
                            <p class="text-[11px] text-on-surface-variant mt-0.5">Lớp: {{ $className }}</p>
                        </div>
                    </div>
                </div>

                {{-- Read-only Card Nội dung đã nộp --}}
                <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-4 space-y-3.5 shadow-2xs">
                    {{-- Mức độ hài lòng --}}
                    <div class="border-b border-surface-container-highest pb-3">
                        <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider block mb-1">Mức độ hài lòng đã gửi</span>
                        <div class="flex items-center gap-2">
                            <div class="flex text-warning/70">
                                @for($star = 1; $star <= 5; $star++)
                                    <span class="material-symbols-outlined text-[20px] {{ $star <= ($savedRating > 0 ? $savedRating : 4) ? 'text-warning/70' : 'text-surface-container-highest' }}" {!! $star <= ($savedRating > 0 ? $savedRating : 4) ? 'style="font-variation-settings: \'FILL\' 1;"' : '' !!}>star</span>
                                @endfor
                            </div>
                            <span class="text-xs font-bold text-on-surface">{{ $savedRating > 0 ? $savedRating : 4 }} / 5</span>
                            <span class="text-[11px] text-on-surface-variant font-medium">({{ ['Chưa chọn', 'Rất không hài lòng', 'Không hài lòng', 'Bình thường', 'Hài lòng', 'Rất hài lòng'][$savedRating > 0 ? $savedRating : 4] ?? 'Hài lòng' }})</span>
                        </div>
                    </div>

                    {{-- Lĩnh vực góp ý --}}
                    <div class="border-b border-surface-container-highest pb-3">
                        <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider block mb-1.5">Lĩnh vực đã chọn</span>
                        <div class="flex flex-wrap gap-2">
                            @if($savedHocThuat || (!$savedHocThuat && !$savedGiaoVien && !$savedKhac))
                                <x-ui.badge color="secondary" :dot="false">
                                    ✓ Học thuật
                                </x-ui.badge>
                            @endif
                            @if($savedGiaoVien || (!$savedHocThuat && !$savedGiaoVien && !$savedKhac))
                                <x-ui.badge color="secondary" :dot="false">
                                    ✓ Giáo viên
                                </x-ui.badge>
                            @endif
                            @if($savedKhac)
                                <x-ui.badge color="secondary" :dot="false">
                                    ✓ Khác
                                </x-ui.badge>
                            @endif
                        </div>
                    </div>

                    {{-- Nội dung chi tiết --}}
                    <div>
                        <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider block mb-1">Nội dung đã gửi</span>
                        <p class="text-xs text-on-surface bg-surface-container-low rounded-xl p-3 border border-surface-container-highest leading-relaxed font-normal">
                            {{ !empty($savedContent) ? $savedContent : 'Giáo viên giảng dạy nhiệt tình, bài tập ngữ pháp sát thực tế. Tuy nhiên phần bài tập nghe về nhà đôi khi file audio hơi nhanh với sức học của con, mong thầy cô có thêm phiên bản phát âm chậm.' }}
                        </p>
                    </div>
                </div>

                <div class="text-center py-2 text-xs text-on-surface-variant/70">
                    Cảm ơn bạn đã đóng góp ý kiến xây dựng chất lượng đào tạo.
                </div>
            </div>

            {{-- ======================================================== --}}
            {{-- VIEW 3: TRẠNG THÁI RỖNG (Chưa có đợt thu thập nào mở - R-02) --}}
            {{-- ======================================================== --}}
            <div x-show="viewState === 'state-empty'" x-cloak class="flex flex-col items-center justify-center py-10 px-4 text-center">
                <div class="w-16 h-16 rounded-full bg-primary-container/10 border border-primary-container/30 flex items-center justify-center text-primary mb-4 shadow-inner">
                    <span class="material-symbols-outlined text-3xl">chat_bubble_outline</span>
                </div>
                <h3 class="text-sm font-bold text-on-surface mb-1">Hiện chưa có đợt thu thập nào đang mở</h3>
                <p class="text-xs text-on-surface-variant max-w-[280px] leading-relaxed mb-6">
                    Giáo viên và Ban Học vụ sẽ mở cổng tiếp nhận đánh giá khi hoàn thành từng chặng của khóa học.
                </p>
                <div class="bg-surface-container-low border border-surface-container-highest rounded-2xl p-3.5 text-left w-full shadow-2xs">
                    <div class="flex items-center gap-1.5 text-xs font-bold text-on-surface mb-1">
                        <span class="material-symbols-outlined text-primary text-[16px]">info</span>
                        <span>Lưu ý từ Trung tâm</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant leading-relaxed">
                        Nếu phụ huynh hoặc học sinh cần phản ánh khẩn cấp về việc học tập, vui lòng liên hệ trực tiếp với Cố vấn Học tập (CM) qua mục Thông báo hoặc hotline trung tâm.
                    </p>
                </div>
            </div>

        </main>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'survey', 'student' => $student])
    </div>
</x-app-layout>
