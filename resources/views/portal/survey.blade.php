<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-emerald-600">contact_support</span>
                        Khảo sát chất lượng
                    </h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-purple-600 text-white text-xs font-semibold hover:bg-purple-700 transition shadow-sm">
                    <span class="material-symbols-outlined text-[18px]">rate_review</span>
                    <span>Đánh giá chặng học</span>
                </a>
            </div>
        </div>
    </x-slot>

    

    {{-- Mobile Frame for Survey --}}
    <div class="max-w-[430px] mx-auto bg-white min-h-[844px] shadow-2xl rounded-3xl border border-gray-200 overflow-hidden flex flex-col relative pb-24 my-4"
         x-data="{
            selectedSurvey: 'Đánh giá chất lượng cơ sở vật chất tháng 10',
            feedbackText: '',
            isSubmitting: false,
            select(title) {
                this.selectedSurvey = title;
                $nextTick(() => {
                    document.getElementById('feedback-textarea')?.focus();
                });
            }
         }">

        {{-- Header Partial --}}
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Khảo sát',
            'showBack' => true,
            'backUrl' => route('portal.student.home', ['studentId' => $student?->id])
        ])

        {{-- Subtab Switcher: Khảo sát chung vs Feedback chặng --}}
        <div class="flex items-center border-b border-gray-200 bg-gray-50 px-3 pt-2">
            <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Khảo sát định kỳ</span>
            </a>
            <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-gray-500 hover:text-gray-900 font-semibold text-xs transition">
                <span class="material-symbols-outlined text-[16px]">rate_review</span>
                <span>Feedback chặng học</span>
            </a>
        </div>

        {{-- Main Content --}}
        <main class="w-full p-4 space-y-4 flex-1 overflow-y-auto">
            {{-- Header Context --}}
            <div class="pt-1">
                <h2 class="text-xl font-bold text-gray-900">Khảo sát &amp; Đánh giá</h2>
                <p class="text-xs text-gray-500 mt-0.5">Hãy chia sẻ ý kiến của bạn để chúng tôi nâng cao chất lượng dịch vụ đào tạo.</p>
            </div>

            {{-- Section 1: Khảo sát đang mở --}}
            <section class="space-y-2">
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Khảo sát đang mở</h3>

                @foreach($surveys as $idx => $srv)
                    <button type="button"
                            @click="select('{{ $srv['title'] }}')"
                            class="w-full text-left bg-white rounded-xl p-3.5 transition-all relative overflow-hidden border shadow-2xs hover:bg-orange-50/20"
                            :class="selectedSurvey === '{{ $srv['title'] }}' ? 'border-primary-container ring-1 ring-primary-container/20' : 'border-gray-200'">
                        <div x-show="selectedSurvey === '{{ $srv['title'] }}'" class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container rounded-l-xl"></div>
                        <div class="flex justify-between items-start gap-2">
                            <div class="pr-2">
                                <h4 class="text-xs font-bold text-gray-900 mb-1 leading-snug">{{ $srv['title'] }}</h4>
                                <p class="text-[11px] flex items-center gap-1 {{ !empty($srv['is_urgent']) ? 'text-rose-600 font-semibold' : 'text-gray-500' }}">
                                    <span class="material-symbols-outlined text-[13px]">event</span>
                                    <span>{{ $srv['status_text'] }}</span>
                                </p>
                            </div>
                            <span class="material-symbols-outlined text-lg shrink-0"
                                  :class="selectedSurvey === '{{ $srv['title'] }}' ? 'text-primary' : 'text-gray-400'"
                                  x-text="selectedSurvey === '{{ $srv['title'] }}' ? 'radio_button_checked' : 'radio_button_unchecked'">
                            </span>
                        </div>
                    </button>
                @endforeach
            </section>

            {{-- Section 2: Form Phản Hồi --}}
            <section class="bg-gray-50/80 border border-gray-200 rounded-2xl p-4 shadow-2xs space-y-3">
                <div class="border-b border-gray-200 pb-2.5">
                    <h3 class="text-xs font-bold text-gray-900">Nội dung phản hồi</h3>
                    <p class="text-[11px] text-gray-500 mt-0.5">
                        Đang phản hồi cho: <span class="font-bold text-primary" x-text="selectedSurvey"></span>
                    </p>
                </div>

                <form action="{{ route('portal.student.survey.store') }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                    <input type="hidden" name="survey_title" :value="selectedSurvey">

                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 uppercase tracking-wider mb-1.5">
                            MỨC ĐỘ HÀI LÒNG CHUNG
                        </label>
                        <div class="flex items-center gap-2 bg-white p-2.5 rounded-xl border border-gray-200">
                            @for($s = 1; $s <= 5; $s++)
                                <label class="flex-1 flex flex-col items-center gap-1 cursor-pointer">
                                    <input type="radio" name="rating" value="{{ $s }}" {{ $s === 5 ? 'checked' : '' }} class="text-primary focus:ring-primary-container">
                                    <span class="text-[10px] font-bold text-gray-600">{{ $s }} ★</span>
                                </label>
                            @endfor
                        </div>
                    </div>

                    <div>
                        <label for="feedback-textarea" class="block text-[11px] font-bold text-gray-700 uppercase tracking-wider mb-1">
                            Ý KIẾN CỦA BẠN <span class="text-red-500">*</span>
                        </label>
                        <textarea id="feedback-textarea"
                                  name="feedback"
                                  rows="4"
                                  required
                                  placeholder="Vui lòng nhập chi tiết phản hồi của bạn tại đây..."
                                  class="w-full bg-white border border-gray-300 rounded-xl p-3 text-xs text-gray-900 focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container resize-none transition-all placeholder:text-gray-400"></textarea>
                    </div>

                    <button type="submit"
                            class="w-full bg-primary-container hover:bg-primary text-white font-bold text-xs py-3 px-4 rounded-xl flex items-center justify-center gap-2 shadow-md transition active:scale-[0.98]">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        <span>Gửi phản hồi khảo sát</span>
                    </button>
                </form>
            </section>

            {{-- History of Submissions with Delete CRUD --}}
            @if(isset($pastSurveys) && $pastSurveys->isNotEmpty())
                <section class="space-y-2 pt-2">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Khảo sát đã gửi</h3>
                    @foreach($pastSurveys as $ps)
                        <div class="p-3 bg-white border border-gray-200 rounded-xl text-xs space-y-1.5 shadow-2xs">
                            <div class="flex justify-between items-center">
                                <strong class="text-gray-900">{{ $ps->title }}</strong>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full font-bold">
                                        {{ $ps->data['rating'] ?? 5 }} ★
                                    </span>
                                    <form action="{{ route('portal.student.survey.destroy', $ps->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa bài khảo sát này?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-gray-400 hover:text-rose-600 transition p-1" title="Xóa khảo sát">
                                            <span class="material-symbols-outlined text-[15px]">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <p class="text-gray-600 italic text-[11px]">"{{ $ps->data['feedback'] ?? '' }}"</p>
                            <span class="text-[10px] text-gray-400 block font-mono">{{ $ps->data['submitted_at'] ?? $ps->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                    @endforeach
                </section>
            @endif
        </main>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'survey', 'student' => $student])
    </div>
</x-app-layout>
