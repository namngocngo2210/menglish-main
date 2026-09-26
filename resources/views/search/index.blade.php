<x-app-layout title="Tìm kiếm">
    <x-ui.page-header title="Tìm kiếm" description="Tìm màn hình theo tên, khách hàng, học viên và lớp học theo tên, mã hoặc số điện thoại (trong phạm vi bạn được xem)." />

    <form method="GET" action="{{ route('search') }}" role="search" class="mb-lg flex max-w-xl gap-sm">
        <x-ui.input name="q" type="search" :value="$term" icon="search" placeholder="Nhập tên, mã hoặc SĐT (ít nhất 2 ký tự)..." aria-label="Từ khóa tìm kiếm" class="flex-1" />
        <x-ui.button type="submit">Tìm</x-ui.button>
    </form>

    @if (mb_strlen($term) < 2)
        <div class="rounded-xl border border-surface-container-highest bg-surface">
            <x-ui.empty-state icon="search" title="Nhập từ khóa để tìm kiếm" description="Từ khóa cần ít nhất 2 ký tự." />
        </div>
    @elseif ($searched === [] && $screens === [])
        <div class="rounded-xl border border-surface-container-highest bg-surface">
            <x-ui.empty-state icon="lock" title="Bạn chưa có quyền tìm kiếm" description="Tài khoản của bạn chưa được cấp quyền xem khách hàng, học viên hoặc lớp học." />
        </div>
    @elseif ($total === 0)
        <div class="rounded-xl border border-surface-container-highest bg-surface">
            <x-ui.empty-state icon="search_off" title="Không tìm thấy kết quả" description="Không có kết quả cho “{{ $term }}”. Thử từ khóa khác." />
        </div>
    @else
        <p class="mb-md font-body-medium text-body-medium text-on-surface-variant">Tìm thấy {{ $total }} kết quả cho “<strong class="text-on-surface">{{ $term }}</strong>”.</p>

        <div class="space-y-lg">
            @if ($screens !== [])
                <section class="rounded-xl border border-surface-container-highest bg-surface p-md" data-search-screens>
                    <h2 class="mb-sm font-h3 text-h3 text-on-surface">Màn hình ({{ count($screens) }})</h2>
                    <ul class="grid grid-cols-1 gap-xs sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($screens as $screen)
                            <li>
                                <a href="{{ $screen['url'] }}" class="flex items-center gap-sm rounded-lg px-sm py-xs font-body-medium text-body-medium text-primary hover:bg-surface-container-low">
                                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">arrow_forward</span>
                                    <span class="truncate">{{ $screen['title'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (in_array('customers', $searched, true) && $results['customers']->isNotEmpty())
                <x-ui.data-table>
                    <x-slot:header>
                        <h2 class="font-h3 text-h3 text-on-surface">Khách hàng CRM ({{ $results['customers']->count() }})</h2>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Khách hàng</th><th>SĐT</th><th>Cơ sở</th><th>Giai đoạn</th></tr></thead>
                        <tbody>
                            @foreach ($results['customers'] as $customer)
                                <tr>
                                    <td>
                                        <a href="{{ route('crm.customers.show', $customer->id) }}" class="font-semibold text-primary hover:underline">{{ $customer->name }}</a>
                                        <span class="block font-code text-caption text-on-surface-variant">{{ $customer->code }}</span>
                                    </td>
                                    <td class="font-code">{{ $customer->phone ?: '—' }}</td>
                                    <td>{{ $customer->branch?->name ?? 'Chưa gán chi nhánh' }}</td>
                                    <td>{{ $customer->stage_label }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if (in_array('students', $searched, true) && $results['students']->isNotEmpty())
                <x-ui.data-table>
                    <x-slot:header>
                        <h2 class="font-h3 text-h3 text-on-surface">Học viên ({{ $results['students']->count() }})</h2>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Học viên</th><th>SĐT</th><th>Lớp hiện tại</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                            @foreach ($results['students'] as $student)
                                <tr>
                                    <td>
                                        <a href="{{ route('students.show', $student->id) }}" class="font-semibold text-primary hover:underline">{{ $student->name }}</a>
                                        <span class="block font-code text-caption text-on-surface-variant">{{ $student->code }}</span>
                                    </td>
                                    <td class="font-code">{{ $student->phone ?: '—' }}</td>
                                    <td>{{ $student->currentClass?->name ?? 'Chưa xếp lớp' }}</td>
                                    <td>{{ $student->status_label }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            @if (in_array('classes', $searched, true) && $results['classes']->isNotEmpty())
                <x-ui.data-table>
                    <x-slot:header>
                        <h2 class="font-h3 text-h3 text-on-surface">Lớp học ({{ $results['classes']->count() }})</h2>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Lớp</th><th>Cơ sở</th><th>Giáo viên</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                            @foreach ($results['classes'] as $class)
                                <tr>
                                    <td>
                                        <a href="{{ route('classes.show', $class->id) }}" class="font-semibold text-primary hover:underline">{{ $class->name }}</a>
                                        <span class="block font-code text-caption text-on-surface-variant">{{ $class->code }}</span>
                                    </td>
                                    <td>{{ $class->branch?->name ?? 'Chưa gán chi nhánh' }}</td>
                                    <td>{{ $class->teacher?->name ?? 'Chưa phân công' }}</td>
                                    <td>{{ \App\Support\StatusLabel::for($class->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif
        </div>
    @endif
</x-app-layout>
