<!DOCTYPE html>
<html lang="vi" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bản Đánh Giá Năng Lực Tiếng Anh — {{ $submission->candidate_name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800,900&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            body { background: #fff !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .scorecard-sheet { box-shadow: none !important; border: none !important; max-width: 100% !important; margin: 0 !important; padding: 16px !important; }
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-900 bg-slate-100 min-h-screen py-6 px-3 sm:px-6">

    <!-- Top Action Floating Bar -->
    <div class="max-w-4xl mx-auto mb-4 flex items-center justify-between no-print">
        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('placement-tests.index') }}" class="px-3 py-1.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                    <span>Quay lại Admin</span>
                </a>
            @endauth
        </div>

        <div class="flex items-center gap-2">
            <button type="button" onclick="window.print()" class="px-4 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">print</span>
                <span>In Bản Đánh Giá / Xuất PDF</span>
            </button>
        </div>
    </div>

    <!-- Official MEnglish Scorecard Template Sheet -->
    <main class="scorecard-sheet max-w-4xl mx-auto bg-white rounded-3xl border border-slate-200 shadow-xl p-6 sm:p-10 space-y-6">
        
        <!-- 1. Header (Logo, Center Title, Hotline & Campuses) -->
        <div class="border-b-2 border-orange-500 pb-5">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                <!-- Logo & Brand Slogan -->
                <div class="flex items-center gap-3">
                    <img src="/images/menglish-logo.png" alt="ME Education Logo" class="h-16 w-auto object-contain">
                    <div>
                        <div class="font-black text-slate-900 text-xl tracking-tight leading-tight">MEnglish</div>
                        <div class="text-xs font-bold text-orange-600 italic tracking-wider">We change - We lead</div>
                        <div class="text-[11px] text-slate-500 font-semibold mt-0.5">Hệ thống Anh ngữ MEnglish</div>
                    </div>
                </div>

                <!-- Main Assessment Title -->
                <div class="text-center sm:text-right space-y-1">
                    <h1 class="text-xl sm:text-2xl font-black text-orange-600 uppercase tracking-tight">
                        BẢN ĐÁNH GIÁ NĂNG LỰC TIẾNG ANH
                    </h1>
                    <div class="flex items-center justify-center sm:justify-end gap-1.5 text-xs font-bold text-slate-700 font-mono">
                        <span class="material-symbols-outlined text-sm text-orange-600">call</span>
                        <span>Hotline: <strong class="text-orange-600 font-black">0975 996 986</strong></span>
                    </div>
                    <div class="text-[10px] text-slate-400 font-mono">Mã bài thi: {{ $submission->test?->code }} · Ngày thi: {{ $submission->created_at->format('d/m/Y') }}</div>
                </div>
            </div>

            <!-- Campus Locations Footer Line -->
            <div class="mt-3 pt-2.5 border-t border-slate-100 flex flex-wrap items-center justify-between text-[11px] text-slate-600 gap-2">
                <span><strong>CS 1:</strong> Số 15 ngõ 172 Ngọc Hà</span>
                <span><strong>CS 2:</strong> Số 23 ngõ 209 Đội Cấn</span>
                <span><strong>CS 3:</strong> Số 12 ngõ 24/55 Hoàng Hoa Thám</span>
            </div>
        </div>

        <!-- 2. Thông tin thí sinh: chỉ hiển thị đúng những gì thí sinh đã nhập khi làm bài -->
        <div class="p-4 rounded-2xl bg-orange-50/50 border border-orange-200/80 space-y-2.5">
            <div class="flex items-center gap-1.5 text-xs font-black text-orange-800 uppercase tracking-wider pb-1.5 border-b border-orange-200">
                <span class="material-symbols-outlined text-base text-orange-600">person</span>
                <span>THÔNG TIN THÍ SINH</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div class="flex items-baseline justify-between sm:block">
                    <span class="text-slate-500 font-medium">Họ tên:</span>
                    <span class="font-extrabold text-slate-900 text-sm sm:block">{{ $submission->candidate_name }}</span>
                </div>
                <div class="flex items-baseline justify-between sm:block">
                    <span class="text-slate-500 font-medium">ĐT liên hệ:</span>
                    <span class="font-extrabold text-orange-600 font-mono text-sm sm:block">{{ $submission->candidate_phone }}</span>
                </div>
                <div class="flex items-baseline justify-between sm:block">
                    <span class="text-slate-500 font-medium">Email:</span>
                    <span class="font-semibold text-slate-800 sm:block">{{ $submission->candidate_email ?: '—' }}</span>
                </div>
            </div>
        </div>

        @if ($submission->isPending())
            <div class="p-4 rounded-2xl bg-sky-50 border border-sky-200 text-xs text-sky-900 flex items-start gap-2">
                <span class="material-symbols-outlined text-base text-sky-600">hourglass_top</span>
                <span>Bài làm đã được ghi nhận và <strong>đang chờ Học vụ chấm</strong> phần Viết / Nói. Điểm tổng, trình độ và khóa học đề xuất sẽ được cập nhật sau khi chấm.</span>
            </div>
        @endif

        <!-- 3. MỤC TIÊU HỌC TIẾNG ANH (Goals Checklist) -->
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-2xs space-y-2.5">
            <div class="flex items-center gap-1.5 text-xs font-black text-slate-900 uppercase tracking-wider">
                <span class="material-symbols-outlined text-base text-orange-600">flag</span>
                <span>MỤC TIÊU HỌC TIẾNG ANH</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-xs">
                <div class="flex items-center gap-2 p-2 rounded-xl bg-orange-50/60 border border-orange-100 font-semibold text-slate-800">
                    <span class="w-4 h-4 rounded-md bg-orange-600 text-white flex items-center justify-center text-[11px] font-bold">✓</span>
                    <span>Tự tin</span>
                </div>
                <div class="flex items-center gap-2 p-2 rounded-xl bg-orange-50/60 border border-orange-100 font-semibold text-slate-800">
                    <span class="w-4 h-4 rounded-md bg-orange-600 text-white flex items-center justify-center text-[11px] font-bold">✓</span>
                    <span>Chắc ngữ pháp</span>
                </div>
                <div class="flex items-center gap-2 p-2 rounded-xl bg-orange-50/60 border border-orange-100 font-semibold text-slate-800">
                    <span class="w-4 h-4 rounded-md bg-orange-600 text-white flex items-center justify-center text-[11px] font-bold">✓</span>
                    <span>Cải thiện điểm số trên trường</span>
                </div>
                <div class="flex items-center gap-2 p-2 rounded-xl bg-orange-50/60 border border-orange-100 font-semibold text-slate-800">
                    <span class="w-4 h-4 rounded-md bg-orange-600 text-white flex items-center justify-center text-[11px] font-bold">✓</span>
                    <span>Thi lấy chứng chỉ quốc tế</span>
                </div>
                <div class="flex items-center gap-2 p-2 rounded-xl bg-orange-50/60 border border-orange-100 font-semibold text-slate-800">
                    <span class="w-4 h-4 rounded-md bg-orange-600 text-white flex items-center justify-center text-[11px] font-bold">✓</span>
                    <span>Cải thiện giao tiếp</span>
                </div>
                <div class="flex items-center gap-2 p-2 rounded-xl bg-orange-50/60 border border-orange-100 font-semibold text-slate-800">
                    <span class="w-4 h-4 rounded-md bg-orange-600 text-white flex items-center justify-center text-[11px] font-bold">✓</span>
                    <span>Xây dựng kĩ năng tư duy</span>
                </div>
            </div>
        </div>

        <!-- 4. KẾT QUẢ ĐÁNH GIÁ NĂNG LỰC (Band Track & 4 Skills Score Table - Light Theme) -->
        <div class="p-5 rounded-2xl bg-white border-2 border-slate-200/90 shadow-xs space-y-4">
            <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <div class="flex items-center gap-2 text-xs font-black uppercase tracking-wider text-slate-900">
                    <span class="material-symbols-outlined text-xl text-amber-500">emoji_events</span>
                    <span>KẾT QUẢ ĐÁNH GIÁ NĂNG LỰC</span>
                </div>
                <div class="text-[11px] font-mono font-medium text-slate-500">
                    Hệ thống thang chuẩn Quốc tế Cambridge / CEFR
                </div>
            </div>

            <!-- Cambridge / CEFR Level Progression Track -->
            @php
                $levels = [
                    'PRE STARTERS',
                    'STARTERS',
                    'MOVERS',
                    'FLYERS',
                    'KET',
                    'PET',
                    'IELTS FOUNDATION',
                    'IELTS ACADEMIC'
                ];
                // Level theo lớp xếp (thang điểm khối lớp — BA Q2), không quy đổi từ điểm trung bình.
                $finalClass = mb_strtoupper((string) $submission->finalClass());
                $activeLevelName = match (true) {
                    $finalClass === '' || $submission->isPending() => null,
                    str_contains($finalClass, 'PRE STARTERS') => 'PRE STARTERS',
                    str_contains($finalClass, 'STARTERS') => 'STARTERS',
                    str_contains($finalClass, 'MOVERS'), str_contains($finalClass, 'FAM 2') => 'MOVERS',
                    str_contains($finalClass, 'FLYERS') => 'FLYERS',
                    str_contains($finalClass, 'KET') => 'KET',
                    str_contains($finalClass, 'PET') => 'PET',
                    str_contains($finalClass, 'IELTS FOUNDATION') => 'IELTS FOUNDATION',
                    str_contains($finalClass, 'IELTS') => 'IELTS ACADEMIC',
                    default => null,
                };
                $rubricGraded = $submission->hasRubricGrade();
                $maxScores = \App\Services\PlacementRubricService::maxScores($submission->grade_group);
                $fmtScore = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
            @endphp
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-1.5 text-center">
                @foreach ($levels as $lvl)
                    @php
                        $isActive = ($lvl === $activeLevelName);
                    @endphp
                    <div class="p-2 rounded-xl border text-[10px] font-bold uppercase transition {{ $isActive ? 'bg-gradient-to-r from-orange-500 to-amber-500 border-orange-400 text-white shadow-md ring-2 ring-orange-300 scale-105' : 'bg-slate-50 border-slate-200/80 text-slate-600' }}">
                        {{ $lvl }}
                        @if ($isActive)
                            <div class="text-[9px] font-black text-amber-100 mt-0.5">★ ĐẠT ★</div>
                        @endif
                    </div>
                @endforeach
            </div>

            <!-- 4 Skills Scores Table (Light Theme) -->
            <div class="bg-slate-50/80 rounded-2xl p-2 border border-slate-200 overflow-hidden">
                <table class="w-full text-center border-collapse">
                    @if ($rubricGraded)
                        <thead>
                            <tr class="text-[11px] font-black uppercase text-slate-600 border-b border-slate-200">
                                <th class="py-2.5 px-2">LISTENING /{{ $maxScores['listening'] }}</th>
                                <th class="py-2.5 px-2">READING &amp; WRITING /{{ $maxScores['reading_writing'] }}</th>
                                <th class="py-2.5 px-2">SPEAKING /{{ $maxScores['speaking'] }}</th>
                                <th class="py-2.5 px-2 bg-orange-100 text-orange-950 rounded-t-xl">TOTAL /{{ array_sum($maxScores) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="font-mono text-xl sm:text-2xl font-black">
                                <td class="py-3 px-2 text-indigo-700">{{ $fmtScore($submission->listening_score) }}</td>
                                <td class="py-3 px-2 text-emerald-700">{{ $fmtScore($submission->reading_writing_score) }}</td>
                                <td class="py-3 px-2 text-rose-700">{{ $fmtScore($submission->speaking_score) }}</td>
                                <td class="py-3 px-2 bg-gradient-to-br from-orange-500 to-amber-600 text-white rounded-b-xl text-3xl font-black shadow-inner">{{ $fmtScore($submission->total_score) }}</td>
                            </tr>
                        </tbody>
                    @else
                        <thead>
                            <tr class="text-[11px] font-black uppercase text-slate-600 border-b border-slate-200">
                                <th class="py-2.5 px-2">LISTENING</th>
                                <th class="py-2.5 px-2">SPEAKING</th>
                                <th class="py-2.5 px-2">READING</th>
                                <th class="py-2.5 px-2">WRITING</th>
                                <th class="py-2.5 px-2 bg-orange-100 text-orange-950 rounded-t-xl">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="font-mono text-xl sm:text-2xl font-black">
                                <td class="py-3 px-2 text-indigo-700">{{ $submission->listening_score ?? '—' }}</td>
                                <td class="py-3 px-2 text-rose-700">{{ $submission->speaking_score ?? '—' }}</td>
                                <td class="py-3 px-2 text-emerald-700">{{ $submission->reading_score ?? '—' }}</td>
                                <td class="py-3 px-2 text-purple-700">{{ $submission->writing_score ?? '—' }}</td>
                                <td class="py-3 px-2 bg-gradient-to-br from-orange-500 to-amber-600 text-white rounded-b-xl text-3xl font-black shadow-inner">{{ $submission->overall_score ?? '—' }}</td>
                            </tr>
                        </tbody>
                    @endif
                </table>
            </div>
        </div>

        <!-- 5. NHẬN XÉT CỦA GIÁO VIÊN & KẾT QUẢ XẾP LỚP -->
        <div class="space-y-4">
            <!-- Level & Recommendations -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 rounded-2xl bg-indigo-50/80 border border-indigo-200 space-y-1">
                    <div class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">verified</span>
                        <span>Khối lớp / thang điểm:</span>
                    </div>
                    <div class="text-lg font-black text-indigo-950">{{ $submission->grade_group ? \App\Services\PlacementRubricService::groupLabel($submission->grade_group) : '—' }}</div>
                </div>

                <div class="p-4 rounded-2xl bg-orange-50/80 border border-orange-200 space-y-1">
                    <div class="text-[11px] font-bold text-orange-800 uppercase tracking-wider flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">school</span>
                        <span>Kết quả xếp lớp:</span>
                    </div>
                    <div class="text-lg font-black text-orange-950">{{ $submission->isPending() ? 'Chờ Học vụ chấm' : ($submission->finalClass() ?? '—') }}</div>
                </div>
            </div>

            <!-- Nhận xét chi tiết của GV theo từng đầu mục kỹ năng -->
            @php
                $rawComments = $rubricGraded
                    ? \App\Services\PlacementRubricService::composeComments([
                        'listening' => $submission->listening_comment,
                        'reading_writing' => $submission->reading_writing_comment,
                        'speaking' => $submission->speaking_comment,
                    ], $submission->finalClass(), $submission->teacher_comments ? '【Ghi chú của giáo viên】: '.$submission->teacher_comments : null)
                    : ($submission->teacher_comments ?? '');
                $parsedComments = [];
                if (preg_match_all('/【(.*?)】\s*:\s*(.*?)(?=(?:【|$))/us', $rawComments, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $parsedComments[] = [
                            'title' => trim($m[1]),
                            'content' => trim($m[2]),
                        ];
                    }
                }
            @endphp

            <div class="p-4 sm:p-5 rounded-2xl bg-white border border-slate-200 shadow-2xs space-y-3">
                <div class="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5 pb-2 border-b border-slate-100">
                    <span class="material-symbols-outlined text-orange-600 text-base">psychology</span>
                    <span>NHẬN XÉT CỦA GIÁO VIÊN CHUYÊN MÔN (ACADEMIC COMMENTS):</span>
                </div>

                @if (!empty($parsedComments))
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        @foreach ($parsedComments as $sec)
                            @php
                                $titleLower = mb_strtolower($sec['title']);
                                $theme = match(true) {
                                    str_contains($titleLower, 'nghe') => [
                                        'icon' => 'headphones',
                                        'badge' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                        'box' => 'border-indigo-100 bg-indigo-50/40',
                                        'icon_color' => 'text-indigo-600',
                                    ],
                                    str_contains($titleLower, 'đọc') || str_contains($titleLower, 'viết') => [
                                        'icon' => 'menu_book',
                                        'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'box' => 'border-emerald-100 bg-emerald-50/40',
                                        'icon_color' => 'text-emerald-600',
                                    ],
                                    str_contains($titleLower, 'nói') => [
                                        'icon' => 'record_voice_over',
                                        'badge' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'box' => 'border-rose-100 bg-rose-50/40',
                                        'icon_color' => 'text-rose-600',
                                    ],
                                    default => [
                                        'icon' => 'military_tech',
                                        'badge' => 'bg-amber-50 text-amber-800 border-amber-200',
                                        'box' => 'border-amber-100 bg-amber-50/40 md:col-span-2',
                                        'icon_color' => 'text-amber-600',
                                    ],
                                };
                            @endphp
                            <div class="p-3.5 rounded-xl border {{ $theme['box'] }} space-y-1.5">
                                <div class="flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base {{ $theme['icon_color'] }}">{{ $theme['icon'] }}</span>
                                    <span class="px-2 py-0.5 rounded-md border text-[11px] font-bold {{ $theme['badge'] }}">{{ $sec['title'] }}</span>
                                </div>
                                <p class="text-slate-700 leading-relaxed text-[11.5px] pl-0.5">
                                    {{ $sec['content'] }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-xs text-slate-800 leading-relaxed italic bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                        {{ $rawComments !== '' ? '"'.$rawComments.'"' : '—' }}
                    </div>
                @endif
            </div>
        </div>

        <!-- 6. Signatures & Footer Commitments -->
        <div class="pt-4 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600">
            <div class="space-y-0.5">
                <div class="font-bold text-slate-900">MEnglish Education Vietnam</div>
                <div class="text-[11px] text-slate-400">Cam kết chất lượng đào tạo &amp; tiến bộ vượt bậc</div>
            </div>
            <div class="text-center font-semibold">
                <div class="text-slate-400 text-[10px]">Hà Nội, ngày {{ $submission->created_at->format('d') }} tháng {{ $submission->created_at->format('m') }} năm {{ $submission->created_at->format('Y') }}</div>
                <div class="font-bold text-slate-900 mt-1">Ban Đào Tạo &amp; Khảo Thí MEnglish</div>
                @unless ($submission->isPending())
                    <div class="text-[10px] text-emerald-600 font-mono font-bold flex items-center justify-center gap-0.5 mt-0.5">
                        <span class="material-symbols-outlined text-[13px]">verified_user</span>
                        <span>Hệ thống đã phê duyệt điện tử</span>
                    </div>
                @endunless
            </div>
        </div>
    </main>
</body>
</html>
