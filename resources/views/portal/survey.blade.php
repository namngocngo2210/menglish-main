<x-app-layout>
    <x-ui.page-header title="Khảo sát chất lượng" icon="contact_support" :back="route('portal.student.home', ['studentId' => $student?->id])">
        <x-slot:actions>
            <x-ui.button icon="rate_review" :href="route('portal.student.feedback', ['studentId' => $student?->id])">Đánh giá chặng học</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    

    {{-- Mobile Frame for Survey --}}
    <div class="max-w-[430px] mx-auto bg-surface-container-lowest min-h-[844px] shadow-2xl rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-24 my-4"
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
        <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
            <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Khảo sát định kỳ</span>
            </a>
            <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-on-surface-variant hover:text-on-surface font-semibold text-xs transition">
                <span class="material-symbols-outlined text-[16px]">rate_review</span>
                <span>Feedback chặng học</span>
            </a>
        </div>

        {{-- Main Content --}}
        <main class="w-full p-4 space-y-4 flex-1 overflow-y-auto">
            {{-- Header Context --}}
            <div class="pt-1">
                <h2 class="text-xl font-bold text-on-surface">Khảo sát &amp; Đánh giá</h2>
                <p class="text-xs text-on-surface-variant mt-0.5">Hãy chia sẻ ý kiến của bạn để chúng tôi nâng cao chất lượng dịch vụ đào tạo.</p>
            </div>

            {{-- Section 1: Khảo sát đang mở --}}
            <section class="space-y-2">
                <h3 class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Khảo sát đang mở</h3>

                @foreach($surveys as $idx => $srv)
                    <button type="button"
                            @click="select('{{ $srv['title'] }}')"
                            class="w-full text-left bg-surface-container-lowest rounded-xl p-3.5 transition-all relative overflow-hidden border shadow-2xs hover:bg-primary-container/10"
                            :class="selectedSurvey === '{{ $srv['title'] }}' ? 'border-primary-container ring-1 ring-primary-container/20' : 'border-surface-container-highest'">
                        <div x-show="selectedSurvey === '{{ $srv['title'] }}'" class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container rounded-l-xl"></div>
                        <div class="flex justify-between items-start gap-2">
                            <div class="pr-2">
                                <h4 class="text-xs font-bold text-on-surface mb-1 leading-snug">{{ $srv['title'] }}</h4>
                                <p class="text-[11px] flex items-center gap-1 {{ !empty($srv['is_urgent']) ? 'text-error font-semibold' : 'text-on-surface-variant' }}">
                                    <span class="material-symbols-outlined text-[13px]">event</span>
                                    <span>{{ $srv['status_text'] }}</span>
                                </p>
                            </div>
                            <span class="material-symbols-outlined text-lg shrink-0"
                                  :class="selectedSurvey === '{{ $srv['title'] }}' ? 'text-primary' : 'text-on-surface-variant/70'"
                                  x-text="selectedSurvey === '{{ $srv['title'] }}' ? 'radio_button_checked' : 'radio_button_unchecked'">
                            </span>
                        </div>
                    </button>
                @endforeach
            </section>

            {{-- Section 2: Form Phản Hồi --}}
            <section class="bg-surface-container-low/80 border border-surface-container-highest rounded-2xl p-4 shadow-2xs space-y-3">
                <div class="border-b border-surface-container-highest pb-2.5">
                    <h3 class="text-xs font-bold text-on-surface">Nội dung phản hồi</h3>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">
                        Đang phản hồi cho: <span class="font-bold text-primary" x-text="selectedSurvey"></span>
                    </p>
                </div>

                <form action="{{ route('portal.student.survey.store') }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                    <input type="hidden" name="survey_title" :value="selectedSurvey">

                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1.5">
                            MỨC ĐỘ HÀI LÒNG CHUNG
                        </label>
                        <div class="flex items-center gap-2 bg-surface-container-lowest p-2.5 rounded-xl border border-surface-container-highest">
                            @for($s = 1; $s <= 5; $s++)
                                <label class="flex-1 flex flex-col items-center gap-1 cursor-pointer">
                                    <input type="radio" name="rating" value="{{ $s }}" {{ $s === 5 ? 'checked' : '' }} class="border-outline-variant text-primary focus:ring-primary-container">
                                    <span class="text-[10px] font-bold text-on-surface-variant">{{ $s }} ★</span>
                                </label>
                            @endfor
                        </div>
                    </div>

                    <x-ui.textarea id="feedback-textarea" name="feedback" label="Ý KIẾN CỦA BẠN" rows="4" required
                                   placeholder="Vui lòng nhập chi tiết phản hồi của bạn tại đây..." class="resize-none" />

                    <x-ui.button type="submit" icon="send" class="w-full">
                        <span>Gửi phản hồi khảo sát</span>
                    </x-ui.button>
                </form>
            </section>

            {{-- History of Submissions with Delete CRUD --}}
            @if(isset($pastSurveys) && $pastSurveys->isNotEmpty())
                <section class="space-y-2 pt-2">
                    <h3 class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Khảo sát đã gửi</h3>
                    @foreach($pastSurveys as $ps)
                        <div class="p-3 bg-surface-container-lowest border border-surface-container-highest rounded-xl text-xs space-y-1.5 shadow-2xs">
                            <div class="flex justify-between items-center">
                                <strong class="text-on-surface">{{ $ps->title }}</strong>
                                <div class="flex items-center gap-1.5">
                                    <x-ui.badge color="success" :pill="true" :dot="false">
                                        {{ $ps->data['rating'] ?? 5 }} ★
                                    </x-ui.badge>
                                    <form action="{{ route('portal.student.survey.destroy', $ps->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa bài khảo sát này?');">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="delete" title="Xóa khảo sát" aria-label="Xóa khảo sát" />
                                    </form>
                                </div>
                            </div>
                            <p class="text-on-surface-variant italic text-[11px]">"{{ $ps->data['feedback'] ?? '' }}"</p>
                            <span class="text-[10px] text-on-surface-variant/70 block font-mono">{{ $ps->data['submitted_at'] ?? $ps->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                    @endforeach
                </section>
            @endif
        </main>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'survey', 'student' => $student])
    </div>
</x-app-layout>
