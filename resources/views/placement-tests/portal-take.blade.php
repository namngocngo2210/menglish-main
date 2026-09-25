<!DOCTYPE html>
<html lang="vi" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $test->title }} — MEnglish Online Placement Test</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-slate-900 bg-slate-50 min-h-screen">
    <!-- Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-orange-600 text-white flex items-center justify-center font-black text-lg shadow-sm">
                    M
                </div>
                <div>
                    <div class="font-black text-slate-900 text-base leading-tight tracking-tight">MEnglish Academy</div>
                    <div class="text-[11px] text-slate-500 font-medium">Hệ Thống Đánh Giá Trình Độ &amp; Xếp Lớp Chuẩn CEFR</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="px-3.5 py-1.5 rounded-xl bg-orange-50 text-orange-700 border border-orange-200 text-xs font-mono font-bold flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-orange-600">timer</span>
                    <span>Thời gian: {{ $test->duration_minutes }} phút</span>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <!-- Test Intro Banner -->
        <div class="bg-gradient-to-r from-orange-600 to-amber-500 rounded-3xl p-6 md:p-8 text-white shadow-xl space-y-3">
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 bg-white/20 text-white font-bold text-xs rounded-full backdrop-blur-xs uppercase tracking-wider font-mono">{{ $test->code }}</span>
                <span class="px-3 py-1 bg-white/20 text-white font-semibold text-xs rounded-full backdrop-blur-xs">{{ $test->target_level }}</span>
            </div>
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight">{{ $test->title }}</h1>
            <p class="text-xs md:text-sm text-white/90 leading-relaxed max-w-2xl">
                Bài kiểm tra gồm {{ $test->questions_count }} câu hỏi đánh giá 4 kỹ năng (Nghe, Đọc - Ngữ pháp, Viết và Nói). Phần Nghe, Đọc - Ngữ pháp được chấm tự động; phần Viết và Nói do <strong>Học vụ MEnglish</strong> chấm và gửi kết quả xếp lớp cho bạn.
            </p>
        </div>

        <!-- Form Submission -->
        <form action="{{ route('portal.test.submit', $test->code) }}" method="POST" class="space-y-6">
            @csrf
            @if ($leadToken)
                <input type="hidden" name="lead_token" value="{{ $leadToken }}">
            @endif

            <!-- 1. Candidate Info -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2 pb-2 border-b border-slate-100">
                    <span class="material-symbols-outlined text-orange-600">person</span>
                    1. Thông tin thí sinh dự thi
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Họ và tên thí sinh <span class="text-rose-500">*</span></label>
                        <input type="text" name="candidate_name" value="{{ old('candidate_name', $lead?->name) }}" required placeholder="Nguyễn Văn A" class="w-full text-xs rounded-xl border border-slate-200 p-2.5 font-bold" />
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Số điện thoại liên hệ <span class="text-rose-500">*</span></label>
                        <input type="text" name="candidate_phone" value="{{ old('candidate_phone', $lead?->phone) }}" required placeholder="0912 345 678" class="w-full text-xs rounded-xl border border-slate-200 p-2.5 font-mono font-bold" />
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Email nhận bảng điểm</label>
                        <input type="email" name="candidate_email" value="{{ old('candidate_email', $lead?->email) }}" placeholder="hocvien@gmail.com" class="w-full text-xs rounded-xl border border-slate-200 p-2.5" />
                    </div>
                </div>
            </div>

            @php
                $questions = is_array($test->questions) ? $test->questions : [];
                $listeningQuestions = array_filter($questions, fn($q) => ($q['skill'] ?? '') === 'listening');
                $readingGrammarQuestions = array_filter($questions, fn($q) => in_array($q['skill'] ?? '', ['reading', 'grammar']));
                $writingQuestions = array_filter($questions, fn($q) => ($q['skill'] ?? '') === 'writing');
                $speakingQuestions = array_filter($questions, fn($q) => ($q['skill'] ?? '') === 'speaking');
            @endphp

            <!-- 2. Listening Section -->
            @if (count($listeningQuestions) > 0)
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-indigo-600">headphones</span>
                            2. Section 1: Listening Comprehension ({{ count($listeningQuestions) }} câu)
                        </h2>
                        <span class="text-xs text-indigo-600 font-bold bg-indigo-50 px-2.5 py-0.5 rounded-full">Kỹ năng Nghe</span>
                    </div>

                    <div class="space-y-6">
                        @foreach ($listeningQuestions as $idx => $q)
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-3">
                                <div class="font-bold text-slate-900 text-xs flex items-start gap-2">
                                    <span class="px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-800 font-mono text-[11px] shrink-0">Câu {{ $idx + 1 }}</span>
                                    <span>{{ $q['title'] ?? '' }}</span>
                                </div>

                                @if (!empty($q['audio_url']))
                                    @php
                                        $audioSrc = str_starts_with($q['audio_url'], 'http') || str_starts_with($q['audio_url'], '/') ? $q['audio_url'] : ('/' . $q['audio_url']);
                                    @endphp
                                    <div class="p-3.5 bg-gradient-to-r from-indigo-50/90 to-blue-50/90 border border-indigo-200/80 rounded-xl space-y-2 shadow-2xs">
                                        <div class="flex items-center justify-between text-[11px] font-bold text-indigo-900">
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-base text-indigo-600 animate-pulse">volume_up</span>
                                                <span>Băng nghe Audio (Listening Track)</span>
                                            </div>
                                            <span class="text-[10px] text-indigo-600 font-semibold italic">Bấm nút Play ▶ để nghe</span>
                                        </div>
                                        <audio controls class="w-full h-9 rounded-lg" preload="metadata" src="{{ $audioSrc }}">
                                            <source src="{{ $audioSrc }}" type="audio/mpeg">
                                            Trình duyệt của bạn không hỗ trợ phát audio.
                                        </audio>
                                    </div>
                                @endif

                                @if (!empty($q['image_url']))
                                    <div class="my-2">
                                        <img src="{{ $q['image_url'] }}" alt="Question illustration" class="max-h-64 rounded-xl border border-slate-200 object-contain mx-auto bg-white p-1">
                                    </div>
                                @endif

                                @if (($q['type'] ?? '') === 'fill_blank')
                                    <div class="pt-1">
                                        <input type="text" name="answers[{{ $q['id'] ?? $idx }}]" placeholder="Nhập từ cần điền..." class="w-full text-xs rounded-xl border border-slate-300 p-2.5 font-bold" />
                                    </div>
                                @elseif (!empty($q['options']))
                                    <div class="grid grid-cols-1 {{ count($q['options']) > 2 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2' }} gap-2.5 text-xs text-slate-700">
                                        @foreach ($q['options'] as $opt)
                                            <label class="flex flex-col p-2.5 rounded-xl bg-white border border-slate-200 hover:border-orange-500 cursor-pointer transition">
                                                <div class="flex items-center gap-2 mb-1.5">
                                                    <input type="radio" name="answers[{{ $q['id'] ?? $idx }}]" value="{{ $opt['key'] }}" class="text-orange-600 focus:ring-orange-500">
                                                    <span class="font-bold text-slate-900">{{ $opt['key'] }}.</span>
                                                    <span>{{ $opt['text'] }}</span>
                                                </div>
                                                @if (!empty($opt['image_url']))
                                                    <img src="{{ $opt['image_url'] }}" alt="{{ $opt['key'] }}" class="max-h-32 rounded-lg border border-slate-100 object-contain bg-slate-50 p-1 mx-auto">
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

            <!-- 3. Reading & Grammar Section -->
            @if (count($readingGrammarQuestions) > 0)
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-emerald-600">menu_book</span>
                            3. Section 2: Reading &amp; Grammar ({{ count($readingGrammarQuestions) }} câu)
                        </h2>
                        <span class="text-xs text-emerald-600 font-bold bg-emerald-50 px-2.5 py-0.5 rounded-full">Đọc hiểu &amp; Ngữ pháp</span>
                    </div>

                    <div class="space-y-6">
                        @foreach ($readingGrammarQuestions as $idx => $q)
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-3">
                                <div class="font-bold text-slate-900 text-xs flex items-start gap-2">
                                    <span class="px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-800 font-mono text-[11px] shrink-0">Câu {{ $idx + 1 }}</span>
                                    <span>{{ $q['title'] ?? '' }}</span>
                                </div>

                                @if (!empty($q['passage']))
                                    <div class="p-3.5 bg-emerald-50/60 border border-emerald-100 rounded-xl text-xs leading-relaxed text-slate-800 font-medium italic">
                                        "{{ $q['passage'] }}"
                                    </div>
                                @endif

                                @if (!empty($q['image_url']))
                                    <div class="my-2">
                                        <img src="{{ $q['image_url'] }}" alt="Illustration" class="max-h-64 rounded-xl border border-slate-200 object-contain mx-auto bg-white p-1">
                                    </div>
                                @endif

                                @if (($q['type'] ?? '') === 'fill_blank')
                                    <div class="pt-1">
                                        <input type="text" name="answers[{{ $q['id'] ?? $idx }}]" placeholder="Nhập câu trả lời..." class="w-full text-xs rounded-xl border border-slate-300 p-2.5 font-bold" />
                                    </div>
                                @elseif (!empty($q['options']))
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-700">
                                        @foreach ($q['options'] as $opt)
                                            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-white border border-slate-200 hover:border-emerald-500 cursor-pointer transition">
                                                <input type="radio" name="answers[{{ $q['id'] ?? $idx }}]" value="{{ $opt['key'] }}" class="text-orange-600 focus:ring-orange-500">
                                                <span class="font-bold text-slate-900">{{ $opt['key'] }}.</span>
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

            <!-- 4. Writing Section -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                    <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
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
                <textarea name="writing_content" rows="5" placeholder="Nhập bài viết của bạn tại đây..." class="w-full text-xs rounded-xl border border-slate-200 p-3 leading-relaxed"></textarea>
            </div>

            <!-- 5. Speaking Section -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                    <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-rose-600">mic</span>
                        5. Section 4: Speaking &amp; Fluency (Nói &amp; Phản xạ)
                    </h2>
                    <span class="text-xs text-rose-600 font-bold bg-rose-50 px-2.5 py-0.5 rounded-full">Kỹ năng Nói</span>
                </div>

                @php
                    $speakingQ = !empty($speakingQuestions) ? reset($speakingQuestions) : null;
                @endphp

                @if ($speakingQ && !empty($speakingQ['cue_points']))
                    <div class="p-3.5 bg-rose-50/70 text-rose-950 rounded-xl text-xs space-y-1.5 border border-rose-200">
                        <div class="font-bold flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">record_voice_over</span>
                            <span>Gợi ý chủ đề Speaking: {{ $speakingQ['title'] ?? '' }}</span>
                        </div>
                        <div class="text-[11px] text-rose-800 whitespace-pre-line leading-relaxed font-mono">
                            {{ $speakingQ['cue_points'] }}
                        </div>
                    </div>
                @endif

                <div class="space-y-2">
                    <div class="text-xs font-bold text-slate-700">Tự đánh giá mức độ phản xạ nói tiếng Anh hiện tại:</div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                        <label class="p-3.5 rounded-xl border border-slate-200 hover:border-orange-500 cursor-pointer block space-y-1 bg-white transition">
                            <input type="radio" name="speaking_self_rate" value="beginner" class="text-orange-600">
                            <div class="font-bold text-slate-900">Mới bắt đầu / Mất gốc (A1)</div>
                            <p class="text-[11px] text-slate-500">Chưa tự tin phát âm, hay ấp úng khi giao tiếp câu cơ bản.</p>
                        </label>
                        <label class="p-3.5 rounded-xl border border-slate-200 hover:border-orange-500 cursor-pointer block space-y-1 bg-white transition">
                            <input type="radio" name="speaking_self_rate" value="intermediate" checked class="text-orange-600">
                            <div class="font-bold text-slate-900">Trung bình (A2 - B1)</div>
                            <p class="text-[11px] text-slate-500">Giao tiếp được câu hoàn chỉnh hàng ngày, phản xạ tương đối ổn.</p>
                        </label>
                        <label class="p-3.5 rounded-xl border border-slate-200 hover:border-orange-500 cursor-pointer block space-y-1 bg-white transition">
                            <input type="radio" name="speaking_self_rate" value="advanced" class="text-orange-600">
                            <div class="font-bold text-slate-900">Nâng cao (B2 - C1)</div>
                            <p class="text-[11px] text-slate-500">Tự tin thuyết trình, tranh luận học thuật và phản xạ nhanh.</p>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="p-6 bg-white rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div class="text-xs text-slate-500">
                    Vui lòng kiểm tra lại câu trả lời trước khi gửi bài thi.
                </div>
                <button type="submit" class="px-8 py-3.5 bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm rounded-xl shadow-lg hover:shadow-xl transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">check_circle</span>
                    <span>Nộp Bài Thi &amp; Xem Báo Cáo Điểm Tự Động</span>
                </button>
            </div>
        </form>
    </main>
</body>
</html>
