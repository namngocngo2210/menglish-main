<!DOCTYPE html>
<html lang="vi" class="h-full bg-surface-container-low">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $test->title }} — MEnglish Online Placement Test</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet" />
    @include('layouts.partials.assets')
</head>
<body class="font-sans antialiased text-on-surface bg-surface-container-low min-h-screen">
    {{-- Header --}}
    <header class="bg-surface-container-lowest border-b border-surface-container-highest sticky top-0 z-40 shadow-xs">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-container text-white flex items-center justify-center font-black text-lg shadow-sm">
                    M
                </div>
                <div>
                    <div class="font-black text-on-surface text-base leading-tight tracking-tight">MEnglish Academy</div>
                    <div class="text-[11px] text-on-surface-variant font-medium">Hệ Thống Đánh Giá Trình Độ &amp; Xếp Lớp Chuẩn CEFR</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div id="exam-violation-badge" class="hidden px-3 py-1.5 rounded-xl bg-error/10 text-error border border-error/30 text-xs font-bold flex items-center gap-1.5" title="Số lần rời khỏi bài thi">
                    <span class="material-symbols-outlined text-[16px]">shield</span>
                    <span>Rời bài: <span id="exam-violation-count">0</span>/{{ \App\Models\PlacementTestSubmission::MAX_VIOLATIONS }}</span>
                </div>
                <div class="px-3.5 py-1.5 rounded-xl bg-primary-container/10 text-primary border border-primary-container/30 text-xs font-mono font-bold flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-primary">timer</span>
                    <span>Thời gian: {{ $test->duration_minutes }} phút</span>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        {{-- Test Intro Banner --}}
        <div class="bg-gradient-to-r from-primary-container to-warning rounded-3xl p-6 md:p-8 text-white shadow-xl space-y-3">
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 bg-white/20 text-white font-bold text-xs rounded-full backdrop-blur-xs uppercase tracking-wider font-mono">{{ $test->code }}</span>
                <span class="px-3 py-1 bg-white/20 text-white font-semibold text-xs rounded-full backdrop-blur-xs">{{ $test->target_level }}</span>
            </div>
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight">{{ $test->title }}</h1>
            <p class="text-xs md:text-sm text-white/90 leading-relaxed max-w-2xl">
                Bài kiểm tra gồm {{ $test->questions_count }} câu hỏi đánh giá 4 kỹ năng (Nghe, Đọc - Ngữ pháp, Viết và Nói). Phần Nghe, Đọc - Ngữ pháp được chấm tự động; phần Viết và Nói do <strong>Học vụ MEnglish</strong> chấm và gửi kết quả xếp lớp cho bạn.
            </p>
        </div>

        {{-- Form Submission --}}
        <form id="exam-form" action="{{ route('portal.test.submit', $test->code) }}" method="POST" class="space-y-6">
            @csrf
            @if ($errors->any())
                <x-ui.alert type="error" title="Vui lòng kiểm tra lại thông tin:">
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif
            @if ($leadToken)
                <input type="hidden" name="lead_token" value="{{ $leadToken }}">
            @endif
            <input type="hidden" name="violation_count" id="exam-violation-input" value="0">
            <input type="hidden" name="violation_log" id="exam-violation-log" value="[]">
            <input type="hidden" name="auto_submitted" id="exam-auto-submitted" value="0">

            {{-- 1. Candidate Info --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2 pb-2 border-b border-surface-container-highest">
                    <span class="material-symbols-outlined text-primary">person</span>
                    1. Thông tin thí sinh dự thi
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                    <x-ui.input name="candidate_name" label="Họ và tên thí sinh" :value="$lead?->name" required placeholder="Họ và tên" class="font-bold" />
                    <x-ui.input name="candidate_phone" label="Số điện thoại liên hệ" :value="$lead?->phone" required placeholder="VD: 0912 345 678" class="font-mono font-bold" />
                    <x-ui.input type="email" name="candidate_email" label="Email nhận bảng điểm" :value="$lead?->email" placeholder="hocvien@gmail.com" />
                </div>
            </div>


            {{-- Quy chế & nút bắt đầu: phần câu hỏi chỉ hiện sau khi vào chế độ thi --}}
            <div id="exam-start-card" class="bg-surface-container-lowest rounded-2xl border border-primary-container/40 shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2 pb-2 border-b border-surface-container-highest">
                    <span class="material-symbols-outlined text-primary">shield_lock</span>
                    Quy chế làm bài
                </h2>
                <ul class="list-disc list-inside space-y-1.5 text-xs text-on-surface-variant leading-relaxed">
                    <li>Khi bấm <strong>Bắt đầu làm bài</strong>, bài thi mở ở chế độ <strong>toàn màn hình</strong>. Không thoát toàn màn hình, không chuyển sang tab, trang web hay ứng dụng khác cho tới khi nộp bài.</li>
                    <li>Bài thi không cho sao chép, dán, bấm chuột phải hay tải lại trang.</li>
                    <li>Mỗi lần rời khỏi bài thi đều được ghi lại và gửi kèm bài làm cho người chấm. Rời bài tới lần thứ <strong>{{ \App\Models\PlacementTestSubmission::MAX_VIOLATIONS }}</strong>, bài sẽ <strong>tự động nộp</strong> với các câu đã làm.</li>
                </ul>
                <div class="flex items-center justify-between gap-3 flex-wrap pt-2">
                    <span class="text-xs text-on-surface-variant">Điền họ tên và số điện thoại ở trên trước khi bắt đầu.</span>
                    <x-ui.button type="button" id="exam-start-btn" icon="play_circle">Bắt đầu làm bài</x-ui.button>
                </div>
            </div>

            <div id="exam-body" class="hidden space-y-6">
            @php
                $questions = is_array($test->questions) ? $test->questions : [];
                $listeningQuestions = array_filter($questions, fn($q) => ($q['skill'] ?? '') === 'listening');
                $readingGrammarQuestions = array_filter($questions, fn($q) => in_array($q['skill'] ?? '', ['reading', 'grammar']));
                $writingQuestions = array_filter($questions, fn($q) => ($q['skill'] ?? '') === 'writing');
                $speakingQuestions = array_filter($questions, fn($q) => ($q['skill'] ?? '') === 'speaking');
            @endphp

            {{-- 2. Listening Section --}}
            @if (count($listeningQuestions) > 0)
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-5">
                    <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                        <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-secondary">headphones</span>
                            2. Section 1: Listening Comprehension ({{ count($listeningQuestions) }} câu)
                        </h2>
                        <x-ui.badge color="secondary" pill>Kỹ năng Nghe</x-ui.badge>
                    </div>

                    <div class="space-y-6">
                        @foreach ($listeningQuestions as $idx => $q)
                            <div class="p-4 rounded-xl border border-surface-container-highest/80 bg-surface-container-low/50 space-y-3">
                                <div class="font-bold text-on-surface text-xs flex items-start gap-2">
                                    <span class="px-2 py-0.5 rounded-md bg-secondary/10 text-on-secondary-fixed font-mono text-[11px] shrink-0">Câu {{ $idx + 1 }}</span>
                                    <span>{{ $q['title'] ?? '' }}</span>
                                </div>

                                @if (!empty($q['audio_url']))
                                    @php
                                        $audioSrc = str_starts_with($q['audio_url'], 'http') || str_starts_with($q['audio_url'], '/') ? $q['audio_url'] : ('/' . $q['audio_url']);
                                    @endphp
                                    <div class="p-3.5 bg-secondary/10 border border-secondary/30 rounded-xl space-y-2 shadow-2xs">
                                        <div class="flex items-center justify-between text-[11px] font-bold text-on-secondary-fixed">
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-base text-secondary animate-pulse">volume_up</span>
                                                <span>Băng nghe Audio (Listening Track)</span>
                                            </div>
                                            <span class="text-[10px] text-secondary font-semibold italic">Bấm nút Play ▶ để nghe</span>
                                        </div>
                                        <audio controls class="w-full h-9 rounded-lg" preload="metadata" src="{{ $audioSrc }}">
                                            <source src="{{ $audioSrc }}" type="audio/mpeg">
                                            Trình duyệt của bạn không hỗ trợ phát audio.
                                        </audio>
                                    </div>
                                @endif

                                @if (!empty($q['image_url']))
                                    <div class="my-2">
                                        <img src="{{ $q['image_url'] }}" alt="Question illustration" class="max-h-64 rounded-xl border border-surface-container-highest object-contain mx-auto bg-surface-container-lowest p-1">
                                    </div>
                                @endif

                                @if (($q['type'] ?? '') === 'fill_blank')
                                    <div class="pt-1">
                                        <x-ui.input name="answers[{{ $q['id'] ?? $idx }}]" placeholder="Nhập từ cần điền..." class="font-bold" />
                                    </div>
                                @elseif (!empty($q['options']))
                                    <div class="grid grid-cols-1 {{ count($q['options']) > 2 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2' }} gap-2.5 text-xs text-on-surface-variant">
                                        @foreach ($q['options'] as $opt)
                                            <label class="flex flex-col p-2.5 rounded-xl bg-surface-container-lowest border border-surface-container-highest hover:border-primary-container cursor-pointer transition">
                                                <div class="flex items-center gap-2 mb-1.5">
                                                    <input type="radio" name="answers[{{ $q['id'] ?? $idx }}]" value="{{ $opt['key'] }}" class="text-primary focus:ring-primary-container">
                                                    <span class="font-bold text-on-surface">{{ $opt['key'] }}.</span>
                                                    <span>{{ $opt['text'] }}</span>
                                                </div>
                                                @if (!empty($opt['image_url']))
                                                    <img src="{{ $opt['image_url'] }}" alt="{{ $opt['key'] }}" class="max-h-32 rounded-lg border border-surface-container-highest object-contain bg-surface-container-low p-1 mx-auto">
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 3. Reading & Grammar Section --}}
            @if (count($readingGrammarQuestions) > 0)
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-5">
                    <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                        <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary">menu_book</span>
                            3. Section 2: Reading &amp; Grammar ({{ count($readingGrammarQuestions) }} câu)
                        </h2>
                        <x-ui.badge color="success" pill>Đọc hiểu &amp; Ngữ pháp</x-ui.badge>
                    </div>

                    <div class="space-y-6">
                        @foreach ($readingGrammarQuestions as $idx => $q)
                            <div class="p-4 rounded-xl border border-surface-container-highest/80 bg-surface-container-low/50 space-y-3">
                                <div class="font-bold text-on-surface text-xs flex items-start gap-2">
                                    <span class="px-2 py-0.5 rounded-md bg-tertiary/10 text-on-tertiary-container font-mono text-[11px] shrink-0">Câu {{ $idx + 1 }}</span>
                                    <span>{{ $q['title'] ?? '' }}</span>
                                </div>

                                @if (!empty($q['passage']))
                                    <div class="p-3.5 bg-tertiary/5 border border-tertiary/20 rounded-xl text-xs leading-relaxed text-on-surface font-medium italic">
                                        "{{ $q['passage'] }}"
                                    </div>
                                @endif

                                @if (!empty($q['image_url']))
                                    <div class="my-2">
                                        <img src="{{ $q['image_url'] }}" alt="Illustration" class="max-h-64 rounded-xl border border-surface-container-highest object-contain mx-auto bg-surface-container-lowest p-1">
                                    </div>
                                @endif

                                @if (($q['type'] ?? '') === 'fill_blank')
                                    <div class="pt-1">
                                        <x-ui.input name="answers[{{ $q['id'] ?? $idx }}]" placeholder="Nhập câu trả lời..." class="font-bold" />
                                    </div>
                                @elseif (!empty($q['options']))
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-on-surface-variant">
                                        @foreach ($q['options'] as $opt)
                                            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-surface-container-lowest border border-surface-container-highest hover:border-tertiary cursor-pointer transition">
                                                <input type="radio" name="answers[{{ $q['id'] ?? $idx }}]" value="{{ $opt['key'] }}" class="text-primary focus:ring-primary-container">
                                                <span class="font-bold text-on-surface">{{ $opt['key'] }}.</span>
                                                <span>{{ $opt['text'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 4. Writing Section --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                    <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-600">edit_note</span>
                        4. Section 3: Writing Task (Viết tự luận)
                    </h2>
                    <span class="text-xs text-purple-600 font-bold bg-purple-50 px-2.5 py-0.5 rounded-full">Kỹ năng Viết</span>
                </div>

                @php
                    $writingQ = !empty($writingQuestions) ? reset($writingQuestions) : null;
                    $writingPrompt = $writingQ['title'] ?? 'Hãy viết một đoạn văn ngắn giới thiệu về bản thân, sở thích hoặc một chuyến đi đáng nhớ của bạn.';
                @endphp

                <div class="p-3.5 bg-purple-50 text-purple-900 rounded-xl text-xs space-y-1.5 border border-purple-200">
                    <div class="font-bold flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">help</span>
                        <span>Đề bài Writing:</span>
                    </div>
                    <p class="leading-relaxed">{{ $writingPrompt }}</p>
                </div>
                <x-ui.textarea name="writing_content" rows="5" placeholder="Nhập bài viết của bạn tại đây..." class="leading-relaxed" />
            </div>

            {{-- 5. Speaking Section --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                    <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-error">mic</span>
                        5. Section 4: Speaking &amp; Fluency (Nói &amp; Phản xạ)
                    </h2>
                    <x-ui.badge color="error" pill>Kỹ năng Nói</x-ui.badge>
                </div>

                @php
                    $speakingQ = !empty($speakingQuestions) ? reset($speakingQuestions) : null;
                @endphp

                @if ($speakingQ && !empty($speakingQ['cue_points']))
                    <div class="p-3.5 bg-error/5 text-on-error-container rounded-xl text-xs space-y-1.5 border border-error/30">
                        <div class="font-bold flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">record_voice_over</span>
                            <span>Gợi ý chủ đề Speaking: {{ $speakingQ['title'] ?? '' }}</span>
                        </div>
                        <div class="text-[11px] text-on-error-container whitespace-pre-line leading-relaxed font-mono">
                            {{ $speakingQ['cue_points'] }}
                        </div>
                    </div>
                @endif

                <div class="space-y-2">
                    <div class="text-xs font-bold text-on-surface-variant">Tự đánh giá mức độ phản xạ nói tiếng Anh hiện tại:</div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                        <label class="p-3.5 rounded-xl border border-surface-container-highest hover:border-primary-container cursor-pointer block space-y-1 bg-surface-container-lowest transition">
                            <input type="radio" name="speaking_self_rate" value="beginner" class="text-primary">
                            <div class="font-bold text-on-surface">Mới bắt đầu / Mất gốc (A1)</div>
                            <p class="text-[11px] text-on-surface-variant">Chưa tự tin phát âm, hay ấp úng khi giao tiếp câu cơ bản.</p>
                        </label>
                        <label class="p-3.5 rounded-xl border border-surface-container-highest hover:border-primary-container cursor-pointer block space-y-1 bg-surface-container-lowest transition">
                            <input type="radio" name="speaking_self_rate" value="intermediate" checked class="text-primary">
                            <div class="font-bold text-on-surface">Trung bình (A2 - B1)</div>
                            <p class="text-[11px] text-on-surface-variant">Giao tiếp được câu hoàn chỉnh hàng ngày, phản xạ tương đối ổn.</p>
                        </label>
                        <label class="p-3.5 rounded-xl border border-surface-container-highest hover:border-primary-container cursor-pointer block space-y-1 bg-surface-container-lowest transition">
                            <input type="radio" name="speaking_self_rate" value="advanced" class="text-primary">
                            <div class="font-bold text-on-surface">Nâng cao (B2 - C1)</div>
                            <p class="text-[11px] text-on-surface-variant">Tự tin thuyết trình, tranh luận học thuật và phản xạ nhanh.</p>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Submit Button --}}
            <div class="p-6 bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm flex items-center justify-between">
                <div class="text-xs text-on-surface-variant">
                    Vui lòng kiểm tra lại câu trả lời trước khi gửi bài thi.
                </div>
                <x-ui.button type="submit" icon="check_circle" class="shadow-lg hover:shadow-xl">Nộp Bài Thi &amp; Xem Báo Cáo Điểm Tự Động</x-ui.button>
            </div>
            </div>
        </form>
    </main>
    {{-- Cảnh báo khi thí sinh rời bài thi --}}
    <div id="exam-warning" class="hidden fixed inset-0 z-[100] bg-inverse-surface/90 flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-surface-container-lowest rounded-2xl shadow-xl p-6 space-y-4 text-center">
            <span class="material-symbols-outlined text-5xl text-error">warning</span>
            <h2 class="text-lg font-extrabold text-on-surface">Bạn vừa rời khỏi bài thi</h2>
            <p class="text-sm text-on-surface-variant leading-relaxed">
                Đây là lần thứ <strong id="exam-warning-count">1</strong>/{{ \App\Models\PlacementTestSubmission::MAX_VIOLATIONS }}. Lần rời bài đã được ghi lại cho người chấm.
                Tới lần thứ {{ \App\Models\PlacementTestSubmission::MAX_VIOLATIONS }}, bài sẽ tự động nộp.
            </p>
            <x-ui.button type="button" id="exam-resume-btn" icon="fullscreen" class="w-full justify-center">Quay lại làm bài</x-ui.button>
        </div>
    </div>

    <div id="exam-submitting" class="hidden fixed inset-0 z-[110] bg-inverse-surface/90 flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-surface-container-lowest rounded-2xl shadow-xl p-6 space-y-3 text-center">
            <span class="material-symbols-outlined text-5xl text-error">block</span>
            <h2 class="text-lg font-extrabold text-on-surface">Bài thi đã tự động nộp</h2>
            <p class="text-sm text-on-surface-variant">Bạn đã rời khỏi bài thi quá số lần cho phép. Đang gửi bài làm...</p>
        </div>
    </div>

    <script>
        (() => {
            const MAX_VIOLATIONS = {{ \App\Models\PlacementTestSubmission::MAX_VIOLATIONS }};
            const form = document.getElementById('exam-form');
            const body = document.getElementById('exam-body');
            const startCard = document.getElementById('exam-start-card');
            const warning = document.getElementById('exam-warning');
            const root = document.documentElement;
            const canFullscreen = !!(root.requestFullscreen || root.webkitRequestFullscreen);
            const log = [];
            let started = false;
            let submitting = false;
            let lastViolationAt = 0;

            const isFullscreen = () => !!(document.fullscreenElement || document.webkitFullscreenElement);
            const enterFullscreen = () => {
                if (!canFullscreen || isFullscreen()) return;
                const req = root.requestFullscreen || root.webkitRequestFullscreen;
                try { const p = req.call(root); if (p && p.catch) p.catch(() => {}); } catch (e) {}
            };

            const autoSubmit = () => {
                submitting = true;
                document.getElementById('exam-auto-submitted').value = '1';
                warning.classList.add('hidden');
                document.getElementById('exam-submitting').classList.remove('hidden');
                form.submit();
            };

            const recordViolation = (type) => {
                if (!started || submitting) return;
                // Chuyển tab thường bắn cùng lúc blur + visibilitychange + thoát toàn màn hình: tính là 1 lần.
                const now = Date.now();
                if (now - lastViolationAt < 1500) return;
                lastViolationAt = now;

                log.push({ type, at: new Date().toISOString() });
                document.getElementById('exam-violation-input').value = String(log.length);
                document.getElementById('exam-violation-log').value = JSON.stringify(log);
                document.getElementById('exam-violation-count').textContent = String(log.length);

                if (log.length >= MAX_VIOLATIONS) {
                    autoSubmit();
                    return;
                }
                document.getElementById('exam-warning-count').textContent = String(log.length);
                warning.classList.remove('hidden');
            };

            document.getElementById('exam-start-btn').addEventListener('click', () => {
                for (const name of ['candidate_name', 'candidate_phone']) {
                    const input = form.elements[name];
                    if (input && !input.reportValidity()) return;
                }
                enterFullscreen();
                started = true;
                startCard.classList.add('hidden');
                body.classList.remove('hidden');
                document.getElementById('exam-violation-badge').classList.remove('hidden');
                history.pushState({ exam: true }, '', location.href);
                window.scrollTo({ top: 0 });
            });

            document.getElementById('exam-resume-btn').addEventListener('click', () => {
                warning.classList.add('hidden');
                enterFullscreen();
            });

            form.addEventListener('submit', () => { submitting = true; });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') recordViolation('hidden');
            });
            window.addEventListener('blur', () => recordViolation('blur'));
            const onFullscreenChange = () => {
                if (canFullscreen && !isFullscreen()) recordViolation('fullscreen_exit');
            };
            document.addEventListener('fullscreenchange', onFullscreenChange);
            document.addEventListener('webkitfullscreenchange', onFullscreenChange);

            // Không cho quay lại trang trước / tải lại trang khi đang làm bài.
            window.addEventListener('popstate', () => {
                if (started && !submitting) history.pushState({ exam: true }, '', location.href);
            });
            window.addEventListener('beforeunload', (e) => {
                if (started && !submitting) { e.preventDefault(); e.returnValue = ''; }
            });

            // Chặn chuột phải, sao chép / dán, kéo thả.
            for (const evt of ['contextmenu', 'copy', 'cut', 'paste', 'drop', 'dragstart']) {
                document.addEventListener(evt, (e) => { if (started) e.preventDefault(); });
            }
            document.addEventListener('selectstart', (e) => {
                if (started && !e.target.closest?.('input, textarea')) e.preventDefault();
            });

            // Chặn phím tắt phổ biến: DevTools, xem nguồn, lưu, in, tải lại, sao chép / dán.
            document.addEventListener('keydown', (e) => {
                if (!started || submitting) return;
                const key = (e.key || '').toLowerCase();
                const mod = e.ctrlKey || e.metaKey;
                const blocked =
                    key === 'f12' || key === 'f5' ||
                    (mod && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key)) ||
                    (mod && ['u', 's', 'p', 'r', 'c', 'x', 'v', 'o', 'f', 'g'].includes(key)) ||
                    (e.altKey && ['arrowleft', 'arrowright'].includes(key));
                if (blocked) { e.preventDefault(); e.stopPropagation(); }
            }, true);
        })();
    </script>
</body>
</html>
