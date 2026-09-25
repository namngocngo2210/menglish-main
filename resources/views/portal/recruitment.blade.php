<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cơ hội Nghề nghiệp & Tuyển dụng — MENGLISH</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    <style>
        body { font-family: 'Be Vietnam Pro', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen flex flex-col justify-between">
    <!-- Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/menglish-logo.png') }}" alt="MENGLISH Logo" class="h-10 w-auto object-contain">
                <div class="border-l border-slate-300 pl-3">
                    <span class="text-xs font-extrabold uppercase tracking-wider text-orange-600 block">Tuyển Dụng & Nhân Sự</span>
                    <span class="text-[11px] text-slate-500 font-medium">Hệ thống Anh ngữ MENGLISH</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="#apply-form" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold rounded-xl shadow-xs transition">
                    Nộp CV ngay
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 text-white py-16 px-4 sm:px-6 relative overflow-hidden">
        <div class="max-w-4xl mx-auto text-center space-y-4 relative z-10">
            <span class="px-3 py-1 rounded-full bg-orange-500/20 text-orange-400 border border-orange-500/30 text-xs font-bold uppercase tracking-wider inline-block">
                MENGLISH Career Opportunities
            </span>
            <h1 class="text-3xl sm:text-4xl md:text-5xl font-black tracking-tight leading-tight">
                Gia nhập Đội ngũ Giáo dục Tiên phong tại <span class="text-orange-500">MENGLISH</span>
            </h1>
            <p class="text-sm sm:text-base text-slate-300 max-w-2xl mx-auto leading-relaxed">
                Môi trường làm việc trẻ trung, năng động, lộ trình thăng tiến minh bạch cùng chế độ đãi ngộ hấp dẫn dành cho Giảng viên và Nhân sự Vận hành.
            </p>
        </div>
    </section>

    <!-- Main Content -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-12 flex-1 w-full space-y-12">
        

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <!-- Left: Danh sách vị trí tuyển dụng (7 cols) -->
            <div class="lg:col-span-7 space-y-6">
                <div class="flex items-center justify-between border-b pb-3 border-slate-200">
                    <h2 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600">work</span>
                        Các vị trí đang tuyển dụng ({{ $jobs->count() }})
                    </h2>
                    <span class="text-xs text-slate-500">Cập nhật hôm nay</span>
                </div>

                <div class="space-y-4">
                    @forelse($jobs as $job)
                        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs hover:shadow-md hover:border-orange-200 transition space-y-3">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div>
                                    <h3 class="font-bold text-base text-slate-900">{{ $job->title }}</h3>
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 mt-1">
                                        <span class="font-semibold text-orange-600">{{ $job->department }}</span>
                                        <span>•</span>
                                        <span>{{ $job->employment_type }}</span>
                                        <span>•</span>
                                        <span>{{ $job->branch?->name ?? 'Toàn hệ thống' }}</span>
                                    </div>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 self-start sm:self-auto">
                                    {{ $job->salary_range ?: 'Mức lương thỏa thuận' }}
                                </span>
                            </div>

                            <p class="text-xs text-slate-600 line-clamp-3 leading-relaxed whitespace-pre-line">{{ $job->description }}</p>

                            @if($job->requirements)
                                <div class="p-3 bg-slate-50 rounded-xl text-xs text-slate-700">
                                    <strong class="text-slate-900 block mb-1">Yêu cầu ứng viên:</strong>
                                    <p class="whitespace-pre-line">{{ $job->requirements }}</p>
                                </div>
                            @endif

                            <div class="flex items-center justify-between pt-2 text-xs text-slate-400">
                                <span>Hạn nộp: {{ $job->deadline ? $job->deadline->format('d/m/Y') : 'Tuyển liên tục' }}</span>
                                <a href="#apply-form" onclick="selectPosition('{{ $job->id }}', '{{ $job->title }}', '{{ $job->branch_id }}')" class="font-bold text-orange-600 hover:underline">
                                    Ứng tuyển vị trí này &rarr;
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="bg-white rounded-2xl p-8 border border-slate-200 text-center text-slate-400">
                            <p class="text-sm font-medium">Hiện tại chưa có tin tuyển dụng nào mở. Bạn vẫn có thể nộp hồ sơ tiềm năng bên cạnh!</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Right: Form Nộp Hồ sơ Trực Tuyến (5 cols) -->
            <div id="apply-form" class="lg:col-span-5 bg-white rounded-2xl p-6 border border-slate-200 shadow-md space-y-5 sticky top-28">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600">send</span>
                        Nộp Hồ sơ Ứng tuyển Online
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">Điền thông tin và đính kèm CV, chúng tôi sẽ phản hồi trong vòng 24 - 48h</p>
                </div>

                <form action="{{ route('portal.recruitment.submit') }}" method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                    @csrf
                    <input type="hidden" id="job_posting_id" name="job_posting_id" value="">

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Họ và tên <span class="text-rose-500">*</span></label>
                        <input type="text" name="full_name" required placeholder="Nguyễn Văn A" class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Email <span class="text-rose-500">*</span></label>
                            <input type="email" name="email" required placeholder="name@example.com" class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Số điện thoại <span class="text-rose-500">*</span></label>
                            <input type="text" name="phone" required placeholder="0987654321" class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Vị trí ứng tuyển <span class="text-rose-500">*</span></label>
                        <input type="text" id="applying_position" name="applying_position" required placeholder="Ví dụ: Giáo viên Tiếng Anh / Trợ giảng" class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Cơ sở mong muốn làm việc</label>
                        <select id="branch_id" name="branch_id" class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500">
                            <option value="">Toàn hệ thống / Linh hoạt</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Đính kèm CV (PDF, DOC, DOCX tối đa 10MB) <span class="text-rose-500">*</span></label>
                        <input type="file" name="cv_file" required accept=".pdf,.doc,.docx" class="w-full text-xs border border-slate-300 rounded-xl p-2 bg-slate-50 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100 cursor-pointer">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Link Video dạy thử / Portfolio / LinkedIn</label>
                        <input type="url" name="portfolio_url" placeholder="https://youtube.com/... hoặc https://linkedin.com/in/..." class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Thư ngỏ / Giới thiệu bản thân</label>
                        <textarea name="cover_letter" rows="3" placeholder="Chia sẻ thêm về kinh nghiệm giảng dạy hoặc lý do bạn chọn MENGLISH..." class="w-full text-xs rounded-xl border-slate-300 p-2.5 focus:border-orange-500 focus:ring-orange-500"></textarea>
                    </div>

                    <button type="submit" class="w-full py-3 bg-orange-600 hover:bg-orange-700 text-white font-bold rounded-xl shadow-sm transition flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Gửi Hồ sơ Ứng tuyển
                    </button>
                </form>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-8 border-t border-slate-800 text-xs">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center space-y-2">
            <p class="font-bold text-slate-200">HỆ THỐNG ANH NGỮ MENGLISH — PHÒNG NHÂN SỰ & ĐÀO TẠO</p>
            <p>Hotline Tuyển dụng: 0988.xxx.xxx • Email: tuyendung@menglish.edu.vn</p>
            <p class="text-slate-600">© 2026 MENGLISH. All rights reserved.</p>
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
