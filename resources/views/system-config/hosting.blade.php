<x-app-layout>
    <x-ui.page-header title="Thông Số Hosting & Máy Chủ (Server Diagnostics)" icon="dns">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="refresh" onclick="window.location.reload();">Làm mới thông số</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        {{-- Top Nav Tabs matching System Config --}}
        <div class="flex items-center gap-2 border-b border-gray-200 pb-2 overflow-x-auto">
            <a 
                href="{{ route('system-config.bank-accounts') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">account_balance_wallet</span>
                <span>Tài khoản Ngân hàng</span>
            </a>

            <a 
                href="{{ route('system-config.debt-reminders') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">notifications_active</span>
                <span>Mẫu nhắc nợ</span>
            </a>

            <a 
                href="{{ route('system-config.ticket-emails') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 font-semibold shrink-0"
            >
                <span class="material-symbols-outlined text-base">mail</span>
                <span>Email nhận Ticket</span>
            </a>

            <a 
                href="{{ route('system-config.hosting') }}"
                class="px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 border border-primary-container bg-primary-container text-white font-bold shadow-xs shrink-0"
            >
                <span class="material-symbols-outlined text-base">dns</span>
                <span>Hosting &amp; Máy chủ</span>
            </a>
        </div>

        {{-- 1. Top Quota & Storage Highlights --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            {{-- Disk Storage Usage Card --}}
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 md:p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">hard_drive</span>
                        </div>
                        <div>
                            <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Dung Lượng Lưu Trữ Website Đang Sử Dụng</h2>
                            <p class="text-[11px] text-gray-500">Thống kê dung lượng thực tế website sử dụng và tỷ trọng từng thành phần</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 border border-indigo-200/80 text-indigo-700 font-mono text-xs">
                        <span class="text-gray-500 font-sans text-[11px] font-semibold">Đã sử dụng:</span>
                        <strong class="font-black text-indigo-900 text-sm">{{ $storageStats['total_used_formatted'] }}</strong>
                    </div>
                </div>

                {{-- Multi-component Composition Bar --}}
                <div class="space-y-2">
                    <div class="w-full h-3 bg-gray-100 rounded-full overflow-hidden flex p-0.5 gap-0.5">
                        <div class="h-full rounded-l-full bg-indigo-600 transition-all duration-500" style="width: {{ max(1, $storageStats['uploads_percent']) }}%" title="Media Uploads: {{ $storageStats['uploads_size_formatted'] }} ({{ $storageStats['uploads_percent'] }}%)"></div>
                        <div class="h-full bg-amber-500 transition-all duration-500" style="width: {{ max(1, $storageStats['source_vendor_percent']) }}%" title="Mã nguồn &amp; Vendor: {{ $storageStats['source_vendor_size_formatted'] }} ({{ $storageStats['source_vendor_percent'] }}%)"></div>
                        <div class="h-full bg-purple-600 transition-all duration-500" style="width: {{ max(1, $storageStats['storage_percent']) }}%" title="Storage &amp; Logs: {{ $storageStats['storage_size_formatted'] }} ({{ $storageStats['storage_percent'] }}%)"></div>
                        <div class="h-full rounded-r-full bg-emerald-600 transition-all duration-500" style="width: {{ max(1, $storageStats['db_percent']) }}%" title="Database MySQL: {{ $storageStats['db_size_mb'] }} MB ({{ $storageStats['db_percent'] }}%)"></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-gray-500 font-mono">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-indigo-600 inline-block shrink-0"></span>
                            <span>Uploads: <strong class="text-gray-900">{{ $storageStats['uploads_size_formatted'] }}</strong> ({{ $storageStats['uploads_percent'] }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-500 inline-block shrink-0"></span>
                            <span>Mã nguồn &amp; Vendor: <strong class="text-gray-900">{{ $storageStats['source_vendor_size_formatted'] }}</strong> ({{ $storageStats['source_vendor_percent'] }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-purple-600 inline-block shrink-0"></span>
                            <span>Storage &amp; Logs: <strong class="text-gray-900">{{ $storageStats['storage_size_formatted'] }}</strong> ({{ $storageStats['storage_percent'] }}%)</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-600 inline-block shrink-0"></span>
                            <span>Database: <strong class="text-gray-900">{{ $storageStats['db_size_mb'] }} MB</strong> ({{ $storageStats['db_percent'] }}%)</span>
                        </span>
                    </div>
                </div>

                {{-- Partition Breakdown --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 border-t border-gray-100">
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-gray-400 font-bold uppercase">Media Uploads</span>
                            <span class="text-[10px] font-bold text-indigo-600 font-mono">{{ $storageStats['uploads_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-indigo-700 font-mono">{{ $storageStats['uploads_size_formatted'] }}</div>
                        <div class="text-[9px] text-gray-400 truncate">public/uploads/</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-gray-400 font-bold uppercase">Database MySQL</span>
                            <span class="text-[10px] font-bold text-emerald-600 font-mono">{{ $storageStats['db_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-emerald-700 font-mono">{{ $storageStats['db_size_mb'] }} MB</div>
                        <div class="text-[9px] text-gray-400 truncate">Data + Indexes</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-gray-400 font-bold uppercase">Storage &amp; Logs</span>
                            <span class="text-[10px] font-bold text-purple-600 font-mono">{{ $storageStats['storage_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-purple-700 font-mono">{{ $storageStats['storage_size_formatted'] }}</div>
                        <div class="text-[9px] text-gray-400 truncate">storage/logs/cache</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-gray-400 font-bold uppercase">Source &amp; Vendor</span>
                            <span class="text-[10px] font-bold text-amber-600 font-mono">{{ $storageStats['source_vendor_percent'] }}%</span>
                        </div>
                        <div class="text-xs font-black text-amber-700 font-mono">{{ $storageStats['source_vendor_size_formatted'] }}</div>
                        <div class="text-[9px] text-gray-400 truncate">app + vendor</div>
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
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                    <span class="material-symbols-outlined text-indigo-600">tune</span>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Cấu Hình PHP Runtime &amp; Giới Hạn Tài Nguyên</h2>
                </div>

                <div class="divide-y divide-gray-100 text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Giới hạn RAM PHP (Memory Limit)</span>
                        <span class="font-mono font-bold text-gray-900 bg-gray-100 px-2 py-0.5 rounded">{{ $serverSpecs['memory_limit'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Dung lượng Upload file tối đa (Upload Max)</span>
                        <span class="font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded">{{ $serverSpecs['upload_max_filesize'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Dung lượng POST tối đa (Post Max Size)</span>
                        <span class="font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded">{{ $serverSpecs['post_max_size'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Thời gian thực thi tối đa (Max Execution Time)</span>
                        <span class="font-mono font-bold text-gray-900">{{ $serverSpecs['max_execution_time'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Số biến gửi tối đa (Max Input Vars)</span>
                        <span class="font-mono font-bold text-gray-900">{{ $serverSpecs['max_input_vars'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">PHP SAPI Interface</span>
                        <span class="font-mono text-gray-700">{{ $serverSpecs['php_sapi'] }}</span>
                    </div>
                </div>
            </div>

            {{-- Web Server & Operating System --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                    <span class="material-symbols-outlined text-orange-600">computer</span>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Hệ Điều Hành &amp; Môi Trường Máy Chủ</h2>
                </div>

                <div class="divide-y divide-gray-100 text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Máy chủ Web (Web Server Daemon)</span>
                        <span class="font-bold text-gray-900 text-right">{{ $serverSpecs['web_server_name'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Chuỗi Server Header Gốc (Raw Software)</span>
                        <span class="font-mono text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded text-right truncate max-w-[240px]">{{ $serverSpecs['server_software'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Bảng điều khiển Hosting (Control Panel)</span>
                        <span class="font-semibold text-gray-800 text-right">{{ $serverSpecs['hosting_panel'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Hệ điều hành máy chủ (OS)</span>
                        <span class="font-mono text-gray-800 text-right truncate max-w-[240px]">{{ $serverSpecs['os_name'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Tên máy chủ (Hostname) / Server IP</span>
                        <span class="font-mono font-bold text-gray-900">{{ $serverSpecs['hostname'] }} ({{ $serverSpecs['server_ip'] }})</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Cổng kết nối (Port) &amp; Giao thức</span>
                        <span class="font-mono text-gray-700">Port {{ $serverSpecs['server_port'] }} ({{ $serverSpecs['server_protocol'] }})</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Giao thức bảo mật (SSL/TLS)</span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-bold {{ $healthChecks['https_active'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-100 text-gray-600' }}">
                            {{ $healthChecks['https_active'] ? 'HTTPS (Được mã hóa SSL)' : 'HTTP (Local Development)' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. Laravel Framework & Database Specs --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            {{-- Laravel Framework Status --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                    <span class="material-symbols-outlined text-rose-600">deployed_code</span>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Cấu Hình Framework Laravel</h2>
                </div>

                <div class="divide-y divide-gray-100 text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Phiên bản Laravel (Version)</span>
                        <span class="font-mono font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded">v{{ $serverSpecs['laravel_version'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Môi trường hoạt động (Environment)</span>
                        <span class="font-mono font-bold uppercase {{ $serverSpecs['app_env'] === 'production' ? 'text-emerald-700 bg-emerald-50' : 'text-amber-700 bg-amber-50' }} px-2 py-0.5 rounded">
                            {{ $serverSpecs['app_env'] }}
                        </span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Chế độ Debug (APP_DEBUG)</span>
                        <span class="font-mono font-bold {{ $serverSpecs['app_debug'] ? 'text-amber-600' : 'text-emerald-600' }}">
                            {{ $serverSpecs['app_debug'] ? 'BẬT (True)' : 'TẮT (False - An toàn)' }}
                        </span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Driver Session / Cache / Queue</span>
                        <span class="font-mono text-gray-800">{{ $serverSpecs['session_driver'] }} / {{ $serverSpecs['cache_driver'] }} / {{ $serverSpecs['queue_driver'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Hệ thống gửi Email (Mail Transport)</span>
                        <span class="font-mono text-indigo-700 font-semibold">{{ $serverSpecs['mail_driver'] }} ({{ $serverSpecs['mail_host'] }})</span>
                    </div>
                </div>
            </div>

            {{-- Database Connection Specs --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                    <span class="material-symbols-outlined text-emerald-600">database</span>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Cơ Sở Dữ Liệu MySQL / MariaDB</h2>
                </div>

                <div class="divide-y divide-gray-100 text-xs">
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Hệ quản trị CSDL &amp; Phiên bản</span>
                        <span class="font-mono font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">{{ $serverSpecs['db_version'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Tên Database đang kết nối</span>
                        <span class="font-mono font-bold text-gray-900">{{ $serverSpecs['db_database'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Địa chỉ máy chủ CSDL (DB Host)</span>
                        <span class="font-mono text-gray-700">{{ $serverSpecs['db_host'] }}</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Tổng số bảng dữ liệu (Tables)</span>
                        <span class="font-mono font-bold text-indigo-600">{{ $serverSpecs['db_tables_count'] }} bảng</span>
                    </div>
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="text-gray-500 font-medium">Dung lượng CSDL (Data + Index)</span>
                        <span class="font-mono font-black text-gray-900">{{ $storageStats['db_size_mb'] }} MB</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. PHP Extensions & Health Checks --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 space-y-4">
            <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-teal-600">health_and_safety</span>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Trạng Thái PHP Extensions &amp; Quyền Ghi Thư Mục</h2>
                </div>
                <span class="text-[11px] text-gray-400">Kiểm tra tự động toàn bộ thư viện cần thiết</span>
            </div>

            {{-- Permission checks --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-3 rounded-xl border {{ $healthChecks['storage_writable'] ? 'bg-emerald-50/50 border-emerald-200' : 'bg-rose-50 border-rose-200' }} flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base {{ $healthChecks['storage_writable'] ? 'text-emerald-600' : 'text-rose-600' }}">folder</span>
                        <span class="font-semibold text-gray-800">storage/</span>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $healthChecks['storage_writable'] ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white' }}">
                        {{ $healthChecks['storage_writable'] ? 'Writable (OK)' : 'Read-Only' }}
                    </span>
                </div>

                <div class="p-3 rounded-xl border {{ $healthChecks['cache_writable'] ? 'bg-emerald-50/50 border-emerald-200' : 'bg-rose-50 border-rose-200' }} flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base {{ $healthChecks['cache_writable'] ? 'text-emerald-600' : 'text-rose-600' }}">memory</span>
                        <span class="font-semibold text-gray-800">bootstrap/cache/</span>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $healthChecks['cache_writable'] ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white' }}">
                        {{ $healthChecks['cache_writable'] ? 'Writable (OK)' : 'Read-Only' }}
                    </span>
                </div>

                <div class="p-3 rounded-xl border {{ $healthChecks['uploads_writable'] ? 'bg-emerald-50/50 border-emerald-200' : 'bg-rose-50 border-rose-200' }} flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base {{ $healthChecks['uploads_writable'] ? 'text-emerald-600' : 'text-rose-600' }}">photo_library</span>
                        <span class="font-semibold text-gray-800">public/uploads/</span>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $healthChecks['uploads_writable'] ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white' }}">
                        {{ $healthChecks['uploads_writable'] ? 'Writable (OK)' : 'Read-Only' }}
                    </span>
                </div>
            </div>

            {{-- Extensions Grid --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 pt-2">
                @foreach ($extensionStatuses as $extKey => $ext)
                    <div class="p-2.5 rounded-xl border {{ $ext['enabled'] ? 'bg-slate-50/70 border-slate-200' : 'bg-rose-50 border-rose-200' }} flex items-center justify-between text-xs">
                        <div class="space-y-0.5 truncate mr-1">
                            <div class="font-bold text-gray-800 font-mono text-[11px] truncate">{{ $extKey }}</div>
                            <div class="text-[10px] text-gray-500 truncate">{{ $ext['label'] }}</div>
                        </div>
                        @if ($ext['enabled'])
                            <span class="material-symbols-outlined text-emerald-600 text-base shrink-0">check_circle</span>
                        @else
                            <span class="material-symbols-outlined text-rose-600 text-base shrink-0">cancel</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</x-app-layout>
