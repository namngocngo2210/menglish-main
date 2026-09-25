<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        <x-ui.page-header title="Nhập khách hàng loạt từ Excel"
            description="Tải file .xlsx / .csv (dòng 1 là tiêu đề). Hệ thống kiểm tra từng dòng (họ tên, SĐT Việt Nam, trùng trong file / trùng CRM, email) trước khi nhập.">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="download" :href="route('crm.import.template')">Tải file mẫu</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <form method="POST" action="{{ route('crm.import.preview') }}" enctype="multipart/form-data"
              class="grid grid-cols-1 gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-2 lg:grid-cols-4">
            @csrf
            <x-ui.field label="File khách hàng (.xlsx, .csv)" name="file" required>
                <input type="file" name="file" required accept=".xlsx,.xls,.csv"
                       class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest p-xs text-body-small">
            </x-ui.field>
            <x-ui.select name="branch_id" label="Chi nhánh nhận khách" required placeholder="-- Chọn chi nhánh --"
                :options="$branches->pluck('name', 'id')" :value="$preview['branch_id'] ?? ($branches->count() === 1 ? $branches->first()->id : null)" />
            @if ($canAssign)
                <x-ui.select name="assigned_user_id" label="Sales phụ trách" placeholder="-- Tôi phụ trách --"
                    :options="$salesUsers->mapWithKeys(fn ($u) => [$u->id => $u->name])" :value="$preview['assigned_user_id'] ?? null" />
            @else
                <x-ui.field label="Sales phụ trách">
                    <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm">{{ auth()->user()->name }}</div>
                </x-ui.field>
            @endif
            <x-ui.input name="default_source" label="Nguồn mặc định" placeholder="VD: Sự kiện Offline" hint="Dùng khi dòng không có cột Nguồn." />
            <div class="md:col-span-2 lg:col-span-4 flex items-center justify-between gap-md">
                <p class="text-caption text-on-surface-variant">Cột nhận diện: Họ tên*, Số điện thoại*, Tên phụ huynh, SĐT phụ huynh, Email, Ngày sinh, Giới tính, Địa chỉ, Nguồn, Khóa học quan tâm, Ghi chú. Tối đa 1.000 dòng.</p>
                <x-ui.button type="submit" icon="fact_check">Kiểm tra dữ liệu</x-ui.button>
            </div>
        </form>

        @if ($preview)
            @php
                $rows = collect($preview['rows']);
                $validCount = $rows->filter(fn ($r) => empty($r['errors']))->count();
                $errorCount = $rows->count() - $validCount;
            @endphp
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <x-ui.stat-card label="Tổng số dòng" :value="$rows->count()" icon="table_rows" />
                <x-ui.stat-card label="Hợp lệ — sẽ nhập" :value="$validCount" tone="success" icon="check_circle" />
                <x-ui.stat-card label="Lỗi — bỏ qua" :value="$errorCount" tone="error" icon="error" />
            </div>

            <x-ui.data-table min-width="1000px">
                <x-slot:header>
                    <div>
                        <h2 class="font-h3 text-h3 text-on-surface">Xem trước: {{ $preview['file_name'] }}</h2>
                        <p class="text-caption text-on-surface-variant">Chi nhánh: <strong>{{ $preview['branch_name'] }}</strong> · Sales phụ trách: <strong>{{ $preview['assigned_user_name'] }}</strong></p>
                    </div>
                    <form method="POST" action="{{ route('crm.import.store') }}" class="flex items-center gap-sm">
                        @csrf
                        <x-ui.button type="submit" name="cancel" value="1" variant="ghost">Hủy</x-ui.button>
                        <x-ui.button type="submit" icon="upload" :disabled="$validCount === 0">Nhập {{ $validCount }} khách hợp lệ</x-ui.button>
                    </form>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Dòng</th>
                            <th>Họ tên</th>
                            <th>SĐT</th>
                            <th>Phụ huynh</th>
                            <th>Email</th>
                            <th>Nguồn</th>
                            <th>Khóa quan tâm</th>
                            <th>Kết quả kiểm tra</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="{{ empty($row['errors']) ? '' : 'bg-rose-50' }}">
                                <td class="font-code">{{ $row['line'] }}</td>
                                <td class="font-semibold">{{ $row['data']['name'] ?? '—' }}</td>
                                <td class="font-code">{{ $row['data']['phone'] ?? '—' }}</td>
                                <td>{{ $row['data']['parent_name'] ?? '' }}<div class="font-code text-caption">{{ $row['data']['parent_phone'] ?? '' }}</div></td>
                                <td>{{ $row['data']['email'] ?? '' }}</td>
                                <td>{{ $row['data']['source'] ?? '' }}</td>
                                <td>{{ $row['data']['course_interest'] ?? '' }}</td>
                                <td>
                                    @if (empty($row['errors']))
                                        <x-ui.badge color="success">Hợp lệ</x-ui.badge>
                                    @else
                                        <ul class="list-disc pl-md text-caption text-error">
                                            @foreach ($row['errors'] as $error)<li>{{ $error }}</li>@endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        @endif
    </div>
</x-app-layout>
