<script setup>
/**
 * Quản lý Media & tệp tin (duyệt thư mục kiểu Google Drive): kéo thả tải tệp (XHR, có tiến trình), tạo / xóa thư mục,
 * lọc tệp, xem lưới / bảng, chọn nhiều để xóa hoặc di chuyển, dọn theo bộ lọc, xem trước ảnh / âm thanh.
 */
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/lib/confirm';
import { toast as appToast } from '@/lib/toast';
import { copyText } from '@/lib/clipboard';
import { csrfToken } from '@/lib/http';
import { currentQuery } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Quản lý Media & Tệp tin lưu trữ' } });

const props = defineProps({
    files: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    directories: { type: Array, default: () => [] },
    subFolders: { type: Array, default: () => [] },
    currentFolder: { type: String, default: '' },
    breadcrumbs: { type: Array, default: () => [] },
    totalFilteredCount: { type: Number, default: 0 },
    totalFilteredSize: { type: String, default: '' },
    datedFolder: { type: String, default: '' },
});

const viewMode = ref('grid');
const selectedFiles = ref([]);
const uploadCardOpen = ref(true);
const isDragging = ref(false);
const isUploading = ref(false);
const uploadProgress = ref(0);
const uploadStatusText = ref('');
const targetFolder = ref(props.currentFolder || 'auto_date');
const fileInput = ref(null);
const folderOpen = ref(false);
const moveOpen = ref(false);
const preview = reactive({ open: false, url: '', name: '', size: '', type: 'image' });

const pageFileIds = computed(() => props.files.data.map((f) => f.id));
const isAllSelected = computed(() => pageFileIds.value.length > 0 && pageFileIds.value.every((id) => selectedFiles.value.includes(id)));
const typeOptions = [
    { value: 'all', label: 'Tất cả loại tệp' }, { value: 'image', label: 'Hình ảnh' }, { value: 'document', label: 'Tài liệu (PDF/Doc)' },
    { value: 'spreadsheet', label: 'Bảng tính (Excel)' }, { value: 'audio', label: 'Âm thanh (Audio/MP3)' }, { value: 'video', label: 'Video' }, { value: 'other', label: 'Khác' },
];
const directoryOptions = computed(() => [{ value: 'all', label: 'Tất cả thư mục' }, ...props.directories.map((dir) => ({ value: dir, label: `${dir}/` }))]);
const sizeOptions = [
    { value: 'all', label: 'Mọi kích thước' }, { value: 'lt_1mb', label: 'Dưới 1 MB' }, { value: '1mb_10mb', label: '1 MB — 10 MB' }, { value: 'gt_10mb', label: 'Trên 10 MB' },
];
const dateOptions = [
    { value: 'all', label: 'Tất cả thời gian' }, { value: 'today', label: 'Hôm nay' }, { value: 'last_7_days', label: '7 ngày trước' },
    { value: 'last_30_days', label: '30 ngày trước' }, { value: 'this_month', label: 'Tháng này' },
];
const presetFolders = [
    ['tickets', 'Ảnh ticket báo lỗi & hỗ trợ'],
    ['avatars', 'Ảnh đại diện người dùng'],
    ['courses', 'Tài liệu khóa học & giáo trình'],
    ['documents', 'Tài liệu chung & hợp đồng'],
    ['marketing', 'Banner & truyền thông'],
];
const badgeColor = (type) => ({ image: 'success', document: 'info', spreadsheet: 'success', audio: 'warning', video: 'secondary' })[type] ?? 'neutral';
const iconTone = (type) => ({ document: 'bg-secondary/10 text-secondary', spreadsheet: 'bg-tertiary/10 text-tertiary', video: 'bg-accent-container text-accent', other: 'bg-surface-container text-on-surface-variant' })[type] ?? '';
const typeIcon = (type) => ({ document: 'description', spreadsheet: 'table_chart', video: 'movie' })[type] ?? 'draft';

function toggleSelectAll(event) {
    if (event.target.checked) selectedFiles.value = [...new Set([...selectedFiles.value, ...pageFileIds.value])];
    else selectedFiles.value = selectedFiles.value.filter((id) => !pageFileIds.value.includes(id));
}

function openMoveModal() {
    if (selectedFiles.value.length === 0) return;
    moveOpen.value = true;
}

function handleFilesDrop(e) {
    isDragging.value = false;
    const files = e.dataTransfer?.files;
    if (files && files.length > 0) uploadFileList(files);
}

function handleFilesSelect(e) {
    const files = e.target.files;
    if (files && files.length > 0) uploadFileList(files);
}

// Tải lên bằng XHR (JSON) để hiện tiến trình; xong tải lại dữ liệu trang.
function uploadFileList(fileList) {
    if (!fileList || fileList.length === 0 || isUploading.value) return;
    const formData = new FormData();
    for (const file of fileList) formData.append('files[]', file);
    formData.append('folder', targetFolder.value);
    if (fileInput.value) fileInput.value.value = '';

    isUploading.value = true;
    uploadProgress.value = 0;
    uploadStatusText.value = `Đang chuẩn bị tải lên ${fileList.length} tệp...`;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', route('media.upload'), true);
    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            uploadProgress.value = Math.round((e.loaded / e.total) * 100);
            uploadStatusText.value = `Đang tải lên ${fileList.length} tệp (${uploadProgress.value}%)...`;
        }
    };
    xhr.onload = () => {
        isUploading.value = false;
        if (xhr.status >= 200 && xhr.status < 300) {
            let message = `Tải lên thành công ${fileList.length} tệp tin!`;
            try {
                message = JSON.parse(xhr.responseText).message || message;
            } catch {
                // giữ thông báo mặc định
            }
            appToast(message);
            router.reload({ preserveScroll: true });
        } else {
            let message = 'Lỗi tải lên tệp tin. Vui lòng thử lại!';
            try {
                const err = JSON.parse(xhr.responseText);
                message = 'Lỗi tải lên: ' + (err.errors ? Object.values(err.errors).flat().join('\n') : err.message || 'Vui lòng kiểm tra dung lượng và định dạng tệp!');
            } catch {
                // giữ thông báo mặc định
            }
            appToast(message, 'error');
        }
    };
    xhr.onerror = () => {
        isUploading.value = false;
        appToast('Lỗi kết nối mạng trong quá trình tải tệp.', 'error');
    };
    xhr.send(formData);
}

function openPreview(file, type = 'image') {
    Object.assign(preview, { url: file.url, name: file.filename, size: file.size_human, type, open: true });
}

function copyUrl(url) {
    return copyText(url, 'Đã sao chép đường dẫn tệp!');
}

const visitOptions = { preserveScroll: true, onSuccess: () => (selectedFiles.value = []) };

async function confirmDeleteFolder(folder) {
    if (await confirmDialog({ title: 'Xóa thư mục?', message: `Thư mục “${folder.name}” và ${folder.files_count} tệp bên trong sẽ bị xóa khỏi máy chủ.\nKhông thể khôi phục.`, confirmLabel: 'Xóa thư mục', danger: true })) {
        router.delete(route('media.delete-folder'), { data: { folder_path: folder.path }, ...visitOptions });
    }
}

async function confirmSingleDelete(file) {
    if (await confirmDialog({ title: 'Xóa vĩnh viễn tệp?', message: `Tệp “${file.filename}” (${file.size_human}) sẽ bị xóa khỏi máy chủ.\nKhông thể khôi phục.`, confirmLabel: 'Xóa tệp', danger: true })) {
        router.delete(route('media.destroy', file.id), visitOptions);
    }
}

async function confirmBulkDelete() {
    const count = selectedFiles.value.length;
    if (count === 0) return;
    if (await confirmDialog({ title: `Xóa vĩnh viễn ${count} tệp đã chọn?`, message: 'Các tệp sẽ bị xóa khỏi máy chủ.\nKhông thể khôi phục.', confirmLabel: 'Xóa tệp', danger: true })) {
        router.delete(route('media.bulk-destroy'), { data: { selected_files: selectedFiles.value }, ...visitOptions });
    }
}

async function confirmCleanFiltered() {
    if (await confirmDialog({ title: 'Dọn dung lượng?', message: `Toàn bộ ${props.totalFilteredCount} tệp (${props.totalFilteredSize}) khớp bộ lọc hiện tại sẽ bị xóa khỏi máy chủ.\nKhông thể khôi phục.`, confirmLabel: 'Xóa tất cả', danger: true })) {
        router.delete(route('media.destroy-filtered', Object.fromEntries(currentQuery())), visitOptions);
    }
}

function onMoved() {
    moveOpen.value = false;
    selectedFiles.value = [];
}
</script>

<template>
    <div class="space-y-5">
        <UiPageHeader title="Quản lý Media & Tệp tin lưu trữ" icon="folder_managed" description="Gom nhóm, tạo thư mục và kéo thả tệp tin như Google Drive trên đĩa cứng hệ thống">
            <template #actions>
                <span class="hidden items-center gap-1.5 rounded-xl bg-surface-container px-3 py-1.5 text-xs font-medium text-on-surface-variant md:flex">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-tertiary"></span>
                    <span>Đĩa cứng: <strong>{{ stats.total_size_human }}</strong> / {{ stats.total_files }} tệp</span>
                </span>
                <UiButton variant="secondary" icon="create_new_folder" @click="folderOpen = true">Tạo thư mục mới</UiButton>
                <UiButton @click="uploadCardOpen = !uploadCardOpen">
                    <span class="material-symbols-outlined text-[18px]">{{ uploadCardOpen ? 'expand_less' : 'cloud_upload' }}</span>
                    <span>{{ uploadCardOpen ? 'Đóng tải lên' : 'Kéo thả tải tệp' }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- 0. Kéo thả / tải lên -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
            <div v-show="uploadCardOpen" class="space-y-4 rounded-3xl border border-primary-container/30 bg-surface-container-lowest p-6 shadow-sm">
                <div class="flex flex-col gap-2 border-b border-surface-container-highest pb-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-primary-container/10 font-bold text-primary-container">
                            <span class="material-symbols-outlined text-lg">cloud_upload</span>
                        </span>
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface">Kéo thả &amp; Tải lên tệp tin mới</h3>
                            <p class="text-xs text-on-surface-variant">Tự động tối ưu hóa và phân loại tệp tin trên cây thư mục hệ thống</p>
                        </div>
                    </div>

                    <UiSelect v-model="targetFolder" inline-label="Lưu vào thư mục:" aria-label="Lưu vào thư mục" class="py-xs font-bold">
                        <option :value="currentFolder || 'auto_date'" :selected="targetFolder === (currentFolder || 'auto_date')">
                            {{ currentFolder ? `Thư mục hiện tại (uploads/media/${currentFolder})` : `uploads/media/${datedFolder} (Theo ngày tháng năm)` }}
                        </option>
                        <option value="auto_date" :selected="false">uploads/media/{{ datedFolder }} (Theo ngày tháng năm)</option>
                        <option v-for="[path, label] in presetFolders" :key="path" :value="path" :selected="targetFolder === path">uploads/media/{{ path }}/ ({{ label }})</option>
                        <option v-for="sf in subFolders" :key="sf.path" :value="sf.path" :selected="targetFolder === sf.path">uploads/media/{{ sf.path }}/</option>
                    </UiSelect>
                </div>

                <input ref="fileInput" type="file" multiple class="hidden" @change="handleFilesSelect" />

                <div
                    :class="['flex flex-col items-center justify-center space-y-3 rounded-2xl border-2 border-dashed p-8 text-center transition-all duration-200', isDragging ? 'scale-[1.01] border-primary-container bg-primary-container/10' : 'border-outline-variant bg-surface-container-low/50 hover:border-primary-container/60 hover:bg-primary-container/10']"
                    @dragover.prevent.stop="isDragging = true"
                    @dragleave.prevent.stop="isDragging = false"
                    @drop.prevent.stop="handleFilesDrop"
                >
                    <div :class="['flex h-14 w-14 transform cursor-pointer items-center justify-center rounded-2xl bg-primary-container/10 text-primary-container shadow-xs transition', isDragging ? 'scale-110' : '']" @click="fileInput?.click()">
                        <span class="material-symbols-outlined text-3xl">upload_file</span>
                    </div>
                    <div class="space-y-1">
                        <p class="text-sm font-bold text-on-surface">
                            <span class="text-primary-container">Kéo thả tệp tin vào đây</span> hoặc
                            <button type="button" class="inline cursor-pointer font-bold text-primary-container underline hover:text-primary" @click.stop="fileInput?.click()">chọn tệp từ máy tính</button>
                        </p>
                        <p class="text-xs text-on-surface-variant">
                            Hỗ trợ đa dạng: <span class="font-semibold text-on-surface-variant">Audio (MP3/WAV), Word (DOCX), PDF, Excel, Hình ảnh, Video, ZIP</span> (Tối đa 100MB/tệp)
                        </p>
                    </div>
                    <div v-show="isUploading" class="w-full max-w-md space-y-2 pt-2" @click.stop>
                        <div class="flex items-center justify-between text-xs font-bold text-on-surface-variant">
                            <span>{{ uploadStatusText }}</span>
                            <span class="font-code text-primary-container">{{ uploadProgress }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-surface-container-high">
                            <div class="h-full rounded-full bg-primary-container transition-all duration-200" :style="`width: ${uploadProgress}%`"></div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- 1. Breadcrumbs -->
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-surface-container-highest/90 bg-surface-container-lowest p-4 shadow-xs">
            <div class="flex items-center gap-1.5 overflow-x-auto py-1 text-xs font-bold">
                <template v-for="(bc, index) in breadcrumbs" :key="bc.path || 'root'">
                    <span v-if="index > 0" class="material-symbols-outlined text-sm text-on-surface-subtle">chevron_right</span>
                    <span v-if="index === breadcrumbs.length - 1 && currentFolder !== ''" class="flex items-center gap-1 rounded-xl border border-primary-container/30 bg-primary-container/10 px-2.5 py-1 text-primary-container">
                        <span class="material-symbols-outlined text-sm">folder_open</span>
                        <span>{{ bc.name }}</span>
                    </span>
                    <Link v-else :href="route('media.index', bc.path ? { folder: bc.path } : {})" class="flex items-center gap-1 rounded-xl px-2.5 py-1 text-on-surface-variant transition hover:bg-surface-container hover:text-on-surface">
                        <span class="material-symbols-outlined text-sm text-on-surface-subtle">{{ index === 0 ? 'home' : 'folder' }}</span>
                        <span>{{ bc.name }}</span>
                    </Link>
                </template>
            </div>
            <UiButton variant="secondary" size="sm" icon="add" @click="folderOpen = true">Tạo thư mục con</UiButton>
        </div>

        <!-- 2. Thư mục con -->
        <div v-if="subFolders.length" class="space-y-2.5">
            <div class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                <span class="material-symbols-outlined text-sm">folder</span>
                <span>Thư mục ({{ subFolders.length }})</span>
            </div>
            <div class="grid grid-cols-2 gap-3.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                <div v-for="sf in subFolders" :key="sf.path" class="group relative flex flex-col justify-between rounded-2xl border border-surface-container-highest/90 bg-surface-container-lowest p-3.5 shadow-xs transition hover:border-primary-container/40 hover:shadow-md">
                    <Link :href="route('media.index', { folder: sf.path })" class="block space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-container/10 text-primary-container transition group-hover:scale-105">
                                <span class="material-symbols-outlined text-2xl">folder</span>
                            </div>
                            <UiButton variant="danger-text" size="sm" icon="delete" title="Xóa thư mục" aria-label="Xóa thư mục" class="opacity-0 focus:opacity-100 group-hover:opacity-100" @click.prevent.stop="confirmDeleteFolder(sf)" />
                        </div>
                        <div>
                            <h4 class="truncate text-xs font-bold text-on-surface transition group-hover:text-primary-container" :title="sf.name">{{ sf.name }}</h4>
                            <p class="mt-0.5 font-code text-xs text-on-surface-subtle">{{ sf.files_count }} tệp · {{ sf.total_size_human }}</p>
                        </div>
                    </Link>
                </div>
            </div>
        </div>

        <!-- 3. Số liệu -->
        <div class="grid grid-cols-2 gap-3.5 md:grid-cols-4">
            <UiStatCard label="Tổng số tệp tin" :value="formatNumber(stats.total_files)" icon="description" tone="secondary" />
            <UiStatCard label="Tổng dung lượng" :value="stats.total_size_human" icon="hard_drive" tone="primary" />
            <UiStatCard :label="`Hình ảnh (${stats.images_count})`" :value="stats.images_size" icon="image" tone="success" />
            <UiStatCard label="Tài liệu & Excel" :value="stats.docs_size" icon="article" tone="secondary" />
        </div>

        <!-- 4. Bộ lọc -->
        <UiFilterBar :action="route('media.index')" :reset-url="route('media.index', currentFolder ? { folder: currentFolder } : {})" placeholder="Nhập tên tệp tin hoặc đường dẫn..." class="!mb-0">
            <template #quick>
                <div class="text-xs text-on-surface-variant">
                    Kết quả lọc: <strong class="text-on-surface">{{ formatNumber(totalFilteredCount) }}</strong> tệp
                    (<strong class="font-code text-primary-container">{{ totalFilteredSize }}</strong>)
                </div>
            </template>
            <input v-if="currentFolder" type="hidden" name="folder" :value="currentFolder" />
            <UiSelect name="type" label="Loại tệp" :value="filters.type" :options="typeOptions" />
            <UiSelect name="directory" label="Thư mục lưu trữ" :value="filters.directory" :options="directoryOptions" />
            <UiSelect name="size_range" label="Kích thước" :value="filters.size_range" :options="sizeOptions" />
            <UiSelect name="date_range" label="Thời gian tải lên" :value="filters.date_range" :options="dateOptions" />
        </UiFilterBar>

        <!-- 5. Thao tác hàng loạt -->
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-surface-container-highest/90 bg-surface-container-lowest p-3.5 shadow-sm">
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex cursor-pointer select-none items-center gap-2 text-xs font-bold text-on-surface-variant">
                    <input type="checkbox" :checked="isAllSelected" class="h-4 w-4 cursor-pointer rounded border-outline-variant text-primary-container focus:ring-primary-container" @change="toggleSelectAll" />
                    <span>Chọn tất cả trang này</span>
                </label>
                <span class="text-on-surface-subtle">|</span>
                <UiButton variant="danger" size="sm" icon="delete_sweep" :disabled="selectedFiles.length === 0" @click="confirmBulkDelete">
                    <span>Xóa các tệp ({{ selectedFiles.length }})</span>
                </UiButton>
                <UiButton variant="secondary" size="sm" icon="drive_file_move" :disabled="selectedFiles.length === 0" @click="openMoveModal">Di chuyển vào thư mục</UiButton>
                <UiButton v-if="totalFilteredCount > 0" variant="danger-text" size="sm" icon="delete_forever" :title="`Xóa vĩnh viễn toàn bộ ${totalFilteredCount} tệp tin khớp bộ lọc khỏi đĩa`" @click="confirmCleanFiltered">Xóa theo bộ lọc ({{ totalFilteredCount }} tệp)</UiButton>
            </div>

            <div class="flex items-center gap-1 rounded-xl border border-surface-container-highest bg-surface-container p-1">
                <button type="button" :class="['rounded-lg p-1.5 text-xs font-semibold transition', viewMode === 'grid' ? 'bg-surface-container-lowest font-bold text-primary-container shadow-xs' : 'text-on-surface-variant hover:text-on-surface']" title="Chế độ xem lưới (Grid)" @click="viewMode = 'grid'">
                    <span class="material-symbols-outlined text-[18px]">grid_view</span>
                </button>
                <button type="button" :class="['rounded-lg p-1.5 text-xs font-semibold transition', viewMode === 'table' ? 'bg-surface-container-lowest font-bold text-primary-container shadow-xs' : 'text-on-surface-variant hover:text-on-surface']" title="Chế độ xem bảng (Table)" @click="viewMode = 'table'">
                    <span class="material-symbols-outlined text-[18px]">table_rows</span>
                </button>
            </div>
        </div>

        <!-- 6. Danh sách tệp -->
        <template v-if="!files.data.length">
            <div v-if="subFolders.length" class="rounded-3xl border border-dashed border-surface-container-highest bg-surface-container-low/80 p-8 text-center">
                <p class="text-xs font-semibold text-on-surface-variant">
                    Thư mục này gồm <strong>{{ subFolders.length }} thư mục con</strong> ở trên. Hãy bấm vào một thư mục để xem tệp hoặc kéo thả tệp tin mới vào đây.
                </p>
            </div>
            <div v-else class="rounded-3xl border border-surface-container-highest bg-surface-container-lowest">
                <UiEmptyState icon="folder_off" title="Không tìm thấy tệp tin nào trong thư mục này" description="Hãy thử kéo thả tệp lên trên hoặc chuyển sang thư mục khác.">
                    <UiButton variant="secondary" icon="home" :href="route('media.index')">Về thư mục gốc</UiButton>
                </UiEmptyState>
            </div>
        </template>
        <template v-else>
            <!-- 6A. Lưới -->
            <div v-show="viewMode === 'grid'" class="grid grid-cols-2 gap-3.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                <div v-for="file in files.data" :key="file.id" class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-surface-container-highest/90 bg-surface-container-lowest shadow-sm transition hover:border-primary-container/50 hover:shadow-md">
                    <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low/70 p-2.5">
                        <label class="cursor-pointer">
                            <input v-model="selectedFiles" type="checkbox" :value="file.id" class="file-checkbox h-3.5 w-3.5 cursor-pointer rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                        </label>
                        <span class="rounded bg-surface-container-high/70 px-1.5 py-0.5 font-code text-xs font-extrabold uppercase text-on-surface-variant">{{ file.extension }}</span>
                    </div>

                    <div class="relative flex min-h-[110px] items-center justify-center overflow-hidden bg-surface-container/40 p-3">
                        <img v-if="file.is_image" :src="file.url" :alt="file.filename" class="shadow-2xs max-h-24 w-auto cursor-pointer rounded-lg object-contain transition duration-200 group-hover:scale-105" loading="lazy" @click="openPreview(file, 'image')" />
                        <div v-else-if="file.type === 'audio'" class="shadow-2xs flex h-14 w-14 cursor-pointer flex-col items-center justify-center rounded-2xl bg-warning-container text-warning transition hover:bg-warning-container group-hover:scale-105" title="Bấm để nghe tệp âm thanh" @click="openPreview(file, 'audio')">
                            <span class="material-symbols-outlined text-2xl">headphones</span>
                            <span class="mt-0.5 text-xs font-bold uppercase tracking-wider">MP3</span>
                        </div>
                        <div v-else :class="['flex h-12 w-12 items-center justify-center rounded-xl', iconTone(file.type)]">
                            <span class="material-symbols-outlined text-2xl">{{ typeIcon(file.type) }}</span>
                        </div>
                    </div>

                    <div class="space-y-1 border-t border-surface-container-highest bg-surface-container-lowest p-2.5 text-left">
                        <h4 class="truncate text-xs font-bold text-on-surface" :title="file.filename">{{ file.filename }}</h4>
                        <div class="flex items-center justify-between font-code text-xs text-on-surface-subtle">
                            <span class="font-bold text-on-surface-variant">{{ file.size_human }}</span>
                            <span class="inline-flex max-w-[75px] items-center gap-0.5" :title="file.directory"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">folder</span><span class="truncate">{{ file.directory }}</span></span>
                        </div>
                        <div class="pt-0.5 text-xs text-on-surface-subtle">{{ file.created_at_human }}</div>
                        <div class="flex items-center justify-between gap-1 border-t border-surface-container-highest pt-2">
                            <UiButton variant="ghost" size="sm" icon="link" title="Sao chép URL" aria-label="Sao chép URL" @click="copyUrl(file.url)" />
                            <UiButton variant="ghost" size="sm" icon="download" :href="file.download_url" native title="Tải về máy" aria-label="Tải về máy" />
                            <UiButton variant="danger-text" size="sm" icon="delete" title="Xóa vĩnh viễn" aria-label="Xóa vĩnh viễn" @click="confirmSingleDelete(file)" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- 6B. Bảng -->
            <UiDataTable v-show="viewMode === 'table'" class="shadow-sm">
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th class="w-10 text-center">
                                <input type="checkbox" :checked="isAllSelected" aria-label="Chọn tất cả trang này" class="h-3.5 w-3.5 cursor-pointer rounded border-outline-variant text-primary-container focus:ring-primary-container" @change="toggleSelectAll" />
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
                        <tr v-for="file in files.data" :key="file.id">
                            <td class="text-center">
                                <input v-model="selectedFiles" type="checkbox" :value="file.id" class="h-3.5 w-3.5 cursor-pointer rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                            </td>
                            <td class="font-medium">
                                <div class="flex items-center gap-2.5">
                                    <img v-if="file.is_image" :src="file.url" class="h-7 w-7 shrink-0 cursor-pointer rounded-lg border object-cover" :alt="file.filename" @click="openPreview(file, 'image')" />
                                    <span v-else-if="file.type === 'audio'" class="material-symbols-outlined cursor-pointer text-lg text-warning transition hover:scale-110" title="Nghe audio" @click="openPreview(file, 'audio')">headphones</span>
                                    <span v-else class="material-symbols-outlined text-base text-on-surface-subtle">draft</span>
                                    <span class="max-w-xs truncate font-bold text-on-surface" :title="file.filename">{{ file.filename }}</span>
                                </div>
                            </td>
                            <td class="font-code text-xs text-on-surface-variant"><span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">folder</span>{{ file.directory }}</span></td>
                            <td><UiBadge pill :dot="false" class="uppercase" :color="badgeColor(file.type)">{{ file.type }} ({{ file.extension }})</UiBadge></td>
                            <td class="text-right font-code font-bold">{{ file.size_human }}</td>
                            <td class="text-xs text-on-surface-subtle">{{ file.created_at_human }}</td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <UiButton variant="ghost" size="sm" icon="link" title="Copy URL" aria-label="Copy URL" @click="copyUrl(file.url)" />
                                    <UiButton variant="ghost" size="sm" icon="download" :href="file.download_url" native title="Tải về" aria-label="Tải về" />
                                    <UiButton variant="danger-text" size="sm" icon="delete" title="Xóa" aria-label="Xóa" @click="confirmSingleDelete(file)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <UiPagination :paginator="files" :options="[]" unit="tệp" class="mt-4" />
        </template>

        <!-- 7. Tạo thư mục mới -->
        <UiModal :show="folderOpen" title="Tạo thư mục mới" max-width="md" @close="folderOpen = false">
            <UiForm id="media-folder-form" :action="route('media.create-folder')" method="post" class="space-y-4" reset-on-success @success="folderOpen = false">
                <input type="hidden" name="parent_folder" :value="currentFolder" />
                <div class="space-y-1">
                    <UiInput name="folder_name" label="Tên thư mục" required placeholder="Ví dụ: hop_dong_2026, anh_su_kien..." class="font-semibold" autofocus />
                    <p class="text-xs text-on-surface-subtle">
                        Vị trí tạo: <strong class="font-code text-on-surface-variant">/uploads/media/{{ currentFolder ? currentFolder + '/' : '' }}</strong>
                    </p>
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="folderOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="media-folder-form">Tạo thư mục</UiButton>
            </template>
        </UiModal>

        <!-- 8. Di chuyển tệp -->
        <UiModal :show="moveOpen" title="Di chuyển vào thư mục" max-width="md" @close="moveOpen = false">
            <UiForm id="media-move-form" :action="route('media.move-files')" method="post" class="space-y-4" @success="onMoved">
                <input v-for="id in selectedFiles" :key="id" type="hidden" name="selected_files[]" :value="id" />
                <p class="font-semibold text-on-surface">Di chuyển {{ selectedFiles.length }} tệp tin</p>
                <UiSelect name="target_folder" label="Chọn thư mục đích" required placeholder="/uploads/media (Thư mục gốc)" class="font-semibold">
                    <option :value="datedFolder">/uploads/{{ datedFolder }}</option>
                    <option v-for="[path] in presetFolders" :key="path" :value="path">/uploads/{{ path }}</option>
                    <option v-for="sf in subFolders" :key="sf.path" :value="sf.path">/uploads/{{ sf.path }}</option>
                </UiSelect>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="moveOpen = false">Hủy</UiButton>
                <UiButton type="submit" variant="info" form="media-move-form">Di chuyển ngay</UiButton>
            </template>
        </UiModal>

        <!-- 9. Xem trước ảnh / âm thanh -->
        <div v-if="preview.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-xs" @click="preview.open = false">
            <div class="w-full max-w-3xl overflow-hidden rounded-2xl bg-surface-container-lowest shadow-2xl" @click.stop>
                <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low px-5 py-3">
                    <div>
                        <h4 class="max-w-md truncate text-xs font-bold text-on-surface">{{ preview.name }}</h4>
                        <span class="font-code text-xs text-on-surface-subtle">{{ preview.size }}</span>
                    </div>
                    <button type="button" class="p-1 text-on-surface-subtle hover:text-on-surface-variant" aria-label="Đóng" @click="preview.open = false">
                        <span class="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>
                <div class="flex max-h-[75vh] min-h-[160px] items-center justify-center overflow-hidden bg-on-surface p-4">
                    <img v-if="preview.type === 'image'" :src="preview.url" :alt="preview.name" class="max-h-[70vh] max-w-full rounded-lg object-contain" />
                    <div v-else-if="preview.type === 'audio'" class="flex w-full max-w-md flex-col items-center space-y-4 rounded-2xl bg-on-surface p-6">
                        <span class="material-symbols-outlined animate-pulse text-5xl text-warning">headphones</span>
                        <p class="w-full truncate text-center font-code text-xs text-white">{{ preview.name }}</p>
                        <audio :src="preview.url" controls class="w-full" autoplay></audio>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-surface-container-highest bg-surface-container-low p-3 text-xs">
                    <UiButton variant="secondary" size="sm" @click="copyUrl(preview.url)">Sao chép Link</UiButton>
                    <a :href="preview.url" target="_blank" rel="noopener" class="rounded-xl bg-primary-container px-3 py-1.5 font-bold text-white transition hover:bg-primary">Mở tệp gốc</a>
                </div>
            </div>
        </div>
    </div>
</template>
