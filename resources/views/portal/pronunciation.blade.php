<x-app-layout>
    <x-ui.page-header title="Luyện phát âm" icon="mic" :back="route('portal.student.homework', ['studentId' => $student?->id])">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="assignment" :href="route('portal.student.homework', ['studentId' => $student?->id])">Xem bài tập viết</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    

    {{-- Mobile Frame for Pronunciation --}}
    <div class="max-w-[430px] mx-auto bg-surface-container-low min-h-[844px] shadow-2xl rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-24 my-4"
         x-data="{
            selectedUnit: 'Unit 1: Greetings - Bài 2',
            isRecording: true,
            timerSeconds: 14,
            timerText: '00:14',
            intervalId: null,
            audioPlaying: false,
            toastVisible: false,
            startRecording() {
                this.isRecording = true;
                this.intervalId = setInterval(() => {
                    this.timerSeconds++;
                    let m = String(Math.floor(this.timerSeconds / 60)).padStart(2, '0');
                    let s = String(this.timerSeconds % 60).padStart(2, '0');
                    this.timerText = `${m}:${s}`;
                }, 1000);
            },
            toggleRecording() {
                if (this.isRecording) {
                    this.isRecording = false;
                    clearInterval(this.intervalId);
                } else {
                    this.startRecording();
                }
            }
         }"
         x-init="
            intervalId = setInterval(() => {
                if (isRecording) {
                    timerSeconds++;
                    let m = String(Math.floor(timerSeconds / 60)).padStart(2, '0');
                    let s = String(timerSeconds % 60).padStart(2, '0');
                    timerText = `${m}:${s}`;
                }
            }, 1000);
         ">

        {{-- Top Header Partial --}}
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Luyện phát âm',
            'showBack' => true,
            'backUrl' => route('portal.student.homework', ['studentId' => $student?->id])
        ])

        {{-- Subtab Switcher --}}
        <div class="flex items-center border-b border-surface-container-highest bg-surface-container-lowest px-3 pt-2">
            <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-on-surface-variant hover:text-on-surface font-semibold text-xs transition">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Nộp bài tập</span>
            </a>
            <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                <span class="material-symbols-outlined text-[16px]">mic</span>
                <span>Luyện phát âm</span>
            </a>
        </div>

        {{-- Main Content --}}
        <main class="w-full p-4 space-y-4 flex-1 overflow-y-auto">

            {{-- Section 1: Audio Mẫu từ Giáo trình --}}
            <section class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 space-y-3 shadow-2xs">
                <div>
                    <h2 class="text-sm font-bold text-on-surface">Audio Mẫu từ Giáo trình</h2>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">Chọn một bài để nghe và luyện tập theo giọng chuẩn bản xứ.</p>
                </div>

                <div class="space-y-2">
                    {{-- Audio Item 1 --}}
                    <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-surface-container-low transition border border-transparent hover:border-surface-container-highest cursor-pointer"
                         @click="selectedUnit = 'Unit 1: Greetings - Bài 1'; isRecording = false; timerSeconds = 0; timerText = '00:00'">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-primary-container/10 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-on-surface">Unit 1: Greetings - Bài 1</p>
                                <p class="text-[10px] text-on-surface-variant/70">00:45</p>
                            </div>
                        </div>
                        <x-ui.button variant="ghost" size="sm" class="text-primary">
                            Chọn
                        </x-ui.button>
                    </div>

                    {{-- Audio Item 2 (Active state simulation) --}}
                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-primary-container/10 border border-primary-container/40 shadow-2xs"
                         :class="{ 'bg-primary-container/10 border-primary-container/40': selectedUnit === 'Unit 1: Greetings - Bài 2' }">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-primary-container flex items-center justify-center text-white shadow-xs">
                                <span class="material-symbols-outlined text-[20px]">pause</span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-primary">Unit 1: Greetings - Bài 2</p>
                                <p class="text-[10px] text-on-surface-variant">01:12 • Đang chọn</p>
                            </div>
                        </div>

                        {{-- Waveform inside active audio item --}}
                        <div class="flex items-center gap-0.5 h-4 mr-1">
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-2"></div>
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-4" style="animation-delay: 0.2s"></div>
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-3" style="animation-delay: 0.4s"></div>
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-2" style="animation-delay: 0.1s"></div>
                        </div>
                    </div>

                    {{-- Audio Item 3 --}}
                    <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-surface-container-low transition border border-transparent hover:border-surface-container-highest cursor-pointer"
                         @click="selectedUnit = 'Unit 2: Family - Bài 1'; isRecording = false; timerSeconds = 0; timerText = '00:00'">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-primary-container/10 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-on-surface">Unit 2: Family - Bài 1</p>
                                <p class="text-[10px] text-on-surface-variant/70">00:58</p>
                            </div>
                        </div>
                        <x-ui.button variant="ghost" size="sm" class="text-primary">
                            Chọn
                        </x-ui.button>
                    </div>
                </div>
            </section>

            {{-- Section 2: Khối Ghi Âm (Active Recording State) --}}
            <section class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-5 flex flex-col items-center justify-center text-center space-y-4 relative overflow-hidden shadow-2xs">
                <div class="absolute inset-0 pointer-events-none opacity-5 bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-primary-container via-transparent to-transparent"></div>

                <div class="relative z-10 w-full space-y-3">
                    <p class="text-xs text-on-surface-variant">
                        Đang luyện tập: <span class="font-bold text-primary" x-text="selectedUnit"></span>
                    </p>

                    {{-- Timer Display --}}
                    <div class="font-mono text-3xl font-bold text-on-surface tracking-wider" x-text="timerText">00:14</div>

                    {{-- Animated Waveform Display (visible when recording) --}}
                    <div class="flex items-center justify-center gap-1.5 h-12 w-full max-w-[200px] mx-auto py-1" x-show="isRecording">
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-4" style="animation-delay: 0.1s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-8" style="animation-delay: 0.3s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-11" style="animation-delay: 0.2s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-6" style="animation-delay: 0.5s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-10" style="animation-delay: 0.4s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-7" style="animation-delay: 0.2s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-9" style="animation-delay: 0.6s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-5" style="animation-delay: 0.3s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-12" style="animation-delay: 0.1s"></div>
                        <div class="w-1 bg-primary-container rounded-full animate-pulse h-6" style="animation-delay: 0.4s"></div>
                    </div>

                    {{-- Record Button --}}
                    <div class="flex justify-center py-2">
                        <button type="button"
                                @click="toggleRecording()"
                                class="relative w-20 h-20 bg-primary-container hover:bg-primary rounded-full flex items-center justify-center text-white shadow-xl transition-all transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-primary-container/30">
                            {{-- Pulsing ring effect when recording --}}
                            <div x-show="isRecording" class="absolute inset-0 rounded-full border-2 border-primary-container animate-ping opacity-75"></div>
                            <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1;" x-text="isRecording ? 'stop' : 'mic'"></span>
                        </button>
                    </div>
                    <p class="text-[11px] text-on-surface-variant" x-text="isRecording ? 'Chạm để dừng ghi âm' : 'Chạm micro để tiếp tục ghi âm'"></p>

                    {{-- Real Form Submission Action --}}
                    <form action="{{ route('portal.student.pronunciation.store') }}" method="POST" class="pt-2 w-full flex justify-center">
                        @csrf
                        <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                        <input type="hidden" name="unit_title" :value="selectedUnit">
                        <input type="hidden" name="duration" :value="timerText">

                        <x-ui.button type="submit" icon="send" class="w-full max-w-[240px]">
                            <span>Nộp bài ghi âm</span>
                        </x-ui.button>
                    </form>
                </div>
            </section>

            {{-- Section 3: Lịch sử luyện tập --}}
            <section class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 space-y-3 shadow-2xs">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-on-surface">Lịch sử của bạn</h2>
                    <span class="text-[10px] text-on-surface-variant/70 font-medium">Giáo viên chấm điểm</span>
                </div>

                <div class="space-y-2">
                    @forelse($history as $rec)
                        <div class="flex items-center justify-between p-3 rounded-xl bg-surface-container-low hover:bg-surface-container transition border border-surface-container-highest/80">
                            <div>
                                <p class="text-xs font-bold text-on-surface">{{ $rec->title }}</p>
                                <div class="flex items-center gap-2 text-[11px] text-on-surface-variant mt-1">
                                    <span class="flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                        {{ $rec->data['submitted_at'] ?? $rec->created_at->format('d/m/Y H:i') }}
                                    </span>
                                    <span>•</span>
                                    <span class="flex items-center gap-0.5 font-mono">
                                        <span class="material-symbols-outlined text-[13px]">timer</span>
                                        {{ $rec->data['duration'] ?? '—' }}
                                    </span>
                                </div>
                                @if ($rec->status === 'reviewed' && ! empty($rec->data['score']))
                                    <x-ui.badge color="success" :pill="true" class="mt-1">
                                        Giáo viên chấm: {{ $rec->data['score'] }}
                                    </x-ui.badge>
                                    @if (! empty($rec->data['feedback']))
                                        <p class="mt-1 text-[11px] text-on-surface-variant italic">"{{ $rec->data['feedback'] }}"</p>
                                    @endif
                                @else
                                    <x-ui.badge color="warning" :pill="true" class="mt-1">
                                        Chờ giáo viên chấm
                                    </x-ui.badge>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5">
                                <x-ui.button variant="ghost" size="sm" icon="play_arrow" title="Nghe lại" aria-label="Nghe lại" />
                                <form action="{{ route('portal.student.pronunciation.destroy', $rec->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa bản ghi âm này?');">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" size="sm" icon="delete" title="Xóa bản ghi" aria-label="Xóa bản ghi" />
                                </form>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="mic" title="Chưa có bài ghi âm" description="Chọn bài, thu âm và bấm Nộp bài — giáo viên sẽ chấm và phản hồi." />
                    @endforelse
                </div>
            </section>
        </main>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'learning', 'student' => $student])
    </div>
</x-app-layout>
