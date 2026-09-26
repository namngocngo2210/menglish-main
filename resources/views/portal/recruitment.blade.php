<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cơ hội Nghề nghiệp & Tuyển dụng — MENGLISH</title>
    {{-- Dùng CSS dự án (design tokens + x-ui.*) thay cho Tailwind CDN --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    <style>
        body { font-family: 'Be Vietnam Pro', sans-serif; }
    </style>
</head>
<body class="bg-surface-container-low text-on-surface antialiased min-h-screen flex flex-col justify-between">
    {{-- Header --}}
    <header class="bg-surface-container-lowest border-b border-surface-container-highest sticky top-0 z-30 shadow-xs">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/menglish-logo.png') }}" alt="MENGLISH Logo" class="h-10 w-auto object-contain">
                <div class="border-l border-outline-variant pl-3">
                    <span class="text-xs font-extrabold uppercase tracking-wider text-primary block">Tuyển Dụng & Nhân Sự</span>
                    <span class="text-[11px] text-on-surface-variant font-medium">Hệ thống Anh ngữ MENGLISH</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button href="#apply-form">
                    Nộp CV ngay
                </x-ui.button>
            </div>
        </div>
    </header>

    {{-- Hero Section --}}
    <section class="bg-gradient-to-br from-inverse-surface via-secondary-hover to-inverse-surface text-white py-16 px-4 sm:px-6 relative overflow-hidden">
        <div class="max-w-4xl mx-auto text-center space-y-4 relative z-10">
            <span class="px-3 py-1 rounded-full bg-primary-container/20 text-primary-container border border-primary-container/30 text-xs font-bold uppercase tracking-wider inline-block">
                MENGLISH Career Opportunities
            </span>
            <h1 class="text-3xl sm:text-4xl md:text-5xl font-black tracking-tight leading-tight">
                Gia nhập Đội ngũ Giáo dục Tiên phong tại <span class="text-primary">MENGLISH</span>
            </h1>
            <p class="text-sm sm:text-base text-white/80 max-w-2xl mx-auto leading-relaxed">
                Môi trường làm việc trẻ trung, năng động, lộ trình thăng tiến minh bạch cùng chế độ đãi ngộ hấp dẫn dành cho Giảng viên và Nhân sự Vận hành.
            </p>
        </div>
    </section>

    {{-- Main Content --}}
    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-12 flex-1 w-full space-y-12">
        

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            {{-- Left: Danh sách vị trí tuyển dụng (7 cols) --}}
            <div class="lg:col-span-7 space-y-6">
                <div class="flex items-center justify-between border-b pb-3 border-surface-container-highest">
                    <h2 class="text-xl font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">work</span>
                        Các vị trí đang tuyển dụng ({{ $jobs->count() }})
                    </h2>
                    <span class="text-xs text-on-surface-variant">Cập nhật hôm nay</span>
                </div>

                <div class="space-y-4">
                    @forelse($jobs as $job)
                        <div class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-xs hover:shadow-md hover:border-primary-container/30 transition space-y-3">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div>
                                    <h3 class="font-bold text-base text-on-surface">{{ $job->title }}</h3>
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-on-surface-variant mt-1">
                                        <span class="font-semibold text-primary">{{ $job->department }}</span>
                                        <span>•</span>
                                        <span>{{ $job->employment_type }}</span>
                                        <span>•</span>
                                        <span>{{ $job->branch?->name ?? 'Toàn hệ thống' }}</span>
                                    </div>
                                </div>
                                <x-ui.badge color="success" :pill="true" :dot="false" class="self-start sm:self-auto">
                                    {{ $job->salary_range ?: 'Mức lương thỏa thuận' }}
                                </x-ui.badge>
                            </div>

                            <p class="text-xs text-on-surface-variant line-clamp-3 leading-relaxed whitespace-pre-line">{{ $job->description }}</p>

                            @if($job->requirements)
                                <div class="p-3 bg-surface-container-low rounded-xl text-xs text-on-surface-variant">
                                    <strong class="text-on-surface block mb-1">Yêu cầu ứng viên:</strong>
                                    <p class="whitespace-pre-line">{{ $job->requirements }}</p>
                                </div>
                            @endif

                            <div class="flex items-center justify-between pt-2 text-xs text-on-surface-variant/70">
                                <span>Hạn nộp: {{ $job->deadline ? $job->deadline->format('d/m/Y') : 'Tuyển liên tục' }}</span>
                                <a href="#apply-form" onclick="selectPosition('{{ $job->id }}', '{{ $job->title }}', '{{ $job->branch_id }}')" class="font-bold text-primary hover:underline">
                                    Ứng tuyển vị trí này &rarr;
                                </a>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest" icon="work_off"
                                           title="Hiện tại chưa có tin tuyển dụng nào mở. Bạn vẫn có thể nộp hồ sơ tiềm năng bên cạnh!" />
                    @endforelse
                </div>
            </div>

            {{-- Right: Form Nộp Hồ sơ Trực Tuyến (5 cols) --}}
            <div id="apply-form" class="lg:col-span-5 bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest shadow-md space-y-5 sticky top-28">
                <div>
                    <h2 class="text-lg font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">send</span>
                        Nộp Hồ sơ Ứng tuyển Online
                    </h2>
                    <p class="text-xs text-on-surface-variant mt-0.5">Điền thông tin và đính kèm CV, chúng tôi sẽ phản hồi trong vòng 24 - 48h</p>
                </div>

                <form action="{{ route('portal.recruitment.submit') }}" method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                    @csrf
                    <input type="hidden" id="job_posting_id" name="job_posting_id" value="">

                    <x-ui.input name="full_name" label="Họ và tên" required placeholder="Nguyễn Văn A" />

                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input type="email" name="email" label="Email" required placeholder="name@example.com" />
                        <x-ui.input name="phone" label="Số điện thoại" required placeholder="0987654321" />
                    </div>

                    <x-ui.input id="applying_position" name="applying_position" label="Vị trí ứng tuyển" required placeholder="Ví dụ: Giáo viên Tiếng Anh / Trợ giảng" />

                    <x-ui.select id="branch_id" name="branch_id" label="Cơ sở mong muốn làm việc" placeholder="Toàn hệ thống / Linh hoạt"
                                 :options="$branches->pluck('name', 'id')" />

                    <x-ui.field label="Đính kèm CV (PDF, DOC, DOCX tối đa 10MB)" name="cv_file" for="cv_file" required>
                        <input type="file" id="cv_file" name="cv_file" required accept=".pdf,.doc,.docx" class="w-full text-xs border border-outline-variant rounded-lg p-2 bg-surface-container-low file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary-container/10 file:text-primary hover:file:bg-primary-container/20 cursor-pointer">
                    </x-ui.field>

                    <x-ui.input type="url" name="portfolio_url" label="Link Video dạy thử / Portfolio / LinkedIn" placeholder="https://youtube.com/... hoặc https://linkedin.com/in/..." />

                    <x-ui.textarea name="cover_letter" label="Thư ngỏ / Giới thiệu bản thân" rows="3" placeholder="Chia sẻ thêm về kinh nghiệm giảng dạy hoặc lý do bạn chọn MENGLISH..." />

                    <x-ui.button type="submit" icon="send" class="w-full">
                        Gửi Hồ sơ Ứng tuyển
                    </x-ui.button>
                </form>
            </div>
        </div>
    </main>

    {{-- Footer --}}
    <footer class="bg-inverse-surface text-inverse-on-surface/70 py-8 border-t border-inverse-surface text-xs">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center space-y-2">
            <p class="font-bold text-inverse-on-surface">HỆ THỐNG ANH NGỮ MENGLISH — PHÒNG NHÂN SỰ & ĐÀO TẠO</p>
            <p>Hotline Tuyển dụng: 0988.xxx.xxx • Email: tuyendung@menglish.edu.vn</p>
            <p class="text-inverse-on-surface/60">© 2026 MENGLISH. All rights reserved.</p>
        </div>
    </footer>

    <script>
        function selectPosition(jobId, title, branchId) {
            document.getElementById('job_posting_id').value = jobId;
            document.getElementById('applying_position').value = title;
            if (branchId) {
                document.getElementById('branch_id').value = branchId;
            }
        }
    </script>
</body>
</html>
