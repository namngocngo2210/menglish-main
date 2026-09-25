<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-rose-600">mic</span>
                        Flow 4 — Bước 4: Luyện phát âm & Thu âm giọng nói AI
                    </h1>
                    <p class="text-xs text-gray-500">Học sinh nghe file audio mẫu từ giáo trình và thu âm giọng nói AI trực tiếp để nộp bài.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">assignment</span>
                    <span>Xem bài tập viết</span>
                </a>
            </div>
        </div>
    </x-slot>

    

    <!-- Mobile Frame for Pronunciation (Matches 04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am) -->
    <div class="max-w-[430px] mx-auto bg-gray-50 min-h-[844px] shadow-2xl rounded-3xl border border-gray-200 overflow-hidden flex flex-col relative pb-24 my-4"
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

        <!-- Top Header Partial -->
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Luyện phát âm',
            'showBack' => true,
            'backUrl' => route('portal.student.homework', ['studentId' => $student?->id])
        ])

        <!-- Subtab Switcher -->
        <div class="flex items-center border-b border-gray-200 bg-white px-3 pt-2">
            <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-gray-500 hover:text-gray-900 font-semibold text-xs transition">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Nộp bài tập</span>
            </a>
            <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}"
               class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                <span class="material-symbols-outlined text-[16px]">mic</span>
                <span>Luyện phát âm AI</span>
            </a>
        </div>

        <!-- Main Content -->
        <main class="w-full p-4 space-y-4 flex-1 overflow-y-auto">

            <!-- Section 1: Audio Mẫu từ Giáo trình -->
            <section class="bg-white rounded-2xl border border-gray-200 p-4 space-y-3 shadow-2xs">
                <div>
                    <h2 class="text-sm font-bold text-gray-900">Audio Mẫu từ Giáo trình</h2>
                    <p class="text-[11px] text-gray-500 mt-0.5">Chọn một bài để nghe và luyện tập theo giọng chuẩn bản xứ.</p>
                </div>

                <div class="space-y-2">
                    <!-- Audio Item 1 -->
                    <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-gray-50 transition border border-transparent hover:border-gray-200 cursor-pointer"
                         @click="selectedUnit = 'Unit 1: Greetings - Bài 1'; isRecording = false; timerSeconds = 0; timerText = '00:00'">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-orange-100 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-900">Unit 1: Greetings - Bài 1</p>
                                <p class="text-[10px] text-gray-400">00:45</p>
                            </div>
                        </div>
                        <button type="button" class="text-primary font-bold text-xs px-3 py-1 rounded-full hover:bg-orange-50 transition">
                            Chọn
                        </button>
                    </div>

                    <!-- Audio Item 2 (Active state simulation) -->
                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-orange-50/80 border border-primary-container/40 shadow-2xs"
                         :class="{ 'bg-orange-50/80 border-primary-container/40': selectedUnit === 'Unit 1: Greetings - Bài 2' }">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-primary-container flex items-center justify-center text-white shadow-xs">
                                <span class="material-symbols-outlined text-[20px]">pause</span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-primary">Unit 1: Greetings - Bài 2</p>
                                <p class="text-[10px] text-gray-500">01:12 • Đang chọn</p>
                            </div>
                        </div>

                        <!-- Waveform inside active audio item -->
                        <div class="flex items-center gap-0.5 h-4 mr-1">
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-2"></div>
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-4" style="animation-delay: 0.2s"></div>
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-3" style="animation-delay: 0.4s"></div>
                            <div class="w-0.5 bg-primary-container rounded-full animate-pulse h-2" style="animation-delay: 0.1s"></div>
                        </div>
                    </div>

                    <!-- Audio Item 3 -->
                    <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-gray-50 transition border border-transparent hover:border-gray-200 cursor-pointer"
                         @click="selectedUnit = 'Unit 2: Family - Bài 1'; isRecording = false; timerSeconds = 0; timerText = '00:00'">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-orange-100 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-900">Unit 2: Family - Bài 1</p>
                                <p class="text-[10px] text-gray-400">00:58</p>
                            </div>
                        </div>
                        <button type="button" class="text-primary font-bold text-xs px-3 py-1 rounded-full hover:bg-orange-50 transition">
                            Chọn
                        </button>
                    </div>
                </div>
            </section>

            <!-- Section 2: Khối Ghi Âm (Active Recording State) -->
            <section class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col items-center justify-center text-center space-y-4 relative overflow-hidden shadow-2xs">
                <div class="absolute inset-0 pointer-events-none opacity-5 bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-primary-container via-transparent to-transparent"></div>

                <div class="relative z-10 w-full space-y-3">
                    <p class="text-xs text-gray-700">
                        Đang luyện tập: <span class="font-bold text-primary" x-text="selectedUnit"></span>
                    </p>

                    <!-- Timer Display -->
                    <div class="font-mono text-3xl font-bold text-gray-900 tracking-wider" x-text="timerText">00:14</div>

                    <!-- Animated Waveform Display (visible when recording) -->
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

                    <!-- Record Button -->
                    <div class="flex justify-center py-2">
                        <button type="button"
                                @click="toggleRecording()"
                                class="relative w-20 h-20 bg-primary-container hover:bg-primary rounded-full flex items-center justify-center text-white shadow-xl transition-all transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-orange-200">
                            <!-- Pulsing ring effect when recording -->
                            <div x-show="isRecording" class="absolute inset-0 rounded-full border-2 border-primary-container animate-ping opacity-75"></div>
                            <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1;" x-text="isRecording ? 'stop' : 'mic'"></span>
                        </button>
                    </div>
                    <p class="text-[11px] text-gray-500" x-text="isRecording ? 'Chạm để dừng ghi âm' : 'Chạm micro để tiếp tục ghi âm'"></p>

                    <!-- Real Form Submission Action -->
                    <form action="{{ route('portal.student.pronunciation.store') }}" method="POST" class="pt-2 w-full flex justify-center">
                        @csrf
                        <input type="hidden" name="student_id" value="{{ $student?->id ?? 1 }}">
                        <input type="hidden" name="unit_title" :value="selectedUnit">
                        <input type="hidden" name="duration" :value="timerText">

                        <button type="submit"
                                class="w-full max-w-[240px] bg-primary-container hover:bg-primary text-white font-bold text-xs py-3 px-6 rounded-xl transition shadow-md flex items-center justify-center gap-2 active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">send</span>
                            <span>Nộp bài ghi âm AI</span>
                        </button>
                    </form>
                </div>
            </section>

            <!-- Section 3: Lịch sử luyện tập -->
            <section class="bg-white rounded-2xl border border-gray-200 p-4 space-y-3 shadow-2xs">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-gray-900">Lịch sử của bạn</h2>
                    <span class="text-[10px] text-gray-400 font-medium">Chấm tự động bởi AI</span>
                </div>

                <div class="space-y-2">
                    @forelse($history as $rec)
                        <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50 hover:bg-gray-100 transition border border-gray-200/80">
                            <div>
                                <p class="text-xs font-bold text-gray-900">{{ $rec->title }}</p>
                                <div class="flex items-center gap-2 text-[11px] text-gray-500 mt-1">
                                    <span class="flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                        {{ $rec->data['submitted_at'] ?? $rec->created_at->format('d/m/Y H:i') }}
                                    </span>
                                    <span>•</span>
                                    <span class="flex items-center gap-0.5 font-mono">
                                        <span class="material-symbols-outlined text-[13px]">timer</span>
                                        {{ $rec->data['duration'] ?? '00:42' }}
                                    </span>
                                </div>
                                <span class="inline-block mt-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                    Điểm AI: {{ $rec->data['score'] ?? '95/100' }}
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="button" class="w-8 h-8 rounded-full bg-orange-100 hover:bg-primary-container hover:text-white transition flex items-center justify-center text-primary shadow-2xs" title="Nghe lại">
                                    <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                                </button>
                                <form action="{{ route('portal.student.pronunciation.destroy', $rec->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa bản ghi âm này?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-rose-100 text-gray-500 hover:text-rose-600 transition flex items-center justify-center shadow-2xs" title="Xóa bản ghi">
                                        <span class="material-symbols-outlined text-[16px]">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <!-- Default mock history matching prototype -->
                        <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50 hover:bg-gray-100 transition border border-gray-200/80">
                            <div>
                                <p class="text-xs font-bold text-gray-900">Bản ghi Unit 1 - Bài 1</p>
                                <div class="flex items-center gap-2 text-[11px] text-gray-500 mt-1">
                                    <span class="flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                        Hôm nay, 10:30 AM
                                    </span>
                                    <span>•</span>
                                    <span class="flex items-center gap-0.5 font-mono">
                                        <span class="material-symbols-outlined text-[13px]">timer</span>
                                        00:42
                                    </span>
                                </div>
                                <span class="inline-block mt-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                    Điểm AI: 94/100 (Phát âm chuẩn)
                                </span>
                            </div>
                            <button type="button" class="w-9 h-9 rounded-full bg-orange-100 hover:bg-primary-container hover:text-white transition flex items-center justify-center text-primary shadow-2xs">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </button>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50 hover:bg-gray-100 transition border border-gray-200/80">
                            <div>
                                <p class="text-xs font-bold text-gray-900">Bản ghi Unit 1 - Bài 1 (Lần 1)</p>
                                <div class="flex items-center gap-2 text-[11px] text-gray-500 mt-1">
                                    <span class="flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                        Hôm qua, 15:45 PM
                                    </span>
                                    <span>•</span>
                                    <span class="flex items-center gap-0.5 font-mono">
                                        <span class="material-symbols-outlined text-[13px]">timer</span>
                                        00:38
                                    </span>
                                </div>
                                <span class="inline-block mt-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                    Điểm AI: 91/100 (Cần chú ý âm đuôi)
                                </span>
                            </div>
                            <button type="button" class="w-9 h-9 rounded-full bg-orange-100 hover:bg-primary-container hover:text-white transition flex items-center justify-center text-primary shadow-2xs">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </button>
                        </div>
                    @endforelse
                </div>
            </section>
        </main>

        <!-- Bottom Navigation Bar Component -->
        @include('portal.partials.bottom-nav', ['activeTab' => 'learning', 'student' => $student])
    </div>
</x-app-layout>
