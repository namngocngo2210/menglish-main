<x-app-layout>
    <div class="space-y-5" x-data="mediaManager()">
        <x-ui.page-header title="Quản lý Media & Tệp tin lưu trữ" icon="folder_managed" description="Gom nhóm, tạo thư mục và kéo thả tệp tin như Google Drive trên đĩa cứng hệ thống">
            <x-slot:actions>
                <span class="text-xs text-on-surface-variant bg-surface-container px-3 py-1.5 rounded-xl font-medium hidden md:flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-tertiary animate-pulse"></span>
                    <span>Đĩa cứng: <strong>{{ $stats['total_size_human'] }}</strong> / {{ $stats['total_files'] }} tệp</span>
                </span>

                {{-- Button Tạo Thư Mục Mới --}}
                <x-ui.button variant="secondary" icon="create_new_folder" x-on:click="$dispatch('open-modal', 'media-folder')">Tạo thư mục mới</x-ui.button>

                {{-- Button Kéo Thả / Tải Lên --}}
                <x-ui.button x-on:click="uploadCardOpen = !uploadCardOpen">
                    <span class="material-symbols-outlined text-[18px]" x-text="uploadCardOpen ? 'expand_less' : 'cloud_upload'"></span>
                    <span x-text="uploadCardOpen ? 'Đóng tải lên' : 'Kéo thả tải tệp'"></span>
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- 0. Drag & Drop File Upload Zone Card --}}
        <div 
            x-show="uploadCardOpen" 
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="bg-surface-container-lowest rounded-3xl border border-primary-container/30 shadow-sm p-6 space-y-4"
        >
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-3 border-b border-surface-container-highest">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-primary-container/10 text-primary-container flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-lg">cloud_upload</span>
                    </span>
                    <div>
                        <h3 class="text-xs font-bold text-on-surface uppercase tracking-wider">Kéo thả &amp; Tải lên tệp tin mới</h3>
                        <p class="text-[11px] text-on-surface-variant">Tự động tối ưu hóa và phân loại tệp tin trên cây thư mục hệ thống</p>
                    </div>
                </div>

                {{-- Target Folder Selector --}}
                <x-ui.select x-model="targetFolder" inline-label="Lưu vào thư mục:" aria-label="Lưu vào thư mục" class="py-xs font-bold">
                    <option value="{{ $currentFolder ?: 'auto_date' }}">
                        📁 {{ $currentFolder ? 'Thư mục hiện tại (uploads/media/' . $currentFolder . ')' : 'uploads/media/' . date('Y') . '/' . date('m') . ' (Theo ngày tháng năm)' }}
                    </option>
                    <option value="auto_date">📁 uploads/media/{{ date('Y') }}/{{ date('m') }} (Theo ngày tháng năm)</option>
                    <option value="tickets">📁 uploads/media/tickets/ (Ảnh ticket báo lỗi &amp; hỗ trợ)</option>
                    <option value="avatars">📁 uploads/media/avatars/ (Ảnh đại diện người dùng)</option>
                    <option value="courses">📁 uploads/media/courses/ (Tài liệu khóa học &amp; giáo trình)</option>
                    <option value="documents">📁 uploads/media/documents/ (Tài liệu chung &amp; hợp đồng)</option>
                    <option value="marketing">📁 uploads/media/marketing/ (Banner &amp; truyền thông)</option>
                    @foreach ($subFolders as $sf)
                        <option value="{{ $sf['path'] }}">📁 uploads/media/{{ $sf['path'] }}/</option>
                    @endforeach
                </x-ui.select>
            </div>

            {{-- Drag and Drop Dropzone --}}
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
                :class="isDragging ? 'border-primary-container bg-primary-container/10 scale-[1.01]' : 'border-outline-variant bg-surface-container-low/50 hover:bg-primary-container/10 hover:border-primary-container/60'"
            >
                <div class="w-14 h-14 rounded-2xl bg-primary-container/10 text-primary-container flex items-center justify-center shadow-xs transition transform cursor-pointer" @click="$refs.fileInput.click()" :class="isDragging ? 'scale-110' : ''">
                    <span class="material-symbols-outlined text-3xl">upload_file</span>
                </div>

                <div class="space-y-1">
                    <p class="text-sm font-bold text-on-surface">
                        <span class="text-primary-container">Kéo thả tệp tin vào đây</span> hoặc 
                        <button type="button" @click.stop="$refs.fileInput.click()" class="text-primary-container underline hover:text-primary font-bold cursor-pointer inline">
                            chọn tệp từ máy tính
                        </button>
                    </p>
                    <p class="text-[11px] text-on-surface-variant">
                        Hỗ trợ đa dạng: <span class="font-semibold text-on-surface-variant">Audio (MP3/WAV), Word (DOCX), PDF, Excel, Hình ảnh, Video, ZIP</span> (Tối đa 100MB/tệp)
                    </p>
                </div>

                {{-- Upload Progress & Status Bar --}}
                <div x-show="isUploading" x-cloak class="w-full max-w-md space-y-2 pt-2" @click.stop>
                    <div class="flex items-center justify-between text-xs font-bold text-on-surface-variant">
                        <span x-text="uploadStatusText"></span>
                        <span class="font-code text-primary-container" x-text="uploadProgress + '%'"></span>
                    </div>
                    <div class="w-full bg-surface-container-high h-2 rounded-full overflow-hidden">
                        <div class="bg-primary-container h-full rounded-full transition-all duration-200" :style="`width: ${uploadProgress}%`"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 1. Google Drive Breadcrumbs & Navigation Bar --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/90 shadow-xs p-4 flex flex-wrap items-center justify-between gap-3">
            {{-- Breadcrumbs Trail --}}
            <div class="flex items-center gap-1.5 text-xs font-bold overflow-x-auto py-1">
                @foreach ($breadcrumbs as $index => $bc)
                    @if ($index > 0)
                        <span class="text-on-surface-variant/70 material-symbols-outlined text-sm">chevron_right</span>
                    @endif

                    @if ($loop->last && $currentFolder !== '')
                        <span class="px-2.5 py-1 rounded-xl bg-primary-container/10 text-primary-container border border-primary-container/30 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">folder_open</span>
                            <span>{{ $bc['name'] }}</span>
                        </span>
                    @else
                        <a 
                            href="{{ route('media.index', $bc['path'] ? ['folder' => $bc['path']] : []) }}" 
                            class="px-2.5 py-1 rounded-xl text-on-surface-variant hover:text-on-surface hover:bg-surface-container transition flex items-center gap-1"
                        >
                            <span class="material-symbols-outlined text-sm text-on-surface-variant/70">{{ $index === 0 ? 'home' : 'folder' }}</span>
                            <span>{{ $bc['name'] }}</span>
                        </a>
                    @endif
                @endforeach
            </div>

            {{-- Quick Action: Tạo thư mục con trong thư mục này --}}
            <x-ui.button variant="secondary" size="sm" icon="add" x-on:click="$dispatch('open-modal', 'media-folder')">Tạo thư mục con</x-ui.button>
        </div>

        {{-- 2. Folder Explorer Grid (Google Drive Folders) --}}
        @if ($subFolders->isNotEmpty())
            <div class="space-y-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">folder</span>
                    <span>Thư mục ({{ $subFolders->count() }})</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                    @foreach ($subFolders as $sf)
                        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/90 shadow-xs hover:shadow-md hover:border-primary-container/40 transition p-3.5 flex flex-col justify-between group relative">
                            <a href="{{ route('media.index', ['folder' => $sf['path']]) }}" class="block space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="w-10 h-10 rounded-xl bg-primary-container/10 text-primary-container flex items-center justify-center group-hover:scale-105 transition">
                                        <span class="material-symbols-outlined text-2xl">folder</span>
                                    </div>

                                    {{-- Delete folder button --}}
                                    <x-ui.button variant="danger-text" size="sm" icon="delete" title="Xóa thư mục" aria-label="Xóa thư mục"
                                        x-on:click.prevent.stop="confirmDeleteFolder('{{ $sf['path'] }}', '{{ $sf['name'] }}', {{ $sf['files_count'] }})"
                                        class="opacity-0 group-hover:opacity-100 focus:opacity-100" />
                                </div>

                                <div>
                                    <h4 class="text-xs font-bold text-on-surface group-hover:text-primary-container transition truncate" title="{{ $sf['name'] }}">
                                        {{ $sf['name'] }}
                                    </h4>
                                    <p class="text-[10px] text-on-surface-variant/70 font-code mt-0.5">
                                        {{ $sf['files_count'] }} tệp · {{ $sf['total_size_human'] }}
                                    </p>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 3. KPI Summary Cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <x-ui.stat-card label="Tổng số tệp tin" :value="number_format($stats['total_files'])" icon="description" tone="secondary" />
            <x-ui.stat-card label="Tổng dung lượng" :value="$stats['total_size_human']" icon="hard_drive" tone="primary" />
            <x-ui.stat-card :label="'Hình ảnh ('.$stats['images_count'].')'" :value="$stats['images_size']" icon="image" tone="success" />
            <x-ui.stat-card label="Tài liệu & Excel" :value="$stats['docs_size']" icon="article" tone="secondary" />
        </div>

        {{-- 4. Filter & Search Bar --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/90 shadow-sm p-4">
            <form method="GET" action="{{ route('media.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                @if ($currentFolder)
                    <input type="hidden" name="folder" value="{{ $currentFolder }}">
                @endif

                {{-- Search --}}
                <div class="lg:col-span-2">
                    <x-ui.input name="search" label="Tìm kiếm tên tệp" icon="search" :value="$filters['search']" placeholder="Nhập tên tệp tin hoặc đường dẫn..." />
                </div>

                {{-- File Type --}}
                <x-ui.select name="type" label="Loại tệp" :value="$filters['type']" :options="[
                    'all' => 'Tất cả loại tệp', 'image' => '🖼️ Hình ảnh', 'document' => '📄 Tài liệu (PDF/Doc)', 'spreadsheet' => '📊 Bảng tính (Excel)',
                    'audio' => '🎧 Âm thanh (Audio/MP3)', 'video' => '🎬 Video', 'other' => '📁 Khác',
                ]" />

                {{-- Directory / Folder --}}
                <x-ui.select name="directory" label="Thư mục lưu trữ" :value="$filters['directory']"
                             :options="['all' => 'Tất cả thư mục'] + collect($directories)->mapWithKeys(fn ($dir) => [$dir => '📁 '.$dir.'/'])->all()" />

                {{-- Size Range --}}
                <x-ui.select name="size_range" label="Kích thước" :value="$filters['size_range']"
                             :options="['all' => 'Mọi kích thước', 'lt_1mb' => 'Dưới 1 MB', '1mb_10mb' => '1 MB — 10 MB', 'gt_10mb' => 'Trên 10 MB']" />

                {{-- Date Range --}}
                <x-ui.select name="date_range" label="Thời gian tải lên" :value="$filters['date_range']"
                             :options="['all' => 'Tất cả thời gian', 'today' => 'Hôm nay', 'last_7_days' => '7 ngày trước', 'last_30_days' => '30 ngày trước', 'this_month' => 'Tháng này']" />

                {{-- Submit buttons --}}
                <div class="lg:col-span-6 flex items-center justify-between pt-2 border-t border-surface-container-highest mt-1">
                    <div class="text-xs text-on-surface-variant">
                        Kết quả lọc: <strong class="text-on-surface">{{ number_format($totalFilteredCount) }}</strong> tệp 
                        (<strong class="text-primary-container font-code">{{ $totalFilteredSize }}</strong>)
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button variant="secondary" size="sm" :href="route('media.index', $currentFolder ? ['folder' => $currentFolder] : [])">Đặt lại</x-ui.button>
                        <x-ui.button type="submit" size="sm" icon="filter_alt">Lọc tệp</x-ui.button>
                    </div>
                </div>
            </form>
        </div>

        {{-- 5. Actions Toolbar & Bulk Operations --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/90 shadow-sm p-3.5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                {{-- Select All Checkbox --}}
                <label class="flex items-center gap-2 text-xs font-bold text-on-surface-variant cursor-pointer select-none">
                    <input 
                        type="checkbox" 
                        @change="toggleSelectAll($event)" 
                        :checked="isAllSelected"
                        class="rounded text-primary-container focus:ring-primary-container border-outline-variant w-4 h-4 cursor-pointer"
                    />
                    <span>Chọn tất cả trang này</span>
                </label>

                <span class="text-on-surface-variant/70">|</span>

                {{-- Bulk Delete Selected Files --}}
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

                    <x-ui.button type="submit" variant="danger" size="sm" icon="delete_sweep" x-bind:disabled="selectedFiles.length === 0">
                        <span>Xóa các tệp (<span x-text="selectedFiles.length"></span>)</span>
                    </x-ui.button>
                </form>

                {{-- Bulk Move Selected Files --}}
                <x-ui.button variant="secondary" size="sm" icon="drive_file_move" x-bind:disabled="selectedFiles.length === 0" x-on:click="openMoveModal()">Di chuyển vào thư mục</x-ui.button>

                {{-- Clean Up by Filter (Xóa toàn bộ theo bộ lọc) --}}
                @if ($totalFilteredCount > 0)
                    <x-ui.button variant="danger-text" size="sm" icon="delete_forever" x-on:click="confirmCleanFiltered()"
                        title="Xóa vĩnh viễn toàn bộ {{ $totalFilteredCount }} tệp tin khớp bộ lọc khỏi đĩa">Xóa theo bộ lọc ({{ $totalFilteredCount }} tệp)</x-ui.button>
                @endif
            </div>

            {{-- View Mode Switch (Grid vs Table) --}}
            <div class="flex items-center gap-1 bg-surface-container p-1 rounded-xl border border-surface-container-highest">
                <button 
                    type="button" 
                    @click="viewMode = 'grid'" 
                    class="p-1.5 rounded-lg text-xs font-semibold transition"
                    :class="viewMode === 'grid' ? 'bg-surface-container-lowest text-primary-container shadow-xs font-bold' : 'text-on-surface-variant hover:text-on-surface'"
                    title="Chế độ xem lưới (Grid)"
                >
                    <span class="material-symbols-outlined text-[18px]">grid_view</span>
                </button>
                <button 
                    type="button" 
                    @click="viewMode = 'table'" 
                    class="p-1.5 rounded-lg text-xs font-semibold transition"
                    :class="viewMode === 'table' ? 'bg-surface-container-lowest text-primary-container shadow-xs font-bold' : 'text-on-surface-variant hover:text-on-surface'"
                    title="Chế độ xem bảng (Table)"
                >
                    <span class="material-symbols-outlined text-[18px]">table_rows</span>
                </button>
            </div>
        </div>

        {{-- 6. Files List View --}}
        @if ($files->isEmpty())
            @if ($subFolders->isNotEmpty())
                <div class="bg-surface-container-low/80 rounded-3xl border border-dashed border-surface-container-highest p-8 text-center">
                    <p class="text-xs font-semibold text-on-surface-variant">
                        Thư mục này gồm <strong>{{ $subFolders->count() }} thư mục con</strong> ở trên. Hãy bấm vào một thư mục để xem tệp hoặc kéo thả tệp tin mới vào đây.
                    </p>
                </div>
            @else
                <div class="rounded-3xl border border-surface-container-highest bg-surface-container-lowest">
                    <x-ui.empty-state icon="folder_off" title="Không tìm thấy tệp tin nào trong thư mục này" description="Hãy thử kéo thả tệp lên trên hoặc chuyển sang thư mục khác.">
                        <x-ui.button variant="secondary" icon="home" :href="route('media.index')">Về thư mục gốc</x-ui.button>
                    </x-ui.empty-state>
                </div>
            @endif
        @else
            {{-- 6A. Grid Card View --}}
            <div x-show="viewMode === 'grid'" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                @foreach ($files as $file)
                    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/90 shadow-sm hover:shadow-md hover:border-primary-container/50 transition flex flex-col justify-between overflow-hidden group relative">
                        {{-- Top Bar / Checkbox & Actions --}}
                        <div class="p-2.5 flex items-center justify-between bg-surface-container-low/70 border-b border-surface-container-highest">
                            <label class="cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    value="{{ $file['id'] }}" 
                                    x-model="selectedFiles" 
                                    class="file-checkbox rounded text-primary-container focus:ring-primary-container border-outline-variant w-3.5 h-3.5 cursor-pointer"
                                />
                            </label>

                            <span class="text-[10px] font-code uppercase font-extrabold px-1.5 py-0.5 rounded bg-surface-container-high/70 text-on-surface-variant">
                                {{ $file['extension'] }}
                            </span>
                        </div>

                        {{-- Center Thumbnail / Preview --}}
                        <div class="p-3 flex items-center justify-center bg-surface-container/40 min-h-[110px] relative overflow-hidden">
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
                                    class="w-14 h-14 rounded-2xl bg-warning-container text-warning hover:bg-warning-container flex flex-col items-center justify-center cursor-pointer transition shadow-2xs group-hover:scale-105"
                                    title="Bấm để nghe tệp âm thanh"
                                >
                                    <span class="material-symbols-outlined text-2xl">headphones</span>
                                    <span class="text-[9px] font-bold uppercase tracking-wider mt-0.5">MP3</span>
                                </div>
                            @else
                                <div class="w-12 h-12 rounded-xl flex items-center justify-center 
                                    {{ $file['type'] === 'document' ? 'bg-secondary/10 text-secondary' : '' }}
                                    {{ $file['type'] === 'spreadsheet' ? 'bg-tertiary/10 text-tertiary' : '' }}
                                    {{ $file['type'] === 'video' ? 'bg-purple-50 text-purple-600' : '' }}
                                    {{ $file['type'] === 'other' ? 'bg-surface-container text-on-surface-variant' : '' }}
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

                        {{-- Bottom File Info --}}
                        <div class="p-2.5 space-y-1 bg-surface-container-lowest border-t border-surface-container-highest text-left">
                            <h4 class="text-xs font-bold text-on-surface truncate" title="{{ $file['filename'] }}">
                                {{ $file['filename'] }}
                            </h4>

                            <div class="flex items-center justify-between text-[10px] text-on-surface-variant/70 font-code">
                                <span class="text-on-surface-variant font-bold">{{ $file['size_human'] }}</span>
                                <span class="truncate max-w-[75px]" title="{{ $file['directory'] }}">📁 {{ $file['directory'] }}</span>
                            </div>

                            <div class="text-[9px] text-on-surface-variant/70 pt-0.5">
                                {{ $file['created_at_human'] }}
                            </div>

                            {{-- Fast Action Toolbar --}}
                            <div class="pt-2 border-t border-surface-container-highest flex items-center justify-between gap-1">
                                <x-ui.button variant="ghost" size="sm" icon="link" x-on:click="copyUrl('{{ $file['url'] }}')" title="Sao chép URL" aria-label="Sao chép URL" />
                                <x-ui.button variant="ghost" size="sm" icon="download" :href="route('media.download', $file['id'])" title="Tải về máy" aria-label="Tải về máy" />
                                <x-ui.button variant="danger-text" size="sm" icon="delete" x-on:click="confirmSingleDelete('{{ $file['id'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}')" title="Xóa vĩnh viễn" aria-label="Xóa vĩnh viễn" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- 6B. Table View --}}
            <x-ui.data-table x-show="viewMode === 'table'" class="shadow-sm">
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th class="w-10 text-center">
                                <input 
                                    type="checkbox" 
                                    @change="toggleSelectAll($event)" 
                                    :checked="isAllSelected"
                                    aria-label="Chọn tất cả trang này"
                                    class="rounded text-primary-container focus:ring-primary-container border-outline-variant w-3.5 h-3.5 cursor-pointer"
                                />
                            </th>
                            <th>Tên tệp tin</th>
                            <th>Thư mục</th>
                            <th>Loại tệp</th>
                            <th class="text-right">Dung lượng</th>
                            <th>Thời gian tạo</th>
                            <th class="text-center">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr>
                                <td class="text-center">
                                    <input 
                                        type="checkbox" 
                                        value="{{ $file['id'] }}" 
                                        x-model="selectedFiles" 
                                        class="rounded text-primary-container focus:ring-primary-container border-outline-variant w-3.5 h-3.5 cursor-pointer"
                                    />
                                </td>
                                <td class="font-medium">
                                    <div class="flex items-center gap-2.5">
                                        @if ($file['is_image'])
                                            <img src="{{ $file['url'] }}" class="w-7 h-7 object-cover rounded-lg shrink-0 cursor-pointer border" @click="openPreview('{{ $file['url'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}', 'image')" />
                                        @elseif ($file['type'] === 'audio')
                                            <span class="material-symbols-outlined text-warning text-lg cursor-pointer hover:scale-110 transition" @click="openPreview('{{ $file['url'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}', 'audio')" title="Nghe audio">headphones</span>
                                        @else
                                            <span class="material-symbols-outlined text-on-surface-variant/70 text-base">draft</span>
                                        @endif
                                        <span class="font-bold text-on-surface truncate max-w-xs" title="{{ $file['filename'] }}">{{ $file['filename'] }}</span>
                                    </div>
                                </td>
                                <td class="font-code text-on-surface-variant text-[11px]">📁 {{ $file['directory'] }}</td>
                                <td>
                                    <x-ui.badge :pill="true" :dot="false" class="uppercase" :color="['image' => 'success', 'document' => 'info', 'spreadsheet' => 'success', 'audio' => 'warning', 'video' => 'secondary'][$file['type']] ?? 'neutral'">
                                        {{ $file['type'] }} ({{ $file['extension'] }})
                                    </x-ui.badge>
                                </td>
                                <td class="text-right font-code font-bold">{{ $file['size_human'] }}</td>
                                <td class="text-on-surface-variant/70 text-[11px]">{{ $file['created_at_human'] }}</td>
                                <td class="text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <x-ui.button variant="ghost" size="sm" icon="link" x-on:click="copyUrl('{{ $file['url'] }}')" title="Copy URL" aria-label="Copy URL" />
                                        <x-ui.button variant="ghost" size="sm" icon="download" :href="route('media.download', $file['id'])" title="Tải về" aria-label="Tải về" />
                                        <x-ui.button variant="danger-text" size="sm" icon="delete" x-on:click="confirmSingleDelete('{{ $file['id'] }}', '{{ $file['filename'] }}', '{{ $file['size_human'] }}')" title="Xóa" aria-label="Xóa" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

            {{-- Pagination Links --}}
            <x-ui.pagination :paginator="$files" :options="[]" unit="tệp" class="mt-4" />
        @endif

        {{-- Forms for Delete & Move --}}
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

        {{-- 7. Modal Tạo Thư Mục Mới (Google Drive Style) --}}
        <x-ui.modal name="media-folder" title="Tạo thư mục mới" max-width="md">
            <form id="media-folder-form" action="{{ route('media.create-folder') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="parent_folder" value="{{ $currentFolder }}">

                <div class="space-y-1">
                    <x-ui.input name="folder_name" label="Tên thư mục" required placeholder="Ví dụ: hop_dong_2026, anh_su_kien..." class="font-semibold" autofocus />
                    <p class="text-[10px] text-on-surface-variant/70">
                        Vị trí tạo: <strong class="font-code text-on-surface-variant">/uploads/media/{{ $currentFolder ? $currentFolder . '/' : '' }}</strong>
                    </p>
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'media-folder')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="media-folder-form">Tạo thư mục</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- 8. Modal Di Chuyển Tệp (Move Files Modal) --}}
        <x-ui.modal name="media-move" title="Di chuyển vào thư mục" max-width="md">
            <form id="media-move-form" action="{{ route('media.move-files') }}" method="POST" class="space-y-4">
                @csrf
                <template x-for="id in selectedFiles" :key="id">
                    <input type="hidden" name="selected_files[]" :value="id">
                </template>

                <p class="font-semibold text-on-surface">Di chuyển <span x-text="selectedFiles.length"></span> tệp tin</p>

                <x-ui.select name="target_folder" label="Chọn thư mục đích" required placeholder="📁 /uploads/media (Thư mục gốc)" class="font-semibold">
                    <option value="{{ date('Y') }}/{{ date('m') }}">📁 /uploads/{{ date('Y') }}/{{ date('m') }}</option>
                    <option value="tickets">📁 /uploads/tickets</option>
                    <option value="avatars">📁 /uploads/avatars</option>
                    <option value="courses">📁 /uploads/courses</option>
                    <option value="documents">📁 /uploads/documents</option>
                    <option value="marketing">📁 /uploads/marketing</option>
                    @foreach ($subFolders as $sf)
                        <option value="{{ $sf['path'] }}">📁 /uploads/{{ $sf['path'] }}</option>
                    @endforeach
                </x-ui.select>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'media-move')">Hủy</x-ui.button>
                <x-ui.button type="submit" variant="info" form="media-move-form">Di chuyển ngay</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- 9. Image / Audio Preview Lightbox Modal --}}
        <div 
            x-show="preview.open" 
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-xs p-4"
            @click="preview.open = false"
        >
            <div class="bg-surface-container-lowest rounded-2xl max-w-3xl w-full overflow-hidden shadow-2xl" @click.stop>
                <div class="px-5 py-3 border-b border-surface-container-highest flex items-center justify-between bg-surface-container-low">
                    <div>
                        <h4 class="text-xs font-bold text-on-surface truncate max-w-md" x-text="preview.name"></h4>
                        <span class="text-[10px] text-on-surface-variant/70 font-code" x-text="preview.size"></span>
                    </div>
                    <button type="button" @click="preview.open = false" class="text-on-surface-variant/70 hover:text-on-surface-variant p-1">
                        <span class="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>
                <div class="p-4 bg-on-surface flex items-center justify-center min-h-[160px] max-h-[75vh] overflow-hidden">
                    <template x-if="preview.type === 'image'">
                        <img :src="preview.url" :alt="preview.name" class="max-h-[70vh] max-w-full object-contain rounded-lg">
                    </template>
                    <template x-if="preview.type === 'audio'">
                        <div class="w-full max-w-md p-6 bg-on-surface rounded-2xl flex flex-col items-center space-y-4">
                            <span class="material-symbols-outlined text-5xl text-warning animate-pulse">headphones</span>
                            <p class="text-xs text-white font-code truncate text-center w-full" x-text="preview.name"></p>
                            <audio :src="preview.url" controls class="w-full" autoplay></audio>
                        </div>
                    </template>
                </div>
                <div class="p-3 bg-surface-container-low border-t border-surface-container-highest flex justify-end gap-2 text-xs">
                    <x-ui.button variant="secondary" size="sm" x-on:click="copyUrl(preview.url)">Sao chép Link</x-ui.button>
                    <a :href="preview.url" target="_blank" class="px-3 py-1.5 rounded-xl bg-primary-container text-white font-bold hover:bg-primary transition">
                        Mở tệp gốc
                    </a>
                </div>
            </div>
        </div>

        {{-- 10. Action Toast --}}
        <div 
            x-show="toast.show" 
            x-cloak
            x-transition
            class="fixed bottom-6 right-6 z-50 flex items-center gap-2 px-4 py-2.5 rounded-2xl shadow-xl bg-on-surface text-white text-xs font-semibold"
        >
            <span class="material-symbols-outlined text-base text-tertiary">check_circle</span>
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
                    this.$dispatch('open-modal', 'media-move');
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
                                window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Lỗi tải lên: ' + msg, type: 'error' } }));
                            } catch(e) {
                                window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Lỗi tải lên tệp tin. Vui lòng thử lại!', type: 'error' } }));
                            }
                        }
                    };

                    xhr.onerror = () => {
                        this.isUploading = false;
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Lỗi kết nối mạng trong quá trình tải tệp.', type: 'error' } }));
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
