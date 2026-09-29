<x-app-layout>
    {{-- Trên điện thoại: thanh điều hướng đáy là điều hướng chính, ẩn tiêu đề/nút quay lại và dải tab. --}}
    <x-ui.page-header class="hidden md:flex" title="Khảo sát chất lượng" icon="contact_support" :back="route('portal.student.home', ['studentId' => $student?->id])">
        <x-slot:actions>
            <x-ui.button icon="rate_review" :href="route('portal.student.feedback', ['studentId' => $student?->id])">Đánh giá chặng học</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    <x-ui.workspace-tabs class="hidden md:block" />

    

    {{-- Mobile Frame for Survey --}}
    <div class="max-w-[430px] md:max-w-4xl mx-auto bg-surface-container-lowest min-h-[844px] md:min-h-0 shadow-2xl md:shadow-sm rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-24 md:pb-6 my-4"
         x-data="{
            selectedSurvey: @js((string) old('survey_title', '')),
            select(title) {
                this.selectedSurvey = title;
                $dispatch('open-modal', 'survey-response');
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
        <div class="w-full p-4 space-y-4 flex-1 overflow-y-auto">
            {{-- Header Context --}}
            <div class="pt-1">
                <h2 class="text-xl font-bold text-on-surface">Khảo sát &amp; Đánh giá</h2>
                <p class="text-xs text-on-surface-variant mt-0.5">Hãy chia sẻ ý kiến của bạn để chúng tôi nâng cao chất lượng dịch vụ đào tạo.</p>
            </div>

            {{-- Section 1: Khảo sát đang mở --}}
            <section class="space-y-2">
                <h3 class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Khảo sát đang mở</h3>
                @if (! empty($surveys))<p class="text-xs text-on-surface-variant">Bấm vào một khảo sát để gửi phản hồi.</p>@endif

                @forelse($surveys as $srv)
                    <button type="button" @click="select(@js($srv['title']))"
                            class="w-full rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 text-left shadow-2xs transition-all hover:bg-primary-container/10">
                        <div class="flex items-start justify-between gap-2">
                            <div class="pr-2">
                                <h4 class="mb-1 text-xs font-bold leading-snug text-on-surface">{{ $srv['title'] }}</h4>
                                <p class="flex items-center gap-1 text-xs {{ !empty($srv['is_urgent']) ? 'text-error font-semibold' : 'text-on-surface-variant' }}">
                                    <span class="material-symbols-outlined text-[13px]">event</span>
                                    <span>{{ $srv['status_text'] }}</span>
                                </p>
                            </div>
                            <span class="material-symbols-outlined shrink-0 text-lg text-on-surface-subtle" aria-hidden="true">chevron_right</span>
                        </div>
                    </button>
                @empty
                    <p class="rounded-xl border border-dashed border-surface-container-highest p-3.5 text-center text-xs text-on-surface-variant">Hiện chưa có khảo sát nào đang mở.</p>
                @endforelse
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
                                    <form action="{{ route('portal.student.survey.destroy', $ps->id) }}" method="POST" data-confirm="Xóa bài khảo sát này?" data-confirm-label="Xóa" data-confirm-danger>
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="delete" title="Xóa khảo sát" aria-label="Xóa khảo sát" />
                                    </form>
                                </div>
                            </div>
                            <p class="text-on-surface-variant italic text-xs">"{{ $ps->data['feedback'] ?? '' }}"</p>
                            <span class="text-xs text-on-surface-subtle block font-mono">{{ $ps->data['submitted_at'] ?? $ps->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

        {{-- Phản hồi khảo sát: bấm một khảo sát ở danh sách → modal (không mở form ngay trong trang). --}}
        <x-ui.modal name="survey-response" title="Phản hồi khảo sát" max-width="md" :show="$errors->hasAny(['survey_title', 'rating', 'feedback'])">
            <p class="mb-3 text-xs text-on-surface-variant">Đang phản hồi cho: <span class="font-bold text-primary" x-text="selectedSurvey"></span></p>
            <form id="survey-response-form" action="{{ route('portal.student.survey.store') }}" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                <input type="hidden" name="survey_title" :value="selectedSurvey">

                <fieldset>
                    <legend class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Mức độ hài lòng chung</legend>
                    <div class="flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2.5">
                        @for($s = 1; $s <= 5; $s++)
                            <label class="flex flex-1 cursor-pointer flex-col items-center gap-1">
                                <input type="radio" name="rating" value="{{ $s }}" {{ $s === 5 ? 'checked' : '' }} class="border-outline-variant text-primary focus:ring-primary-container">
                                <span class="text-xs font-bold text-on-surface-variant">{{ $s }} ★</span>
                            </label>
                        @endfor
                    </div>
                </fieldset>

                <x-ui.textarea id="feedback-textarea" name="feedback" label="Ý KIẾN CỦA BẠN" rows="4" required
                               placeholder="Vui lòng nhập chi tiết phản hồi của bạn tại đây..." class="resize-none" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'survey-response')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="survey-response-form" icon="send">Gửi phản hồi khảo sát</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'survey', 'student' => $student])
    </div>
</x-app-layout>
