<!DOCTYPE html>
<html lang="vi" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MENGLISH - Hệ Thống 58 Màn Hình Giao Diện UI/UX</title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        [x-cloak] { display: none !important; }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-full flex flex-col" x-data="screenGallery()">
    <!-- Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <a href="{{ route('dashboard') }}" class="w-10 h-10 rounded-xl bg-orange-600 flex items-center justify-center text-white font-bold text-xl shadow-md shadow-orange-200 hover:bg-orange-700 transition">
                        M
                    </a>
                    <div>
                        <h1 class="text-xl font-bold text-slate-900 tracking-tight flex items-center gap-2">
                            MENGLISH System Screens
                            <span class="bg-orange-100 text-orange-700 text-xs font-semibold px-2.5 py-0.5 rounded-full border border-orange-200">58 Giao diện Mới</span>
                        </h1>
                        <p class="text-xs text-slate-500">Hệ thống Quản trị Đào tạo, Học vụ KPI, Cổng Giáo viên & Phụ huynh/Học sinh</p>
                    </div>
                </div>

                <!-- Action links & Search -->
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition shrink-0">
                        <span class="material-symbols-outlined text-sm">arrow_back</span>
                        <span>Về Admin</span>
                    </a>

                    <a href="{{ route('mockup-hub.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold transition shrink-0">
                        <span class="material-symbols-outlined text-sm">hub</span>
                        <span>Mockup Hub</span>
                    </a>
                    
                    <!-- Search Box -->
                    <div class="relative flex-1 md:w-72">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                        <input type="text" x-model="search" @input="filterScreens()" placeholder="Tìm kiếm màn hình..." 
                            class="w-full pl-9 pr-4 py-2 bg-slate-100 border border-slate-200 rounded-lg text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:bg-white transition-all">
                    </div>
                </div>
            </div>

            <!-- Category Filter Tabs -->
            <div class="flex items-center gap-2 mt-4 overflow-x-auto pb-1 no-scrollbar border-t border-slate-100 pt-3">
                <template x-for="cat in categories" :key="cat.id">
                    <button 
                        @click="setCategory(cat.id)" 
                        :class="currentCat === cat.id ? 'bg-orange-600 text-white shadow-sm font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                        class="px-3.5 py-1.5 rounded-lg text-xs whitespace-nowrap transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm" x-text="cat.icon"></span>
                        <span x-text="cat.name"></span>
                        <span class="text-[10px] px-1.5 py-0.2 rounded-full" :class="currentCat === cat.id ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'" x-text="cat.count"></span>
                    </button>
                </template>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 w-full">
        <!-- Results Counter -->
        <div class="mb-4 flex items-center justify-between text-xs text-slate-500">
            <div>
                Hiển thị <span class="font-bold text-slate-800" x-text="filteredScreens.length"></span> / 58 màn hình
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-50 text-amber-800 text-[11px] font-semibold border border-amber-200">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    290 Dữ liệu mẫu SEED (5 bản ghi / màn)
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-semibold border border-emerald-200">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    100% UI nguyên bản theo thiết kế
                </span>
            </div>
        </div>

        <!-- Screens Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <template x-for="(s, idx) in filteredScreens" :key="s.folder_name">
                <div class="bg-white rounded-xl border border-slate-200/80 shadow-sm hover:shadow-md hover:border-orange-300 transition-all flex flex-col overflow-hidden group">
                    <!-- Thumbnail with overlay -->
                    <div class="h-44 bg-slate-100 relative overflow-hidden border-b border-slate-100 flex items-center justify-center cursor-pointer" @click="openModal(s)">
                        <template x-if="s.has_png">
                            <img :src="'/roundcuoi-kieulien/' + s.category_id + '/' + s.folder_name + '/screen.png'" :alt="s.title_vn" class="w-full h-full object-cover object-top group-hover:scale-105 transition-transform duration-300">
                        </template>
                        <template x-if="!s.has_png">
                            <div class="text-center p-4">
                                <span class="material-symbols-outlined text-4xl text-slate-400">web</span>
                                <p class="text-xs text-slate-400 mt-1 font-medium">Giao diện HTML</p>
                            </div>
                        </template>
                        <div class="absolute top-2 left-2 bg-slate-900/80 backdrop-blur-sm text-white text-[10px] font-mono font-bold px-2 py-0.5 rounded-md">
                            #<span x-text="s.num"></span>
                        </div>
                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                            <span class="px-3 py-1.5 bg-white/95 rounded-lg text-xs font-semibold text-slate-900 shadow-md flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">visibility</span> Xem nhanh
                            </span>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="p-4 flex-1 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between gap-1 mb-1">
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-orange-600 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" x-text="s.icon"></span>
                                    <span x-text="s.category_name.split('(')[0]"></span>
                                </div>
                                <template x-if="nativeMap[s.category_id + '/' + s.folder_name]">
                                    <span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center gap-0.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>Native
                                    </span>
                                </template>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800 line-clamp-2 mb-1 group-hover:text-orange-600 transition-colors" x-text="s.title_vn"></h3>
                            <p class="text-xs text-slate-500 line-clamp-2 mb-3" x-text="s.desc"></p>
                        </div>

                        <!-- Card Actions -->
                        <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                            <template x-if="nativeMap[s.category_id + '/' + s.folder_name]">
                                <a :href="nativeMap[s.category_id + '/' + s.folder_name]" target="_blank" class="flex-1 inline-flex items-center justify-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white py-1.5 px-3 rounded-lg text-xs font-bold transition-colors shadow-sm">
                                    <span class="material-symbols-outlined text-sm">rocket_launch</span> Mở Native
                                </a>
                            </template>
                            <template x-if="!nativeMap[s.category_id + '/' + s.folder_name]">
                                <a :href="'/academic-system/' + s.category_id + '/' + s.folder_name" target="_blank" class="flex-1 inline-flex items-center justify-center gap-1 bg-orange-50 hover:bg-orange-600 text-orange-700 hover:text-white py-1.5 px-3 rounded-lg text-xs font-semibold transition-colors shadow-sm">
                                    <span class="material-symbols-outlined text-sm">open_in_new</span> Mở màn hình
                                </a>
                            </template>
                            <button @click="openModal(s)" title="Xem preview" class="p-1.5 text-slate-500 hover:text-orange-600 hover:bg-orange-50 rounded-lg transition-colors border border-slate-200">
                                <span class="material-symbols-outlined text-sm">preview</span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Empty State -->
        <div x-show="filteredScreens.length === 0" x-cloak class="py-16 text-center text-slate-400">
            <span class="material-symbols-outlined text-5xl mb-2 text-slate-300">search_off</span>
            <p class="text-base font-semibold text-slate-600">Không tìm thấy màn hình phù hợp</p>
            <p class="text-xs text-slate-400 mt-1">Thử tìm kiếm với từ khóa khác hoặc chuyển danh mục.</p>
        </div>
    </main>

    <!-- Interactive Fullscreen Modal Preview -->
    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/75 backdrop-blur-sm" @keydown.escape.window="modalOpen = false">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-6xl h-[92vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200" @click.outside="modalOpen = false">
            <!-- Modal Header -->
            <div class="h-14 px-5 bg-slate-900 text-white flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-orange-600 text-white font-bold flex items-center justify-center text-sm">
                        M
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <span x-text="activeScreen?.title_vn"></span>
                            <span class="text-[10px] bg-white/20 text-white px-2 py-0.5 rounded" x-text="'#' + activeScreen?.num"></span>
                        </h3>
                        <p class="text-[11px] text-slate-400 font-mono" x-text="activeScreen?.category_id + '/' + activeScreen?.folder_name"></p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a :href="'/academic-system/' + activeScreen?.category_id + '/' + activeScreen?.folder_name" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-700 text-white text-xs font-semibold shadow-sm transition">
                        <span class="material-symbols-outlined text-sm">open_in_new</span>
                        <span>Mở toàn màn hình</span>
                    </a>
                    <button @click="modalOpen = false" class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition">
                        <span class="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>
            </div>

            <!-- Modal Body (Iframe) -->
            <div class="flex-1 bg-slate-100 p-2 overflow-hidden">
                <template x-if="modalOpen">
                    <iframe :src="'/academic-system/' + activeScreen?.category_id + '/' + activeScreen?.folder_name" class="w-full h-full rounded-xl border border-slate-300 bg-white shadow-inner" frameborder="0"></iframe>
                </template>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500 flex items-center justify-center gap-2 flex-wrap px-4">
        <span>MENGLISH Education System &bull; 2026</span>
        <span class="text-slate-300">&bull;</span>
        <span>Phát triển bởi <a href="https://vmst.vn" target="_blank" rel="noopener noreferrer" class="font-bold text-[#ea580c] hover:underline">VMST Media</a></span>
    </footer>

    <script>
        function screenGallery() {
            const allScreens = @json($screens);
            const initialCat = '{{ $selectedCat }}';

            const nativeMap = {
                // Flow 1: Tuyển sinh, Khai giảng & Quản lý Lớp học (6 bước chuẩn BA)
                '01_Web_Admin/12_dat_lich_hoc_thu_popup': '{{ route('classes.trial-booking') }}',
                '01_Web_Admin/13_tao_lop_moi': '{{ route('classes.create') }}',
                '01_Web_Admin/14_ho_so_lop_hoc': '{{ route('classes.profile') }}',
                '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/13_tong_quan_danh_sach_lop_hoc_thuat': '{{ route('classes.academic-overview') }}',
                '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/14_danh_sach_lop_chi_tiet_hoc_thuat': '{{ route('classes.academic-list') }}',
                '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/15_chi_tiet_lop_hoc_hoc_thuat': '{{ route('classes.academic-detail') }}',
                // Flow 2: Quản lý Giáo trình & Phân bổ Syllabus (8 bước)
                '01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh': '{{ route('syllabus.documents') }}',
                '01_Web_Admin/02_soan_syllabus_theo_chang': '{{ route('syllabus.builder') }}',
                '01_Web_Admin/03_giao_chang_cho_giao_vien': '{{ route('syllabus.assignments') }}',
                '03_Cong_Giao_Vien/08_xem_tai_lieu_giao_trinh': '{{ route('syllabus.teacher-view') }}',
                '03_Cong_Giao_Vien/09_de_xuat_sua_giao_trinh': '{{ route('syllabus.teacher-propose') }}',
                '01_Web_Admin/05_chi_tiet_de_xuat_sua_giao_trinh': '{{ route('syllabus.versions') }}',
                '03_Cong_Giao_Vien/14_xin_dieu_chinh_tien_do': '{{ route('syllabus.teacher-adjust') }}',
                '01_Web_Admin/04_duyet_yeu_cau_dieu_chinh_tien_do': '{{ route('syllabus.adjustment-requests') }}',
                // Flow 3: Giáo viên chấm bài
                '03_Cong_Giao_Vien/04_bai_nop_cua_lop': '{{ route('portal.teacher.submissions') }}',
                // Flow 4: Trải nghiệm Học sinh & Phụ huynh (7 bước chuẩn BA)
                '04_Cong_Phu_Huynh_Hoc_Sinh/01_app_shell_phu_huynh_hoc_sinh': '{{ route('portal.app-shell') }}',
                '04_Cong_Phu_Huynh_Hoc_Sinh/02_trang_chu_phu_huynh_hoc_sinh': '{{ route('portal.student.home') }}',
                '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap': '{{ route('portal.student.homework') }}',
                '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am': '{{ route('portal.student.pronunciation') }}',
                '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao': '{{ route('portal.student.notifications') }}',
                '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat': '{{ route('portal.student.survey') }}',
                '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback': '{{ route('portal.student.feedback') }}',
            };

            return {
                nativeMap: nativeMap,
                screens: allScreens,
                filteredScreens: allScreens,
                search: '',
                currentCat: initialCat || 'all',
                modalOpen: false,
                activeScreen: null,
                categories: [
                    { id: 'all', name: 'Tất cả', icon: 'apps', count: allScreens.length },
                    { id: '01_Web_Admin', name: 'Web Admin', icon: 'admin_panel_settings', count: allScreens.filter(s => s.category_id === '01_Web_Admin').length },
                    { id: '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu', name: 'Học thuật & Học vụ & KPI', icon: 'monitoring', count: allScreens.filter(s => s.category_id === '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu').length },
                    { id: '03_Cong_Giao_Vien', name: 'Cổng Giáo Viên', icon: 'co_present', count: allScreens.filter(s => s.category_id === '03_Cong_Giao_Vien').length },
                    { id: '04_Cong_Phu_Huynh_Hoc_Sinh', name: 'Cổng Phụ Huynh', icon: 'family_restroom', count: allScreens.filter(s => s.category_id === '04_Cong_Phu_Huynh_Hoc_Sinh').length }
                ],
                init() {
                    this.filterScreens();
                },
                setCategory(catId) {
                    this.currentCat = catId;
                    this.filterScreens();
                },
                filterScreens() {
                    const q = this.search.trim().toLowerCase();
                    this.filteredScreens = this.screens.filter(s => {
                        const matchCat = this.currentCat === 'all' || s.category_id === this.currentCat;
                        const matchText = !q || s.title_vn.toLowerCase().includes(q) || s.desc.toLowerCase().includes(q) || s.folder_name.toLowerCase().includes(q);
                        return matchCat && matchText;
                    });
                },
                openModal(screen) {
                    this.activeScreen = screen;
                    this.modalOpen = true;
                }
            };
        }
    </script>
</body>
</html>
