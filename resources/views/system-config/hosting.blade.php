<x-app-layout>
    <x-ui.page-header title="Thông Số Hosting & Máy Chủ (Server Diagnostics)" icon="dns">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="refresh" onclick="window.location.reload();">Làm mới thông số</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        {{-- Top Nav Tabs matching System Config --}}
        <div class="flex items-center gap-2 border-b border-surface-container-highest pb-2 overflow-x-auto">
            <x-ui.button variant="secondary" size="sm" icon="account_balance_wallet" :href="route('system-config.bank-accounts')">Tài khoản Ngân hàng</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="notifications_active" :href="route('system-config.debt-reminders')">Mẫu nhắc nợ</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="mail" :href="route('system-config.ticket-emails')">Email nhận Ticket</x-ui.button>
            <x-ui.button size="sm" icon="dns" :href="route('system-config.hosting')">Hosting &amp; Máy chủ</x-ui.button>
        </div>

        {{-- 1. Top Quota & Storage Highlights --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            {{-- Disk Storage Usage Card --}}
            <div class="lg:col-span-2 bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 md:p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-secondary/10 text-secondary flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">hard_drive</span>
                        </div>
                        <div>
                            <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Dung Lượng Lưu Trữ Website Đang Sử Dụng</h2>
                            <p class="text-[11px] text-on-surface-variant">Thống kê dung lượng thực tế website sử dụng và tỷ trọng từng thành phần</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-secondary/10 border border-secondary/30 text-secondary font-mono text-xs">
                        <span class="text-on-surface-variant font-sans text-[11px] font-semibold">Đã sử dụng:</span>
                        <strong class="font-black text-secondary text-sm">{{ $storageStats['total_used_formatted'] }}</strong>
                    </div>
                </div>

                {{-- Multi-component Composition Bar --}}
                <div class="space-y-2">
                    <div class="w-full h-3 bg-surface-container rounded-full overflow-hidden flex p-0.5 gap-0.5">
                        <div class="h-full rounded-l-full bg-secondary transition-all duration-500" style="width: {{ max(1, $storageStats['uploads_percent']) }}%" title="Media Uploads: {{ $storageStats['uploads_size_formatted'] }} ({{ $storageStats['uploads_percent'] }}%)"></div>
                        <div class="h-full bg-warning transition-all duration-500" style="width: {{ max(1, $storageStats['source_vendor_percent']) }}%" title="Mã nguồn &amp; Vendor: {{ $storageStats['source_vendor_size_formatted'] }} ({{ $storageStats['source_vendor_percent'] }}%)"></div>
                        <div class="h-full bg-purple-600 transition-all duration-500" style="width: {{ max(1, $storageStats['storage_percent']) }}%" title="Storage &amp; Logs: {{ $storageStats['storage_size_formatted'] }} ({{ $storageStats['storage_percent'] }}%)"></div>
                        <div class="h-full rounded-r-full bg-tertiary transition-all duration-500" style="width: {{ max(1, $storageStats['db_percent']) }}%" title="Database MySQL: {{ $storageStats['db_size_mb'] }} MB ({{ $storageStats['db_percent'] }}%)"></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-on-surface-variant font-mono">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-secondary inline-block shrink-0"></span>
                            <span>Uploads: <strong class="text-on-surface">{{ $storageStats['uploads_size_formatted'] }}</strong> ({{ $storageStats['uploads_percent'] }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-warning inline-block shrink-0"></span>
                            <span>Mã nguồn &amp; Vendor: <strong class="text-on-surface">{{ $storageStats['source_vendor_size_formatted'] }}</strong> ({{ $storageStats['source_vendor_percent'] }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-purple-600 inline-block shrink-0"></span>
                            <span>Storage &amp; Logs: <strong class="text-on-surface">{{ $storageStats['storage_size_formatted'] }}</strong> ({{ $storageStats['storage_percent'] }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-tertiary inline-block shrink-0"></span>
                            <span>Database: <strong class="text-on-surface">{{ $storageStats['db_size_mb'] }} MB</strong> ({{ $storageStats['db_percent'] }}%)</span>
                        </span>
                    </div>
                </div>

                {{-- Partition Breakdown --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 border-t border-surface-container-highest">
                    <div class="p-3 rounded-xl bg-surface-container-low border border-surface-container-highest space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-on-surface-variant/70 font-bold uppercase">Media Uploads</span>
                            <span class="text-[10px] font-bold text-secondary font-mono">{{ $storageStats['uploads_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-secondary font-mono">{{ $storageStats['uploads_size_formatted'] }}</div>
                        <div class="text-[9px] text-on-surface-variant/70 truncate">public/uploads/</div>
                    </div>
                    <div class="p-3 rounded-xl bg-surface-container-low border border-surface-container-highest space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-on-surface-variant/70 font-bold uppercase">Database MySQL</span>
                            <span class="text-[10px] font-bold text-tertiary font-mono">{{ $storageStats['db_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-tertiary font-mono">{{ $storageStats['db_size_mb'] }} MB</div>
                        <div class="text-[9px] text-on-surface-variant/70 truncate">Data + Indexes</div>
                    </div>
                    <div class="p-3 rounded-xl bg-surface-container-low border border-surface-container-highest space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-on-surface-variant/70 font-bold uppercase">Storage &amp; Logs</span>
                            <span class="text-[10px] font-bold text-purple-600 font-mono">{{ $storageStats['storage_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-purple-700 font-mono">{{ $storageStats['storage_size_formatted'] }}</div>
                        <div class="text-[9px] text-on-surface-variant/70 truncate">storage/logs/cache</div>
                    </div>
                    <div class="p-3 rounded-xl bg-surface-container-low border border-surface-container-highest space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-on-surface-variant/70 font-bold uppercase">Source &amp; Vendor</span>
                            <span class="text-[10px] font-bold text-warning font-mono">{{ $storageStats['source_vendor_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-warning font-mono">{{ $storageStats['source_vendor_size_formatted'] }}</div>
                        <div class="text-[9px] text-on-surface-variant/70 truncate">app + vendor</div>
                    </div>
                </div>
            </div>

            {{-- Server Quick Status Card --}}
            <div class="bg-gradient-to-br from-slate-900 to-indigo-950 text-white rounded-2xl p-5 md:p-6 shadow-2xs flex flex-col justify-between space-y-4">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[11px] font-bold flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span>Hệ Thống Trực Tuyến</span>
                        </span>
                        <span class="text-[11px] font-mono text-slate-300">{{ now()->format('H:i:s d/m/Y') }}</span>
                    </div>
                    <div>
                        <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Web Server / Hosting Panel</div>
                        <div class="text-base font-extrabold text-amber-300 mt-0.5 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-lg">bolt</span>
                            <span>{{ $serverSpecs['web_server_name'] }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 pt-3 border-t border-white/10 text-xs font-mono text-slate-200">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Phiên bản PHP:</span>
                        <span class="font-bold text-white bg-white/10 px-2 py-0.5 rounded">{{ $serverSpecs['php_version'] }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Laravel Core:</span>
                        <span class="font-bold text-white bg-white/10 px-2 py-0.5 rounded">v{{ $serverSpecs['laravel_version'] }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">MySQL Server:</span>
                        <span class="font-bold text-emerald-300">{{ $serverSpecs['db_version'] }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Core Server & Runtime Specifications --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            {{-- PHP Configuration & Limits --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-surface-container-highest">
                    <span class="material-symbols-outlined text-secondary">tune</span>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Cấu Hình PHP Runtime &amp; Giới Hạn Tài Nguyên</h2>
                </div>

                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Giới hạn RAM PHP (Memory Limit)</span>
                        <span class="font-mono font-bold text-on-surface bg-surface-container px-2 py-0.5 rounded">{{ $serverSpecs['memory_limit'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Dung lượng Upload file tối đa (Upload Max)</span>
                        <span class="font-mono font-bold text-secondary bg-secondary/10 px-2 py-0.5 rounded">{{ $serverSpecs['upload_max_filesize'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Dung lượng POST tối đa (Post Max Size)</span>
                        <span class="font-mono font-bold text-secondary bg-secondary/10 px-2 py-0.5 rounded">{{ $serverSpecs['post_max_size'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Thời gian thực thi tối đa (Max Execution Time)</span>
                        <span class="font-mono font-bold text-on-surface">{{ $serverSpecs['max_execution_time'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Số biến gửi tối đa (Max Input Vars)</span>
                        <span class="font-mono font-bold text-on-surface">{{ $serverSpecs['max_input_vars'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">PHP SAPI Interface</span>
                        <span class="font-mono text-on-surface-variant">{{ $serverSpecs['php_sapi'] }}</span>
                    </div>
                </div>
            </div>

            {{-- Web Server & Operating System --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-surface-container-highest">
                    <span class="material-symbols-outlined text-primary">computer</span>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Hệ Điều Hành &amp; Môi Trường Máy Chủ</h2>
                </div>

                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Máy chủ Web (Web Server Daemon)</span>
                        <span class="font-bold text-on-surface text-right">{{ $serverSpecs['web_server_name'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Chuỗi Server Header Gốc (Raw Software)</span>
                        <span class="font-mono text-secondary bg-secondary/10 px-2 py-0.5 rounded text-right truncate max-w-[240px]">{{ $serverSpecs['server_software'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Bảng điều khiển Hosting (Control Panel)</span>
                        <span class="font-semibold text-on-surface text-right">{{ $serverSpecs['hosting_panel'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Hệ điều hành máy chủ (OS)</span>
                        <span class="font-mono text-on-surface text-right truncate max-w-[240px]">{{ $serverSpecs['os_name'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Tên máy chủ (Hostname) / Server IP</span>
                        <span class="font-mono font-bold text-on-surface">{{ $serverSpecs['hostname'] }} ({{ $serverSpecs['server_ip'] }})</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Cổng kết nối (Port) &amp; Giao thức</span>
                        <span class="font-mono text-on-surface-variant">Port {{ $serverSpecs['server_port'] }} ({{ $serverSpecs['server_protocol'] }})</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Giao thức bảo mật (SSL/TLS)</span>
                        <x-ui.badge :color="$healthChecks['https_active'] ? 'success' : 'neutral'">
                            {{ $healthChecks['https_active'] ? 'HTTPS (Được mã hóa SSL)' : 'HTTP (Local Development)' }}
                        </x-ui.badge>
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. Laravel Framework & Database Specs --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            {{-- Laravel Framework Status --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-surface-container-highest">
                    <span class="material-symbols-outlined text-error">deployed_code</span>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Cấu Hình Framework Laravel</h2>
                </div>

                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Phiên bản Laravel (Version)</span>
                        <span class="font-mono font-bold text-error bg-error/10 px-2 py-0.5 rounded">v{{ $serverSpecs['laravel_version'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Môi trường hoạt động (Environment)</span>
                        <span class="font-mono font-bold uppercase {{ $serverSpecs['app_env'] === 'production' ? 'text-tertiary bg-tertiary/10' : 'text-warning bg-warning/10' }} px-2 py-0.5 rounded">
                            {{ $serverSpecs['app_env'] }}
                        </span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Chế độ Debug (APP_DEBUG)</span>
                        <span class="font-mono font-bold {{ $serverSpecs['app_debug'] ? 'text-warning' : 'text-tertiary' }}">
                            {{ $serverSpecs['app_debug'] ? 'BẬT (True)' : 'TẮT (False - An toàn)' }}
                        </span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Driver Session / Cache / Queue</span>
                        <span class="font-mono text-on-surface">{{ $serverSpecs['session_driver'] }} / {{ $serverSpecs['cache_driver'] }} / {{ $serverSpecs['queue_driver'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Hệ thống gửi Email (Mail Transport)</span>
                        <span class="font-mono text-secondary font-semibold">{{ $serverSpecs['mail_driver'] }} ({{ $serverSpecs['mail_host'] }})</span>
                    </div>
                </div>
            </div>

            {{-- Database Connection Specs --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-surface-container-highest">
                    <span class="material-symbols-outlined text-tertiary">database</span>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Cơ Sở Dữ Liệu MySQL / MariaDB</h2>
                </div>

                <div class="divide-y divide-surface-container-highest text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Hệ quản trị CSDL &amp; Phiên bản</span>
                        <span class="font-mono font-bold text-tertiary bg-tertiary/10 px-2 py-0.5 rounded">{{ $serverSpecs['db_version'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Tên Database đang kết nối</span>
                        <span class="font-mono font-bold text-on-surface">{{ $serverSpecs['db_database'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Địa chỉ máy chủ CSDL (DB Host)</span>
                        <span class="font-mono text-on-surface-variant">{{ $serverSpecs['db_host'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Tổng số bảng dữ liệu (Tables)</span>
                        <span class="font-mono font-bold text-secondary">{{ $serverSpecs['db_tables_count'] }} bảng</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-on-surface-variant font-medium">Dung lượng CSDL (Data + Index)</span>
                        <span class="font-mono font-black text-on-surface">{{ $storageStats['db_size_mb'] }} MB</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. PHP Extensions & Health Checks --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 space-y-4">
            <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-tertiary">health_and_safety</span>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Trạng Thái PHP Extensions &amp; Quyền Ghi Thư Mục</h2>
                </div>
                <span class="text-[11px] text-on-surface-variant/70">Kiểm tra tự động toàn bộ thư viện cần thiết</span>
            </div>

            {{-- Permission checks --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-3 rounded-xl border {{ $healthChecks['storage_writable'] ? 'bg-tertiary/10 border-tertiary/30' : 'bg-error/10 border-error/30' }} flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base {{ $healthChecks['storage_writable'] ? 'text-tertiary' : 'text-error' }}">folder</span>
                        <span class="font-semibold text-on-surface">storage/</span>
                    </div>
                    <x-ui.badge :color="$healthChecks['storage_writable'] ? 'success' : 'error'">
                        {{ $healthChecks['storage_writable'] ? 'Writable (OK)' : 'Read-Only' }}
                    </x-ui.badge>
                </div>

                <div class="p-3 rounded-xl border {{ $healthChecks['cache_writable'] ? 'bg-tertiary/10 border-tertiary/30' : 'bg-error/10 border-error/30' }} flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base {{ $healthChecks['cache_writable'] ? 'text-tertiary' : 'text-error' }}">memory</span>
                        <span class="font-semibold text-on-surface">bootstrap/cache/</span>
                    </div>
                    <x-ui.badge :color="$healthChecks['cache_writable'] ? 'success' : 'error'">
                        {{ $healthChecks['cache_writable'] ? 'Writable (OK)' : 'Read-Only' }}
                    </x-ui.badge>
                </div>

                <div class="p-3 rounded-xl border {{ $healthChecks['uploads_writable'] ? 'bg-tertiary/10 border-tertiary/30' : 'bg-error/10 border-error/30' }} flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base {{ $healthChecks['uploads_writable'] ? 'text-tertiary' : 'text-error' }}">photo_library</span>
                        <span class="font-semibold text-on-surface">public/uploads/</span>
                    </div>
                    <x-ui.badge :color="$healthChecks['uploads_writable'] ? 'success' : 'error'">
                        {{ $healthChecks['uploads_writable'] ? 'Writable (OK)' : 'Read-Only' }}
                    </x-ui.badge>
                </div>
            </div>

            {{-- Extensions Grid --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 pt-2">
                @foreach ($extensionStatuses as $extKey => $ext)
                    <div class="p-2.5 rounded-xl border {{ $ext['enabled'] ? 'bg-surface-container-low/70 border-surface-container-highest' : 'bg-error/10 border-error/30' }} flex items-center justify-between text-xs">
                        <div class="space-y-0.5 truncate mr-1">
                            <div class="font-bold text-on-surface font-mono text-[11px] truncate">{{ $extKey }}</div>
                            <div class="text-[10px] text-on-surface-variant truncate">{{ $ext['label'] }}</div>
                        </div>
                        @if ($ext['enabled'])
                            <span class="material-symbols-outlined text-tertiary text-base shrink-0">check_circle</span>
                        @else
                            <span class="material-symbols-outlined text-error text-base shrink-0">cancel</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</x-app-layout>
