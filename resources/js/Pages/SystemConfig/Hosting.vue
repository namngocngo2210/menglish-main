<script setup>
/** Thông số hosting & máy chủ: dung lượng lưu trữ theo thành phần, PHP / web server / Laravel / CSDL, extension và quyền ghi thư mục. */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Thông số hosting & máy chủ' } });

const props = defineProps({
    storageStats: { type: Object, required: true },
    serverSpecs: { type: Object, required: true },
    extensionStatuses: { type: Array, default: () => [] },
    healthChecks: { type: Object, required: true },
    now: { type: String, default: '' },
});

const width = (percent) => `width: ${Math.max(1, Number(percent) || 0)}%`;
const writable = computed(() => [
    ['storage_writable', 'folder', 'storage/'],
    ['cache_writable', 'memory', 'bootstrap/cache/'],
    ['uploads_writable', 'photo_library', 'public/uploads/'],
]);
const s = computed(() => props.storageStats);
const partitions = computed(() => [
    ['Media Uploads', s.value.uploads_percent, s.value.uploads_size_formatted, 'public/uploads/', 'text-secondary'],
    ['Database MySQL', s.value.db_percent, `${s.value.db_size_mb} MB`, 'Data + Indexes', 'text-tertiary'],
    ['Storage & Logs', s.value.storage_percent, s.value.storage_size_formatted, 'storage/logs/cache', 'text-accent'],
    ['Source & Vendor', s.value.source_vendor_percent, s.value.source_vendor_size_formatted, 'app + vendor', 'text-warning'],
]);
</script>

<template>
    <UiPageHeader title="Thông số hosting & máy chủ" icon="dns">
        <template #actions>
            <UiButton variant="secondary" icon="refresh" @click="router.reload()">Làm mới thông số</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- 1. Dung lượng & trạng thái nhanh -->
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="shadow-2xs space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 md:p-6 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-secondary/10 text-secondary">
                            <span class="material-symbols-outlined text-lg">hard_drive</span>
                        </div>
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Dung Lượng Lưu Trữ Website Đang Sử Dụng</h2>
                            <p class="text-xs text-on-surface-variant">Thống kê dung lượng thực tế website sử dụng và tỷ trọng từng thành phần</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-xl border border-secondary/30 bg-secondary/10 px-3 py-1.5 font-mono text-xs text-secondary">
                        <span class="font-sans text-xs font-semibold text-on-surface-variant">Đã sử dụng:</span>
                        <strong class="text-sm font-black text-secondary">{{ s.total_used_formatted }}</strong>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-surface-container p-0.5">
                        <div class="h-full rounded-l-full bg-secondary transition-all duration-500" :style="width(s.uploads_percent)" :title="`Media Uploads: ${s.uploads_size_formatted} (${s.uploads_percent}%)`"></div>
                        <div class="h-full bg-warning transition-all duration-500" :style="width(s.source_vendor_percent)" :title="`Mã nguồn & Vendor: ${s.source_vendor_size_formatted} (${s.source_vendor_percent}%)`"></div>
                        <div class="h-full bg-accent transition-all duration-500" :style="width(s.storage_percent)" :title="`Storage & Logs: ${s.storage_size_formatted} (${s.storage_percent}%)`"></div>
                        <div class="h-full rounded-r-full bg-tertiary transition-all duration-500" :style="width(s.db_percent)" :title="`Database MySQL: ${s.db_size_mb} MB (${s.db_percent}%)`"></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 font-mono text-xs text-on-surface-variant">
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-secondary"></span>
                            <span>Uploads: <strong class="text-on-surface">{{ s.uploads_size_formatted }}</strong> ({{ s.uploads_percent }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-warning"></span>
                            <span>Mã nguồn &amp; Vendor: <strong class="text-on-surface">{{ s.source_vendor_size_formatted }}</strong> ({{ s.source_vendor_percent }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-accent"></span>
                            <span>Storage &amp; Logs: <strong class="text-on-surface">{{ s.storage_size_formatted }}</strong> ({{ s.storage_percent }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-tertiary"></span>
                            <span>Database: <strong class="text-on-surface">{{ s.db_size_mb }} MB</strong> ({{ s.db_percent }}%)</span>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 border-t border-surface-container-highest pt-2 sm:grid-cols-4">
                    <div v-for="[label, percent, size, path, tone] in partitions" :key="label" class="space-y-1 rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase text-on-surface-subtle">{{ label }}</span>
                            <span :class="['font-mono text-xs font-bold', tone]">{{ percent }}%</span>
                        </div>
                        <div :class="['font-mono text-xs font-black', tone]">{{ size }}</div>
                        <div class="truncate text-xs text-on-surface-subtle">{{ path }}</div>
                    </div>
                </div>
            </div>

            <div class="shadow-2xs flex flex-col justify-between space-y-4 rounded-2xl bg-gradient-to-br from-inverse-surface to-on-info-container p-5 text-white md:p-6">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-1 rounded-full border border-tertiary/30 bg-tertiary/20 px-2.5 py-0.5 text-xs font-bold text-tertiary-fixed">
                            <span class="h-2 w-2 animate-pulse rounded-full bg-tertiary/40"></span>
                            <span>Hệ Thống Trực Tuyến</span>
                        </span>
                        <span class="font-mono text-xs text-inverse-on-surface">{{ now }}</span>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-inverse-on-surface/70">Web Server / Hosting Panel</div>
                        <div class="mt-0.5 flex items-center gap-1.5 text-base font-extrabold text-warning-container">
                            <span class="material-symbols-outlined text-lg">bolt</span>
                            <span>{{ serverSpecs.web_server_name }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 border-t border-white/10 pt-3 font-mono text-xs text-inverse-on-surface">
                    <div class="flex items-center justify-between">
                        <span class="text-inverse-on-surface/70">Phiên bản PHP:</span>
                        <span class="rounded bg-white/10 px-2 py-0.5 font-bold text-white">{{ serverSpecs.php_version }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-inverse-on-surface/70">Laravel Core:</span>
                        <span class="rounded bg-white/10 px-2 py-0.5 font-bold text-white">v{{ serverSpecs.laravel_version }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-inverse-on-surface/70">MySQL Server:</span>
                        <span class="font-bold text-tertiary-fixed">{{ serverSpecs.db_version }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. PHP runtime & máy chủ -->
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="shadow-2xs space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5">
                <div class="flex items-center gap-2 border-b border-surface-container-highest pb-2">
                    <span class="material-symbols-outlined text-secondary">tune</span>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Cấu Hình PHP Runtime &amp; Giới Hạn Tài Nguyên</h2>
                </div>
                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Giới hạn RAM PHP (Memory Limit)</span>
                        <span class="rounded bg-surface-container px-2 py-0.5 font-mono font-bold text-on-surface">{{ serverSpecs.memory_limit }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Dung lượng Upload file tối đa (Upload Max)</span>
                        <span class="rounded bg-secondary/10 px-2 py-0.5 font-mono font-bold text-secondary">{{ serverSpecs.upload_max_filesize }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Dung lượng POST tối đa (Post Max Size)</span>
                        <span class="rounded bg-secondary/10 px-2 py-0.5 font-mono font-bold text-secondary">{{ serverSpecs.post_max_size }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Thời gian thực thi tối đa (Max Execution Time)</span>
                        <span class="font-mono font-bold text-on-surface">{{ serverSpecs.max_execution_time }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Số biến gửi tối đa (Max Input Vars)</span>
                        <span class="font-mono font-bold text-on-surface">{{ serverSpecs.max_input_vars }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">PHP SAPI Interface</span>
                        <span class="font-mono text-on-surface-variant">{{ serverSpecs.php_sapi }}</span>
                    </div>
                </div>
            </div>

            <div class="shadow-2xs space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5">
                <div class="flex items-center gap-2 border-b border-surface-container-highest pb-2">
                    <span class="material-symbols-outlined text-primary">computer</span>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Hệ Điều Hành &amp; Môi Trường Máy Chủ</h2>
                </div>
                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Máy chủ Web (Web Server Daemon)</span>
                        <span class="text-right font-bold text-on-surface">{{ serverSpecs.web_server_name }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Chuỗi Server Header Gốc (Raw Software)</span>
                        <span class="max-w-[240px] truncate rounded bg-secondary/10 px-2 py-0.5 text-right font-mono text-secondary">{{ serverSpecs.server_software }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Bảng điều khiển Hosting (Control Panel)</span>
                        <span class="text-right font-semibold text-on-surface">{{ serverSpecs.hosting_panel }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Hệ điều hành máy chủ (OS)</span>
                        <span class="max-w-[240px] truncate text-right font-mono text-on-surface">{{ serverSpecs.os_name }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Tên máy chủ (Hostname) / Server IP</span>
                        <span class="font-mono font-bold text-on-surface">{{ serverSpecs.hostname }} ({{ serverSpecs.server_ip }})</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Cổng kết nối (Port) &amp; Giao thức</span>
                        <span class="font-mono text-on-surface-variant">Port {{ serverSpecs.server_port }} ({{ serverSpecs.server_protocol }})</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Giao thức bảo mật (SSL/TLS)</span>
                        <UiBadge :color="healthChecks.https_active ? 'success' : 'neutral'">{{ healthChecks.https_active ? 'HTTPS (Được mã hóa SSL)' : 'HTTP (Local Development)' }}</UiBadge>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Laravel & CSDL -->
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="shadow-2xs space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5">
                <div class="flex items-center gap-2 border-b border-surface-container-highest pb-2">
                    <span class="material-symbols-outlined text-error">deployed_code</span>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Cấu Hình Framework Laravel</h2>
                </div>
                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Phiên bản Laravel (Version)</span>
                        <span class="rounded bg-error/10 px-2 py-0.5 font-mono font-bold text-error">v{{ serverSpecs.laravel_version }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Môi trường hoạt động (Environment)</span>
                        <span :class="['rounded px-2 py-0.5 font-mono font-bold uppercase', serverSpecs.app_env === 'production' ? 'bg-tertiary/10 text-tertiary' : 'bg-warning/10 text-warning']">{{ serverSpecs.app_env }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Chế độ Debug (APP_DEBUG)</span>
                        <span :class="['font-mono font-bold', serverSpecs.app_debug ? 'text-warning' : 'text-tertiary']">{{ serverSpecs.app_debug ? 'BẬT (True)' : 'TẮT (False - An toàn)' }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Driver Session / Cache / Queue</span>
                        <span class="font-mono text-on-surface">{{ serverSpecs.session_driver }} / {{ serverSpecs.cache_driver }} / {{ serverSpecs.queue_driver }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Hệ thống gửi Email (Mail Transport)</span>
                        <span class="font-mono font-semibold text-secondary">{{ serverSpecs.mail_driver }} ({{ serverSpecs.mail_host }})</span>
                    </div>
                </div>
            </div>

            <div class="shadow-2xs space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5">
                <div class="flex items-center gap-2 border-b border-surface-container-highest pb-2">
                    <span class="material-symbols-outlined text-tertiary">database</span>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Cơ Sở Dữ Liệu MySQL / MariaDB</h2>
                </div>
                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Hệ quản trị CSDL &amp; Phiên bản</span>
                        <span class="rounded bg-tertiary/10 px-2 py-0.5 font-mono font-bold text-tertiary">{{ serverSpecs.db_version }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Tên Database đang kết nối</span>
                        <span class="font-mono font-bold text-on-surface">{{ serverSpecs.db_database }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Địa chỉ máy chủ CSDL (DB Host)</span>
                        <span class="font-mono text-on-surface-variant">{{ serverSpecs.db_host }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Tổng số bảng dữ liệu (Tables)</span>
                        <span class="font-mono font-bold text-secondary">{{ serverSpecs.db_tables_count }} bảng</span>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="font-medium text-on-surface-variant">Dung lượng CSDL (Data + Index)</span>
                        <span class="font-mono font-black text-on-surface">{{ s.db_size_mb }} MB</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. PHP extensions & quyền ghi thư mục -->
        <div class="shadow-2xs space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5">
            <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-tertiary">health_and_safety</span>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Trạng Thái PHP Extensions &amp; Quyền Ghi Thư Mục</h2>
                </div>
                <span class="text-xs text-on-surface-subtle">Kiểm tra tự động toàn bộ thư viện cần thiết</span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div v-for="[key, icon, path] in writable" :key="key" :class="['flex items-center justify-between rounded-xl border p-3 text-xs', healthChecks[key] ? 'border-tertiary/30 bg-tertiary/10' : 'border-error/30 bg-error/10']">
                    <div class="flex items-center gap-2">
                        <span :class="['material-symbols-outlined text-base', healthChecks[key] ? 'text-tertiary' : 'text-error']">{{ icon }}</span>
                        <span class="font-semibold text-on-surface">{{ path }}</span>
                    </div>
                    <UiBadge :color="healthChecks[key] ? 'success' : 'error'">{{ healthChecks[key] ? 'Writable (OK)' : 'Read-Only' }}</UiBadge>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2.5 pt-2 sm:grid-cols-3 md:grid-cols-4">
                <div v-for="ext in extensionStatuses" :key="ext.key" :class="['flex items-center justify-between rounded-xl border p-2.5 text-xs', ext.enabled ? 'border-surface-container-highest bg-surface-container-low/70' : 'border-error/30 bg-error/10']">
                    <div class="mr-1 space-y-0.5 truncate">
                        <div class="truncate font-mono text-xs font-bold text-on-surface">{{ ext.key }}</div>
                        <div class="truncate text-xs text-on-surface-variant">{{ ext.label }}</div>
                    </div>
                    <span v-if="ext.enabled" class="material-symbols-outlined shrink-0 text-base text-tertiary">check_circle</span>
                    <span v-else class="material-symbols-outlined shrink-0 text-base text-error">cancel</span>
                </div>
            </div>
        </div>
    </div>
</template>
