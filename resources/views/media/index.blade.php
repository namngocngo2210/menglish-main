<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black text-gray-900 tracking-tight flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-primary text-2xl">folder_managed</span>
                    <span>Quản lý Media &amp; Tệp tin lưu trữ</span>
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">
                    Gom nhóm, tạo thư mục và kéo thả tệp tin như Google Drive trên đĩa cứng hệ thống
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <span class="text-xs text-gray-500 bg-gray-100 px-3 py-1.5 rounded-xl font-medium hidden md:flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Đĩa cứng: <strong>{{ $stats['total_size_human'] }}</strong> / {{ $stats['total_files'] }} tệp</span>
                </span>

                <!-- Button Tạo Thư Mục Mới -->
                <button 
                    type="button" 
                    @click="folderModal.open = true" 
                    class="px-3.5 py-1.5 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 text-xs font-bold rounded-xl shadow-2xs transition flex items-center gap-1.5 cursor-pointer"
                >
                    <span class="material-symbols-outlined text-base text-primary-container">create_new_folder</span>
                    <span>Tạo thư mục mới</span>
                </button>

                <!-- Button Kéo Thả / Tải Lên -->
                <button 
                    type="button" 
                    @click="uploadCardOpen = !uploadCardOpen" 
                    class="px-3.5 py-1.5 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5 cursor-pointer"
                >
                    <span class="material-symbols-outlined text-base" x-text="uploadCardOpen ? 'expand_less' : 'cloud_upload'"></span>
                    <span x-text="uploadCardOpen ? 'Đóng tải lên' : 'Kéo thả tải tệp'"></span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" x-data="mediaManager()">
        <!-- 0. Drag & Drop File Upload Zone Card -->
        <div 
            x-show="uploadCardOpen" 
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="bg-white rounded-3xl border border-orange-200/80 shadow-sm p-6 space-y-4"
        >
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-3 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-orange-50 text-primary-container flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-lg">cloud_upload</span>
                    </span>
                    <div>
                        <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Kéo thả &amp; Tải lên tệp tin mới</h3>
                        <p class="text-[11px] text-gray-500">Tự động tối ưu hóa và phân loại tệp tin trên cây thư mục hệ thống</p>
                    </div>
                </div>

                <!-- Target Folder Selector -->
                <div class="flex items-center gap-2 text-xs">
                    <span class="text-gray-500 font-medium">Lưu vào thư mục:</span>
                    <select 
                        x-model="targetFolder" 
                        class="text-xs font-bold rounded-xl border border-gray-200 py-1.5 px-3 focus:ring-1 focus:ring-primary-container focus:border-primary-container bg-gray-50"
                    >
                        <option value="{{ $currentFolder ?: 'auto_date' }}">
                            📁 {{ $currentFolder ? 'Thư mục hiện tại (uploads/' . $currentFolder . ')' : 'uploads/' . date('Y') . '/' . date('m') . ' (Theo ngày tháng năm)' }}
                        </option>
                        <option value="auto_date">📁 uploads/{{ date('Y') }}/{{ date('m') }} (Theo ngày tháng năm)</option>
                        <option value="tickets">📁 uploads/tickets/ (Ảnh ticket báo lỗi &amp; hỗ trợ)</option>
                        <option value="avatars">📁 uploads/avatars/ (Ảnh đại diện người dùng)</option>
                        <option value="courses">📁 uploads/courses/ (Tài liệu khóa học &amp; giáo trình)</option>
                        <option value="documents">📁 uploads/documents/ (Tài liệu chung &amp; hợp đồng)</option>
                        <option value="marketing">📁 uploads/marketing/ (Banner &amp; truyền thông)</option>
                        @foreach ($subFolders as $sf)
                            <option value="{{ $sf['path'] }}">📁 uploads/{{ $sf['path'] }}/</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Drag and Drop Dropzone -->
            <input 
                type="file" 
                x-ref="fileInput" 
                multiple 
                @change="handleFilesSelect($event)" 
                class="hidden" 
            />

            <div 
                @dragover.prevent.stop="isDragging = true"
                @dragleave.prevent.stop="isDragging = false"
                @drop.prevent.stop="handleFilesDrop($event)"
                class="border-2 border-dashed rounded-2xl p-8 text-center transition-all duration-200 flex flex-col items-center justify-center space-y-3"
                :class="isDragging ? 'border-primary-container bg-orange-50/80 scale-[1.01]' : 'border-gray-300 bg-gray-50/50 hover:bg-orange-50/20 hover:border-primary-container/60'"
            >
                <div class="w-14 h-14 rounded-2xl bg-orange-100 text-primary-container flex items-center justify-center shadow-xs transition transform cursor-pointer" @click="$refs.fileInput.click()" :class="isDragging ? 'scale-110' : ''">
                    <span class="material-symbols-outlined text-3xl">upload_file</span>
                </div>

                <div class="space-y-1">
                    <p class="text-sm font-bold text-gray-900">
                        <span class="text-primary-container">Kéo thả tệp tin vào đây</span> hoặc 
                        <button type="button" @click.stop="$refs.fileInput.click()" class="text-primary-container underline hover:text-primary font-bold cursor-pointer inline">
                            chọn tệp từ máy tính
                        </button>
                    </p>
                    <p class="text-[11px] text-gray-500">
                        Hỗ trợ đa dạng: <span class="font-semibold text-gray-700">Audio (MP3/WAV), Word (DOCX), PDF, Excel, Hình ảnh, Video, ZIP</span> (Tối đa 100MB/tệp)
                    </p>
                </div>

                <!-- Upload Progress & Status Bar -->
                <div x-show="isUploading" x-cloak class="w-full max-w-md space-y-2 pt-2" @click.stop>
                    <div class="flex items-center justify-between text-xs font-bold text-gray-700">
                        <span x-text="uploadStatusText"></span>
                        <span class="font-mono text-primary-container" x-text="uploadProgress + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                        <div class="bg-primary-container h-full rounded-full transition-all duration-200" :style="`width: ${uploadProgress}%`"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 1. Google Drive Breadcrumbs & Navigation Bar -->
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-xs p-4 flex flex-wrap items-center justify-between gap-3">
            <!-- Breadcrumbs Trail -->
            <div class="flex items-center gap-1.5 text-xs font-bold overflow-x-auto py-1">
                @foreach ($breadcrumbs as $index => $bc)
                    @if ($index > 0)
                        <span class="text-gray-300 material-symbols-outlined text-sm">chevron_right</span>
                    @endif

                    @if ($loop->last && $currentFolder !== '')
                        <span class="px-2.5 py-1 rounded-xl bg-orange-50 text-primary-container border border-orange-200 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">folder_open</span>
                            <span>{{ $bc['name'] }}</span>
                        </span>
                    @else
                        <a 
                            href="{{ route('media.index', $bc['path'] ? ['folder' => $bc['path']] : []) }}" 
                            class="px-2.5 py-1 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition flex items-center gap-1"
                        >
                            <span class="material-symbols-outlined text-sm text-gray-400">{{ $index === 0 ? 'home' : 'folder' }}</span>
                            <span>{{ $bc['name'] }}</span>
                        </a>
                    @endif
                @endforeach
            </div>

            <!-- Quick Action: Tạo thư mục con trong thư mục này -->
            <button 
                type="button" 
                @click="folderModal.open = true" 
                class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition flex items-center gap-1 shrink-0"
            >
                <span class="material-symbols-outlined text-sm text-primary-container">add</span>
                <span>Tạo thư mục con</span>
            </button>
        </div>

        <!-- 2. Folder Explorer Grid (Google Drive Folders) -->
        @if ($subFolders->isNotEmpty())
            <div class="space-y-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">folder</span>
                    <span>Thư mục ({{ $subFolders->count() }})</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                    @foreach ($subFolders as $sf)
                        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-xs hover:shadow-md hover:border-primary-container/40 transition p-3.5 flex flex-col justify-between group relative">
                            <a href="{{ route('media.index', ['folder' => $sf['path']]) }}" class="block space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="w-10 h-10 rounded-xl bg-orange-50 text-primary-container flex items-center justify-center group-hover:scale-105 transition">
                                        <span class="material-symbols-outlined text-2xl">folder</span>
                                    </div>

                                    <!-- Delete folder button -->
                                    <button 
                                        type="button" 
                                        @click.prevent.stop="confirmDeleteFolder('{{ $sf['path'] }}', '{{ $sf['name'] }}', {{ $sf['files_count'] }})"
                                        class="p-1 rounded-lg text-gray-300 hover:text-rose-600 hover:bg-rose-50 opacity-0 group-hover:opacity-100 transition"
                                        title="Xóa thư mục"
                                    >
                                        <span class="material-symbols-outlined text-sm">delete</span>
                                    </button>
                                </div>

                                <div>
                                    <h4 class="text-xs font-bold text-gray-900 group-hover:text-primary-container transition truncate" title="{{ $sf['name'] }}">
                                        {{ $sf['name'] }}
                                    </h4>
                                    <p class="text-[10px] text-gray-400 font-mono mt-0.5">
                                        {{ $sf['files_count'] }} tệp · {{ $sf['total_size_human'] }}
                                    </p>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- 3. KPI Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <!-- Total Files -->
            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Tổng số tệp tin</span>
                    <div class="text-xl font-black text-gray-900 mt-1 font-mono">{{ number_format($stats['total_files']) }}</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">description</span>
                </div>
            </div>

            <!-- Total Size -->
            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Tổng dung lượng</span>
                    <div class="text-xl font-black text-gray-900 mt-1 font-mono text-primary-container">{{ $stats['total_size_human'] }}</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-orange-50 text-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">hard_drive</span>
                </div>
            </div>

            <!-- Images Size -->
            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Hình ảnh ({{ $stats['images_count'] }})</span>
                    <div class="text-xl font-black text-emerald-700 mt-1 font-mono">{{ $stats['images_size'] }}</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">image</span>
                </div>
            </div>

            <!-- Documents Size -->
            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Tài liệu &amp; Excel</span>
                    <div class="text-xl font-black text-indigo-700 mt-1 font-mono">{{ $stats['docs_size'] }}</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">article</span>
                </div>
            </div>
        </div>

        <!-- 4. Filter & Search Bar -->
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-sm p-4">
            <form method="GET" action="{{ route('media.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                @if ($currentFolder)
                    <input type="hidden" name="folder" value="{{ $currentFolder }}">
                @endif

                <!-- Search -->
                <div class="lg:col-span-2">
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Tìm kiếm tên tệp</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2 text-gray-400 text-base">search</span>
                        <input 
                            type="text" 
                            name="search" 
                            value="{{ $filters['search'] }}" 
                            placeholder="Nhập tên tệp tin hoặc đường dẫn..." 
                            class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container"
                        />
                    </div>
                </div>

                <!-- File Type -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Loại tệp</label>
                    <select name="type" class="w-full text-xs rounded-xl border border-gray-200 py-1.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                        <option value="all" {{ $filters['type'] === 'all' ? 'selected' : '' }}>Tất cả loại tệp</option>
                        <option value="image" {{ $filters['type'] === 'image' ? 'selected' : '' }}>🖼️ Hình ảnh</option>
                        <option value="document" {{ $filters['type'] === 'document' ? 'selected' : '' }}>📄 Tài liệu (PDF/Doc)</option>
                        <option value="spreadsheet" {{ $filters['type'] === 'spreadsheet' ? 'selected' : '' }}>📊 Bảng tính (Excel)</option>
                        <option value="audio" {{ $filters['type'] === 'audio' ? 'selected' : '' }}>🎧 Âm thanh (Audio/MP3)</option>
                        <option value="video" {{ $filters['type'] === 'video' ? 'selected' : '' }}>🎬 Video</option>
                        <option value="other" {{ $filters['type'] === 'other' ? 'selected' : '' }}>📁 Khác</option>
                    </select>
                </div>

                <!-- Directory / Folder -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Thư mục lưu trữ</label>
                    <select name="directory" class="w-full text-xs rounded-xl border border-gray-200 py-1.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                        <option value="all" {{ $filters['directory'] === 'all' ? 'selected' : '' }}>Tất cả thư mục</option>
                        @foreach ($directories as $dir)
                            <option value="{{ $dir }}" {{ $filters['directory'] === $dir ? 'selected' : '' }}>📁 {{ $dir }}/</option>
                        @endforeach
                    </select>
                </div>

                <!-- Size Range -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Kích thước</label>
                    <select name="size_range" class="w-full text-xs rounded-xl border border-gray-200 py-1.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                        <option value="all" {{ $filters['size_range'] === 'all' ? 'selected' : '' }}>Mọi kích thước</option>
                        <option value="lt_1mb" {{ $filters['size_range'] === 'lt_1mb' ? 'selected' : '' }}>Dưới 1 MB</option>
                        <option value="1mb_10mb" {{ $filters['size_range'] === '1mb_10mb' ? 'selected' : '' }}>1 MB — 10 MB</option>
                        <option value="gt_10mb" {{ $filters['size_range'] === 'gt_10mb' ? 'selected' : '' }}>Trên 10 MB</option>
                    </select>
                </div>

                <!-- Date Range -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Thời gian tải lên</label>
                    <select name="date_range" class="w-full text-xs rounded-xl border border-gray-200 py-1.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                        <option value="all" {{ $filters['date_range'] === 'all' ? 'selected' : '' }}>Tất cả thời gian</option>
                        <option value="today" {{ $filters['date_range'] === 'today' ? 'selected' : '' }}>Hôm nay</option>
                        <option value="last_7_days" {{ $filters['date_range'] === 'last_7_days' ? 'selected' : '' }}>7 ngày trước</option>
                        <option value="last_30_days" {{ $filters['date_range'] === 'last_30_days' ? 'selected' : '' }}>30 ngày trước</option>
                        <option value="this_month" {{ $filters['date_range'] === 'this_month' ? 'selected' : '' }}>Tháng này</option>
                    </select>
                </div>

                <!-- Submit buttons -->
                <div class="lg:col-span-6 flex items-center justify-between pt-2 border-t border-gray-100 mt-1">
                    <div class="text-xs text-gray-500">
                        Kết quả lọc: <strong class="text-gray-900">{{ number_format($totalFilteredCount) }}</strong> tệp 
                        (<strong class="text-primary-container font-mono">{{ $totalFilteredSize }}</strong>)
                    </div>

                    <div class="flex items-center gap-2">
                        <a href="{{ route('media.index', $currentFolder ? ['folder' => $currentFolder] : []) }}" class="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-xl transition">
                            Đặt lại
                        </a>
                        <button type="submit" class="px-4 py-1.5 text-xs font-bold text-white bg-primary-container hover:bg-primary rounded-xl shadow-xs transition flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">filter_alt</span>
                            <span>Lọc tệp</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- 5. Actions Toolbar & Bulk Operations -->
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-sm p-3.5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <!-- Select All Checkbox -->
                <label class="flex items-center gap-2 text-xs font-bold text-gray-700 cursor-pointer select-none">
                    <input 
                        type="checkbox" 
                        @change="toggleSelectAll($event)" 
                        :checked="isAllSelected"
                        class="rounded text-primary-container focus:ring-primary-container border-gray-300 w-4 h-4 cursor-pointer"
                    />
                    <span>Chọn tất cả trang này</span>
                </label>

                <span class="text-gray-300">|</span>

                <!-- Bulk Delete Selected Files -->
                <form 
                    action="{{ route('media.bulk-destroy') }}" 
                    method="POST" 
                    id="bulkDeleteForm"
                    @submit.prevent="confirmBulkDelete()"
                >
                    @csrf
                    @method('DELETE')
                    <template x-for="id in selectedFiles" :key="id">
                        <input type="hidden" name="selected_files[]" :value="id">
                    </template>

                    <button 
                        type="submit" 
                        :disabled="selectedFiles.length === 0"
                        class="px-3.5 py-1.5 text-xs font-bold rounded-xl transition flex items-center gap-1.5 shadow-xs"
                        :class="selectedFiles.length > 0 ? 'bg-rose-600 hover:bg-rose-700 text-white cursor-pointer' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                    >
                        <span class="material-symbols-outlined text-[16px]">delete_sweep</span>
                        <span>Xóa các tệp (<span x-text="selectedFiles.length"></span>)</span>
                    </button>
                </form>

                <!-- Bulk Move Selected Files -->
                <button 
                    type="button" 
                    :disabled="selectedFiles.length === 0"
                    @click="openMoveModal()"
                    class="px-3.5 py-1.5 text-xs font-bold rounded-xl transition flex items-center gap-1.5 shadow-xs"
                    :class="selectedFiles.length > 0 ? 'bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 cursor-pointer' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                >
                    <span class="material-symbols-outlined text-[16px]">drive_file_move</span>
                    <span>Di chuyển vào thư mục</span>
                </button>

                <!-- Clean Up by Filter (Xóa toàn bộ theo bộ lọc) -->
                @if ($totalFilteredCount > 0)
                    <button 
                        type="button" 
                        @click="confirmCleanFiltered()"
                        class="px-3.5 py-1.5 text-xs font-bold bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl transition flex items-center gap-1.5 cursor-pointer shadow-xs"
                        title="Xóa vĩnh viễn toàn bộ {{ $totalFilteredCount }} tệp tin khớp bộ lọc khỏi đĩa"
                    >
                        <span class="material-symbols-outlined text-[16px]">delete_forever</span>
                        <span>Xóa theo bộ lọc ({{ $totalFilteredCount }} tệp)</span>
                    </button>
                @endif
            </div>

            <!-- View Mode Switch (Grid vs Table) -->
            <div class="flex items-center gap-1 bg-gray-100 p-1 rounded-xl border border-gray-200">
                <button 
                    type="button" 
                    @click="viewMode = 'grid'" 
                    class="p-1.5 rounded-lg text-xs font-semibold transition"
                    :class="viewMode === 'grid' ? 'bg-white text-primary-container shadow-xs font-bold' : 'text-gray-600 hover:text-gray-900'"
                    title="Chế độ xem lưới (Grid)"
                >
                    <span class="material-symbols-outlined text-[18px]">grid_view</span>
                </button>
                <button 
                    type="button" 
                    @click="viewMode = 'table'" 
                    class="p-1.5 rounded-lg text-xs font-semibold transition"
                    :class="viewMode === 'table' ? 'bg-white text-primary-container shadow-xs font-bold' : 'text-gray-600 hover:text-gray-900'"
                    title="Chế độ xem bảng (Table)"
                >
                    <span class="material-symbols-outlined text-[18px]">table_rows</span>
                </button>
            </div>
        </div>

        <!-- 6. Files List View -->
        @if ($files->isEmpty())
            @if ($subFolders->isNotEmpty())
                <div class="bg-gray-50/80 rounded-3xl border border-dashed border-gray-200 p-8 text-center">
                    <p class="text-xs font-semibold text-gray-500">
                        Thư mục này gồm <strong>{{ $subFolders->count() }} thư mục con</strong> ở trên. Hãy bấm vào một thư mục để xem tệp hoặc kéo thả tệp tin mới vào đây.
                    </p>
                </div>
            @else
                <div class="bg-white rounded-3xl border border-gray-200 p-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-orange-50 text-primary-container mx-auto flex items-center justify-center mb-3">
                        <span class="material-symbols-outlined text-3xl">folder_off</span>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900">Không tìm thấy tệp tin nào trong thư mục này</h3>
                    <p class="text-xs text-gray-500 mt-1">Hãy thử kéo thả tệp lên trên hoặc chuyển sang thư mục khác.</p>
                    <a href="{{ route('media.index') }}" class="mt-4 inline-flex items-center gap-1 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-xs font-bold transition">
                        <span class="material-symbols-outlined text-base">home</span>
                        <span>Về thư mục gốc</span>
                    </a>
                </div>
            @endif
        @else
            <!-- 6A. Grid Card View -->
            <div x-show="viewMode === 'grid'" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                @foreach ($files as $file)
                    <div class="bg-white rounded-2xl border border-gray-200/90 shadow-sm hover:shadow-md hover:border-primary-container/50 transition flex flex-col justify-between overflow-hidden group relative">
                        <!-- Top Bar / Checkbox & Actions -->
                        <div class="p-2.5 flex items-center justify-between bg-gray-50/70 border-b border-gray-100">
                            <label class="cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    value="{{ $file['id'] }}" 
                                    x-model="selectedFiles" 
                                    class="file-checkbox rounded text-primary-container focus:ring-primary-container border-gray-300 w-3.5 h-3.5 cursor-pointer"
                                />
                            </label>

                            <span class="text-[10px] font-mono uppercase font-extrabold px-1.5 py-0.5 rounded bg-gray-200/70 text-gray-700">
                                {{ $file['extension'] }}
                            </span>
                        </div>

                        <!-- Center Thumbnail / Preview -->
                        <div class="p-3 flex items-center justify-center bg-gray-100/40 min-h-[110px] relative overflow-hidden">
                            @if ($file['is_image'])
                                <img 
                                    src="{{ $file['url'] }}" 
                                    alt="{{ $file['filename'] }}" 
                                    class="max-h-24 w-auto object-contain rounded-lg shadow-2xs group-hover:scale-105 transition duration-200 cursor-pointer"
                                    @click="openPreview('{{ $file['url'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}', 'image')"
                                    loading="lazy"
                                />
                            @elseif ($file['type'] === 'audio')
                                <div 
                                    @click="openPreview('{{ $file['url'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}', 'audio')"
                                    class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 hover:bg-amber-100 flex flex-col items-center justify-center cursor-pointer transition shadow-2xs group-hover:scale-105"
                                    title="Bấm để nghe tệp âm thanh"
                                >
                                    <span class="material-symbols-outlined text-2xl">headphones</span>
                                    <span class="text-[9px] font-bold uppercase tracking-wider mt-0.5">MP3</span>
                                </div>
                            @else
                                <div class="w-12 h-12 rounded-xl flex items-center justify-center 
                                    {{ $file['type'] === 'document' ? 'bg-blue-50 text-blue-600' : '' }}
                                    {{ $file['type'] === 'spreadsheet' ? 'bg-emerald-50 text-emerald-600' : '' }}
                                    {{ $file['type'] === 'video' ? 'bg-purple-50 text-purple-600' : '' }}
                                    {{ $file['type'] === 'other' ? 'bg-gray-100 text-gray-600' : '' }}
                                ">
                                    @if ($file['type'] === 'document')
                                        <span class="material-symbols-outlined text-2xl">description</span>
                                    @elseif ($file['type'] === 'spreadsheet')
                                        <span class="material-symbols-outlined text-2xl">table_chart</span>
                                    @elseif ($file['type'] === 'video')
                                        <span class="material-symbols-outlined text-2xl">movie</span>
                                    @else
                                        <span class="material-symbols-outlined text-2xl">draft</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <!-- Bottom File Info -->
                        <div class="p-2.5 space-y-1 bg-white border-t border-gray-100 text-left">
                            <h4 class="text-xs font-bold text-gray-900 truncate" title="{{ $file['filename'] }}">
                                {{ $file['filename'] }}
                            </h4>

                            <div class="flex items-center justify-between text-[10px] text-gray-400 font-mono">
                                <span class="text-gray-600 font-bold">{{ $file['size_human'] }}</span>
                                <span class="truncate max-w-[75px]" title="{{ $file['directory'] }}">📁 {{ $file['directory'] }}</span>
                            </div>

                            <div class="text-[9px] text-gray-400 pt-0.5">
                                {{ $file['created_at_human'] }}
                            </div>

                            <!-- Fast Action Toolbar -->
                            <div class="pt-2 border-t border-gray-100 flex items-center justify-between gap-1">
                                <button 
                                    type="button" 
                                    @click="copyUrl('{{ $file['url'] }}')" 
                                    class="p-1 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-900 transition cursor-pointer"
                                    title="Sao chép URL"
                                >
                                    <span class="material-symbols-outlined text-sm">link</span>
                                </button>

                                <a 
                                    href="{{ route('media.download', $file['id']) }}" 
                                    class="p-1 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-900 transition"
                                    title="Tải về máy"
                                >
                                    <span class="material-symbols-outlined text-sm">download</span>
                                </a>

                                <button 
                                    type="button" 
                                    @click="confirmSingleDelete('{{ $file['id'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}')" 
                                    class="p-1 rounded-lg hover:bg-rose-50 text-gray-400 hover:text-rose-600 transition cursor-pointer"
                                    title="Xóa vĩnh viễn"
                                >
                                    <span class="material-symbols-outlined text-sm">delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- 6B. Table View -->
            <div x-show="viewMode === 'table'" class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200 text-[11px]">
                        <tr>
                            <th class="p-3.5 w-10 text-center">
                                <input 
                                    type="checkbox" 
                                    @change="toggleSelectAll($event)" 
                                    :checked="isAllSelected"
                                    class="rounded text-primary-container focus:ring-primary-container border-gray-300 w-3.5 h-3.5 cursor-pointer"
                                />
                            </th>
                            <th class="p-3.5">Tên tệp tin</th>
                            <th class="p-3.5">Thư mục</th>
                            <th class="p-3.5">Loại tệp</th>
                            <th class="p-3.5 text-right">Dung lượng</th>
                            <th class="p-3.5">Thời gian tạo</th>
                            <th class="p-3.5 text-center">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700">
                        @foreach ($files as $file)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="p-3.5 text-center">
                                    <input 
                                        type="checkbox" 
                                        value="{{ $file['id'] }}" 
                                        x-model="selectedFiles" 
                                        class="rounded text-primary-container focus:ring-primary-container border-gray-300 w-3.5 h-3.5 cursor-pointer"
                                    />
                                </td>
                                <td class="p-3.5 font-medium">
                                    <div class="flex items-center gap-2.5">
                                        @if ($file['is_image'])
                                            <img src="{{ $file['url'] }}" class="w-7 h-7 object-cover rounded-lg shrink-0 cursor-pointer border" @click="openPreview('{{ $file['url'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}', 'image')" />
                                        @elseif ($file['type'] === 'audio')
                                            <span class="material-symbols-outlined text-amber-500 text-lg cursor-pointer hover:scale-110 transition" @click="openPreview('{{ $file['url'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}', 'audio')" title="Nghe audio">headphones</span>
                                        @else
                                            <span class="material-symbols-outlined text-gray-400 text-base">draft</span>
                                        @endif
                                        <span class="font-bold text-gray-900 truncate max-w-xs" title="{{ $file['filename'] }}">{{ $file['filename'] }}</span>
                                    </div>
                                </td>
                                <td class="p-3.5 font-mono text-gray-500 text-[11px]">📁 {{ $file['directory'] }}</td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase
                                        {{ $file['type'] === 'image' ? 'bg-emerald-50 text-emerald-700' : '' }}
                                        {{ $file['type'] === 'document' ? 'bg-blue-50 text-blue-700' : '' }}
                                        {{ $file['type'] === 'spreadsheet' ? 'bg-emerald-50 text-emerald-700' : '' }}
                                        {{ $file['type'] === 'audio' ? 'bg-amber-50 text-amber-700' : '' }}
                                        {{ $file['type'] === 'video' ? 'bg-purple-50 text-purple-700' : '' }}
                                        {{ $file['type'] === 'other' ? 'bg-gray-100 text-gray-700' : '' }}
                                    ">
                                        {{ $file['type'] }} ({{ $file['extension'] }})
                                    </span>
                                </td>
                                <td class="p-3.5 text-right font-mono font-bold text-gray-900">{{ $file['size_human'] }}</td>
                                <td class="p-3.5 text-gray-400 text-[11px]">{{ $file['created_at_human'] }}</td>
                                <td class="p-3.5 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button type="button" @click="copyUrl('{{ $file['url'] }}')" class="p-1 rounded-lg hover:bg-gray-100 text-gray-500 cursor-pointer" title="Copy URL">
                                            <span class="material-symbols-outlined text-sm">link</span>
                                        </button>
                                        <a href="{{ route('media.download', $file['id']) }}" class="p-1 rounded-lg hover:bg-gray-100 text-gray-500" title="Tải về">
                                            <span class="material-symbols-outlined text-sm">download</span>
                                        </a>
                                        <button type="button" @click="confirmSingleDelete('{{ $file['id'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}')" class="p-1 rounded-lg hover:bg-rose-50 text-rose-600 cursor-pointer" title="Xóa">
                                            <span class="material-symbols-outlined text-sm">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination Links -->
            <div class="mt-4">
                {{ $files->links() }}
            </div>
        @endif

        <!-- Forms for Delete & Move -->
        <form id="singleDeleteForm" method="POST" action="" class="hidden">
            @csrf
            @method('DELETE')
        </form>

        <form id="deleteFolderForm" method="POST" action="{{ route('media.delete-folder') }}" class="hidden">
            @csrf
            @method('DELETE')
            <input type="hidden" name="folder_path" id="deleteFolderPath">
        </form>

        <form id="cleanFilteredForm" method="POST" action="{{ route('media.destroy-filtered', request()->query()) }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>

        <!-- 7. Modal Tạo Thư Mục Mới (Google Drive Style) -->
        <div 
            x-show="folderModal.open" 
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
            @click="folderModal.open = false"
        >
            <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4" @click.stop>
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary-container">create_new_folder</span>
                        <span>Tạo thư mục mới</span>
                    </h3>
                    <button type="button" @click="folderModal.open = false" class="text-gray-400 hover:text-gray-600 p-1">
                        <span class="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>

                <form action="{{ route('media.create-folder') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="parent_folder" value="{{ $currentFolder }}">

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Tên thư mục <span class="text-rose-500">*</span></label>
                        <input 
                            type="text" 
                            name="folder_name" 
                            required 
                            placeholder="Ví dụ: hop_dong_2026, anh_su_kien..." 
                            class="w-full text-xs font-semibold rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container"
                            autofocus
                        />
                        <p class="text-[10px] text-gray-400 mt-1">
                            Vị trí tạo: <strong class="font-mono text-gray-700">/uploads/{{ $currentFolder ? $currentFolder . '/' : '' }}</strong>
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
                        <button type="button" @click="folderModal.open = false" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50 transition">
                            Hủy
                        </button>
                        <button type="submit" class="px-5 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition">
                            Tạo thư mục
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 8. Modal Di Chuyển Tệp (Move Files Modal) -->
        <div 
            x-show="moveModal.open" 
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
            @click="moveModal.open = false"
        >
            <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4" @click.stop>
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary-container">drive_file_move</span>
                        <span>Di chuyển <span x-text="selectedFiles.length"></span> tệp tin</span>
                    </h3>
                    <button type="button" @click="moveModal.open = false" class="text-gray-400 hover:text-gray-600 p-1">
                        <span class="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>

                <form action="{{ route('media.move-files') }}" method="POST" class="space-y-4">
                    @csrf
                    <template x-for="id in selectedFiles" :key="id">
                        <input type="hidden" name="selected_files[]" :value="id">
                    </template>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Chọn thư mục đích <span class="text-rose-500">*</span></label>
                        <select 
                            name="target_folder" 
                            required 
                            class="w-full text-xs font-semibold rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container"
                        >
                            <option value="">📁 /uploads (Thư mục gốc)</option>
                            <option value="{{ date('Y') }}/{{ date('m') }}">📁 /uploads/{{ date('Y') }}/{{ date('m') }}</option>
                            <option value="tickets">📁 /uploads/tickets</option>
                            <option value="avatars">📁 /uploads/avatars</option>
                            <option value="courses">📁 /uploads/courses</option>
                            <option value="documents">📁 /uploads/documents</option>
                            <option value="marketing">📁 /uploads/marketing</option>
                            @foreach ($subFolders as $sf)
                                <option value="{{ $sf['path'] }}">📁 /uploads/{{ $sf['path'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
                        <button type="button" @click="moveModal.open = false" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50 transition">
                            Hủy
                        </button>
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition">
                            Di chuyển ngay
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 9. Image / Audio Preview Lightbox Modal -->
        <div 
            x-show="preview.open" 
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-xs p-4"
            @click="preview.open = false"
        >
            <div class="bg-white rounded-2xl max-w-3xl w-full overflow-hidden shadow-2xl" @click.stop>
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                    <div>
                        <h4 class="text-xs font-bold text-gray-900 truncate max-w-md" x-text="preview.name"></h4>
                        <span class="text-[10px] text-gray-400 font-mono" x-text="preview.size"></span>
                    </div>
                    <button type="button" @click="preview.open = false" class="text-gray-400 hover:text-gray-600 p-1">
                        <span class="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>
                <div class="p-4 bg-gray-950 flex items-center justify-center min-h-[160px] max-h-[75vh] overflow-hidden">
                    <template x-if="preview.type === 'image'">
                        <img :src="preview.url" :alt="preview.name" class="max-h-[70vh] max-w-full object-contain rounded-lg">
                    </template>
                    <template x-if="preview.type === 'audio'">
                        <div class="w-full max-w-md p-6 bg-gray-900 rounded-2xl flex flex-col items-center space-y-4">
                            <span class="material-symbols-outlined text-5xl text-amber-400 animate-pulse">headphones</span>
                            <p class="text-xs text-white font-mono truncate text-center w-full" x-text="preview.name"></p>
                            <audio :src="preview.url" controls class="w-full" autoplay></audio>
                        </div>
                    </template>
                </div>
                <div class="p-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-2 text-xs">
                    <button type="button" @click="copyUrl(preview.url)" class="px-3 py-1.5 rounded-xl border bg-white text-gray-700 font-semibold hover:bg-gray-100 transition">
                        Sao chép Link
                    </button>
                    <a :href="preview.url" target="_blank" class="px-3 py-1.5 rounded-xl bg-primary-container text-white font-bold hover:bg-primary transition">
                        Mở tệp gốc
                    </a>
                </div>
            </div>
        </div>

        <!-- 10. Action Toast -->
        <div 
            x-show="toast.show" 
            x-cloak
            x-transition
            class="fixed bottom-6 right-6 z-50 flex items-center gap-2 px-4 py-2.5 rounded-2xl shadow-xl bg-gray-900 text-white text-xs font-semibold"
        >
            <span class="material-symbols-outlined text-base text-emerald-400">check_circle</span>
            <span x-text="toast.message"></span>
        </div>
    </div>

    <script>
        function mediaManager() {
            return {
                viewMode: 'grid',
                selectedFiles: [],
                pageFileIds: @json($files->pluck('id')->all()),
                uploadCardOpen: true,
                isDragging: false,
                isUploading: false,
                uploadProgress: 0,
                uploadStatusText: '',
                targetFolder: '{{ $currentFolder ?: "auto_date" }}',
                folderModal: {
                    open: false,
                },
                moveModal: {
                    open: false,
                },
                preview: {
                    open: false,
                    url: '',
                    name: '',
                    size: '',
                    type: 'image'
                },
                toast: {
                    show: false,
                    message: ''
                },

                get isAllSelected() {
                    return this.pageFileIds.length > 0 && this.pageFileIds.every(id => this.selectedFiles.includes(id));
                },

                toggleSelectAll(event) {
                    if (event.target.checked) {
                        this.selectedFiles = [...new Set([...this.selectedFiles, ...this.pageFileIds])];
                    } else {
                        this.selectedFiles = this.selectedFiles.filter(id => !this.pageFileIds.includes(id));
                    }
                },

                openMoveModal() {
                    if (this.selectedFiles.length === 0) return;
                    this.moveModal.open = true;
                },

                handleFilesDrop(e) {
                    this.isDragging = false;
                    const dt = e.dataTransfer;
                    if (dt && dt.files && dt.files.length > 0) {
                        this.uploadFileList(dt.files);
                    }
                },

                handleFilesSelect(e) {
                    const files = e.target.files;
                    if (files && files.length > 0) {
                        this.uploadFileList(files);
                    }
                },

                uploadFileList(fileList) {
                    if (!fileList || fileList.length === 0) return;
                    if (this.isUploading) return; // Prevent double trigger!

                    const formData = new FormData();
                    for (let i = 0; i < fileList.length; i++) {
                        formData.append('files[]', fileList[i]);
                    }
                    formData.append('folder', this.targetFolder);

                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = '';
                    }

                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                    this.isUploading = true;
                    this.uploadProgress = 0;
                    this.uploadStatusText = `Đang chuẩn bị tải lên ${fileList.length} tệp...`;

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '{{ route('media.upload') }}', true);
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                    xhr.setRequestHeader('Accept', 'application/json');

                    xhr.upload.onprogress = (e) => {
                        if (e.lengthComputable) {
                            this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                            this.uploadStatusText = `Đang tải lên ${fileList.length} tệp (${this.uploadProgress}%)...`;
                        }
                    };

                    xhr.onload = () => {
                        this.isUploading = false;
                        if (xhr.status >= 200 && xhr.status < 300) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                this.showToast(response.message || `Tải lên thành công ${fileList.length} tệp tin!`);
                            } catch(e) {
                                this.showToast(`Tải lên thành công ${fileList.length} tệp tin!`);
                            }
                            setTimeout(() => {
                                window.location.href = window.location.href;
                            }, 500);
                        } else {
                            try {
                                const err = JSON.parse(xhr.responseText);
                                let msg = '';
                                if (err.errors) {
                                    msg = Object.values(err.errors).flat().join('\n');
                                } else {
                                    msg = err.message || 'Vui lòng kiểm tra dung lượng và định dạng tệp!';
                                }
                                alert('Lỗi tải lên:\n' + msg);
                            } catch(e) {
                                alert('Lỗi tải lên tệp tin. Vui lòng thử lại!');
                            }
                        }
                    };

                    xhr.onerror = () => {
                        this.isUploading = false;
                        alert('Lỗi kết nối mạng trong quá trình tải tệp.');
                    };

                    xhr.send(formData);
                },

                openPreview(url, name, size, type = 'image') {
                    this.preview.url = url;
                    this.preview.name = name;
                    this.preview.size = size;
                    this.preview.type = type;
                    this.preview.open = true;
                },

                copyUrl(url) {
                    navigator.clipboard.writeText(url).then(() => {
                        this.showToast('Đã sao chép đường dẫn tệp!');
                    });
                },

                showToast(msg) {
                    this.toast.message = msg;
                    this.toast.show = true;
                    setTimeout(() => { this.toast.show = false; }, 2500);
                },

                confirmDeleteFolder(path, name, count) {
                    if (confirm(`CẢNH BÁO: Bạn có chắc muốn XÓA THƯ MỤC '${name}' (${count} tệp bên trong) khỏi đĩa cứng vật lý không?\n\nToàn bộ tệp tin bên trong thư mục này sẽ bị xóa sạch!`)) {
                        document.getElementById('deleteFolderPath').value = path;
                        document.getElementById('deleteFolderForm').submit();
                    }
                },

                confirmSingleDelete(id, name, size) {
                    if (confirm(`CẢNH BÁO: Bạn có chắc chắn muốn XÓA VĨNH VIỄN tệp tin '${name}' (${size}) khỏi đĩa cứng vật lý không?\n\nHành động này không thể hoàn tác!`)) {
                        const form = document.getElementById('singleDeleteForm');
                        form.action = `/media/${id}`;
                        form.submit();
                    }
                },

                confirmBulkDelete() {
                    const count = this.selectedFiles.length;
                    if (count === 0) return;

                    if (confirm(`CẢNH BÁO NGUY HIỂM:\nBạn đang yêu cầu XÓA VĨNH VIỄN ${count} tệp tin đã chọn khỏi ĐĨA CỨNG VẬT LÝ của máy chủ.\n\nBạn có chắc chắn muốn thực hiện?`)) {
                        document.getElementById('bulkDeleteForm').submit();
                    }
                },

                confirmCleanFiltered() {
                    const count = {{ $totalFilteredCount }};
                    const size = "{{ $totalFilteredSize }}";

                    if (confirm(`CẢNH BÁO DỌN DẸP DUNG LƯỢNG:\nBạn có chắc muốn XÓA VĨNH VIỄN TẤT CẢ ${count} tệp tin (${size}) khớp với bộ lọc hiện tại khỏi đĩa cứng không?\n\nToàn bộ các file này sẽ bị xóa sạch trên máy chủ và không thể khôi phục!`)) {
                        document.getElementById('cleanFilteredForm').submit();
                    }
                }
            };
        }
    </script>
</x-app-layout>
