<x-app-layout>
    {{-- Trên điện thoại: thanh điều hướng đáy là điều hướng chính, ẩn tiêu đề/nút quay lại và dải tab. --}}
    <x-ui.page-header class="hidden md:flex" title="Luyện phát âm" icon="mic" :back="route('portal.student.homework', ['studentId' => $student?->id])">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="assignment" :href="route('portal.student.homework', ['studentId' => $student?->id])">Xem bài tập viết</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    <x-ui.workspace-tabs class="hidden md:block" />

    

    {{-- Mobile Frame for Pronunciation: ghi âm thật bằng micro (MediaRecorder), file gửi kèm form nộp bài. --}}
    <script>
        function pronunciationRecorder() {
            return {
                selectedUnit: '',
                recorder: null,
                chunks: [],
                isRecording: false,
                hasRecording: false,
                previewUrl: null,
                timerSeconds: 0,
                timerText: '00:00',
                intervalId: null,
                error: '',
                pickUnit(title) {
                    this.selectedUnit = title;
                },
                async toggleRecording() {
                    if (this.isRecording) {
                        this.recorder?.stop();
                        return;
                    }
                    this.error = '';
                    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
                        this.error = 'Trình duyệt không hỗ trợ ghi âm. Hãy dùng Chrome, Edge hoặc Safari bản mới.';
                        return;
                    }
                    let stream;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    } catch (e) {
                        this.error = 'Chưa được cấp quyền dùng micro. Hãy cho phép micro rồi thử lại.';
                        return;
                    }
                    this.chunks = [];
                    this.recorder = new MediaRecorder(stream);
                    this.recorder.ondataavailable = (event) => { if (event.data.size > 0) this.chunks.push(event.data); };
                    this.recorder.onstop = () => {
                        stream.getTracks().forEach((track) => track.stop());
                        clearInterval(this.intervalId);
                        this.isRecording = false;
                        this.attachRecording();
                    };
                    this.timerSeconds = 0;
                    this.updateTimer();
                    this.recorder.start();
                    this.isRecording = true;
                    this.intervalId = setInterval(() => { this.timerSeconds++; this.updateTimer(); }, 1000);
                },
                updateTimer() {
                    const m = String(Math.floor(this.timerSeconds / 60)).padStart(2, '0');
                    const s = String(this.timerSeconds % 60).padStart(2, '0');
                    this.timerText = `${m}:${s}`;
                },
                attachRecording() {
                    const type = this.recorder?.mimeType || 'audio/webm';
                    const blob = new Blob(this.chunks, { type });
                    const extension = type.includes('mp4') ? 'm4a' : (type.includes('ogg') ? 'ogg' : 'webm');
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([blob], `ghi-am.${extension}`, { type }));
                    this.$refs.audioFile.files = transfer.files;
                    if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = URL.createObjectURL(blob);
                    this.hasRecording = blob.size > 0;
                },
            };
        }
    </script>
    <div class="max-w-[430px] md:max-w-4xl mx-auto bg-surface-container-low min-h-[844px] md:min-h-0 shadow-2xl md:shadow-sm rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-24 md:pb-6 my-4"
         x-data="pronunciationRecorder()">

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

            {{-- Section 1: Bài nghe mẫu = file nghe giáo viên đính kèm bài tập của lớp --}}
            <section class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 space-y-3 shadow-2xs">
                <div>
                    <h2 class="text-sm font-bold text-on-surface">Bài nghe của lớp</h2>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">File nghe giáo viên gửi kèm bài tập. Nghe, chọn bài rồi thu âm theo.</p>
                </div>

                <div class="space-y-2">
                    @forelse ($practiceItems as $item)
                        <div class="rounded-xl border p-2.5 transition"
                             :class="selectedUnit === @js($item['title']) ? 'bg-primary-container/10 border-primary-container/40' : 'border-surface-container-highest'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-semibold text-on-surface">{{ $item['title'] }}</p>
                                    <p class="text-[10px] text-on-surface-variant/70">{{ $item['class_name'] }}</p>
                                </div>
                                <x-ui.button variant="ghost" size="sm" class="text-primary" x-on:click="pickUnit({{ \Illuminate\Support\Js::from($item['title']) }})">Chọn</x-ui.button>
                            </div>
                            <audio controls preload="none" class="mt-2 h-8 w-full" src="{{ $item['audio_url'] }}"></audio>
                        </div>
                    @empty
                        <x-ui.empty-state icon="headphones" title="Lớp chưa có file nghe" description="Khi giáo viên gửi bài tập kèm file nghe, bài sẽ hiện ở đây. Bạn vẫn có thể tự đặt tên bài và thu âm." />
                    @endforelse
                </div>
            </section>

            {{-- Section 2: Ghi âm (micro thật) + nộp bài --}}
            <section class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-5 flex flex-col items-center justify-center text-center space-y-4 relative overflow-hidden shadow-2xs">
                <form action="{{ route('portal.student.pronunciation.store') }}" method="POST" enctype="multipart/form-data" class="relative z-10 w-full space-y-3 text-left">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student?->id }}">
                    <input type="hidden" name="duration" :value="timerText">
                    <input type="file" name="audio_file" x-ref="audioFile" class="hidden" accept="audio/*">

                    <x-ui.input name="unit_title" label="Bài đang luyện" x-model="selectedUnit" required maxlength="255" placeholder="Ví dụ: Unit 1 - Greetings" />

                    {{-- Đồng hồ --}}
                    <div class="text-center font-mono text-3xl font-bold text-on-surface tracking-wider" x-text="timerText">00:00</div>

                    {{-- Sóng âm khi đang ghi --}}
                    <div class="flex items-center justify-center gap-1.5 h-12 w-full max-w-[200px] mx-auto py-1" x-show="isRecording" x-cloak>
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

                    {{-- Nút ghi âm --}}
                    <div class="flex justify-center py-2">
                        <button type="button" @click="toggleRecording()" :aria-label="isRecording ? 'Dừng ghi âm' : 'Bắt đầu ghi âm'"
                                class="relative w-20 h-20 bg-primary-container hover:bg-primary rounded-full flex items-center justify-center text-white shadow-xl transition-all transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-primary-container/30">
                            <div x-show="isRecording" x-cloak class="absolute inset-0 rounded-full border-2 border-primary-container animate-ping opacity-75"></div>
                            <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1;" x-text="isRecording ? 'stop' : 'mic'">mic</span>
                        </button>
                    </div>
                    <p class="text-center text-[11px] text-on-surface-variant"
                       x-text="isRecording ? 'Chạm để dừng ghi âm' : (hasRecording ? 'Nghe lại bên dưới, hoặc chạm micro để ghi lại' : 'Chạm micro để bắt đầu ghi âm')"></p>
                    <p class="text-[11px] font-semibold text-error" x-show="error" x-text="error" x-cloak></p>
                    @error('audio_file')<p class="text-[11px] font-semibold text-error">{{ $message }}</p>@enderror

                    <audio controls class="h-8 w-full" x-show="hasRecording && !isRecording" x-cloak :src="previewUrl"></audio>

                    <div class="flex justify-center pt-2">
                        <x-ui.button type="submit" icon="send" class="w-full max-w-[240px]" x-bind:disabled="!hasRecording || isRecording">
                            <span>Nộp bài ghi âm</span>
                        </x-ui.button>
                    </div>
                </form>
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
                                @if (! empty($rec->data['audio_path']))
                                    <a href="{{ $rec->data['audio_path'] }}" target="_blank" rel="noopener" title="Nghe lại" aria-label="Nghe lại"
                                       class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-primary hover:bg-primary-container/10">
                                        <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                                    </a>
                                @endif
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
        @include('portal.partials.bottom-nav', ['activeTab' => 'pronunciation', 'student' => $student])
    </div>
</x-app-layout>
