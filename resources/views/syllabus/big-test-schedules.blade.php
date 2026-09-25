<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">alarm</span>
                        Nhắc Lịch &amp; Giám Sát Tổ Chức Big Test
                    </h1>
                    <p class="text-xs text-gray-500">Lịch thi giữa kỳ, phân công phòng thi, giám thị coi thi và gửi nhắc lịch cho học viên</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.big-tests.distribution') }}" class="px-3.5 py-1.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">add_circle</span>
                    <span>Tạo Đợt Big Test</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        {{-- Đợt thi trong 7 ngày tới (hệ thống tự nhắc giáo viên lúc 07:45 hằng ngày) --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">notification_important</span>
                    <h2 class="text-sm font-bold text-gray-900">Sắp thi trong 7 ngày</h2>
                    <x-ui.badge color="warning">{{ $upcoming->count() }} đợt</x-ui.badge>
                    <x-ui.badge color="error">{{ $upcoming->filter(fn ($t) => now()->startOfDay()->diffInDays($t->scheduled_at->copy()->startOfDay()) <= 2)->count() }} khẩn cấp (1-2 ngày)</x-ui.badge>
                </div>
                <p class="text-[11px] text-gray-500">Giáo viên lớp được tự động nhắc trước 7 ngày; nút "Nhắc lịch" gửi nhắc tới học viên.</p>
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Mã đợt thi</th>
                        <th class="py-3 px-4">Lớp</th>
                        <th class="py-3 px-4">Ngày thi</th>
                        <th class="py-3 px-4">Trạng thái đề</th>
                        <th class="py-3 px-4 text-right">Số ngày còn lại</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($upcoming as $t)
                        @php($left = (int) now()->startOfDay()->diffInDays($t->scheduled_at->copy()->startOfDay()))
                        <tr>
                            <td class="py-3 px-4 font-mono font-bold">{{ $t->code }}</td>
                            <td class="py-3 px-4">{{ $t->classModel?->name }}</td>
                            <td class="py-3 px-4 font-mono">{{ $t->scheduled_at->format('d/m/Y H:i') }}</td>
                            <td class="py-3 px-4">
                                @if ($t->is_distributed)
                                    <x-ui.badge color="success">Đã duyệt đề</x-ui.badge>
                                @else
                                    <x-ui.badge color="error">Chưa duyệt đề</x-ui.badge>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-right font-bold {{ $left <= 2 ? 'text-rose-600' : 'text-gray-700' }}">{{ $left }} ngày</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-400">Không có đợt thi nào trong 7 ngày tới.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Lớp thi</th>
                        <th class="py-3 px-4">Tên bài thi</th>
                        <th class="py-3 px-4">Ngày &amp; Giờ thi</th>
                        <th class="py-3 px-4">Phòng thi &amp; Cơ sở</th>
                        <th class="py-3 px-4">Giám thị coi thi</th>
                        <th class="py-3 px-4">Mật mã thi</th>
                        <th class="py-3 px-4 text-right">Nhắc lịch</th>
                        <th class="py-3 px-4 text-right">Bảng điểm</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($bigTests as $bt)
                        <tr class="hover:bg-purple-50/10 transition">
                            <td class="py-3.5 px-4 font-bold text-gray-900">{{ $bt->classModel?->name }}</td>
                            <td class="py-3.5 px-4 font-semibold text-primary">{{ $bt->title }}</td>
                            <td class="py-3.5 px-4 font-mono font-medium text-gray-600">{{ $bt->scheduled_at ? $bt->scheduled_at->format('d/m/Y H:i') : '—' }}</td>
                            <td class="py-3.5 px-4">{{ $bt->room }} · {{ $bt->classModel?->branch?->name ?? '—' }}</td>
                            <td class="py-3.5 px-4 font-medium text-gray-800">{{ $bt->proctor?->name ?? '—' }}</td>
                            <td class="py-3.5 px-4 font-mono font-bold text-emerald-600">{{ $bt->passcodeVisibleTo(auth()->user()) ? $bt->passcode : '••••••' }}</td>
                            <td class="py-3.5 px-4 text-right">
                                @can('syllabus.approve_adjustment')
                                <form action="{{ route('syllabus.big-tests.remind', $bt->id) }}" method="POST" class="inline" data-confirm="Gửi nhắc lịch {{ $bt->title }} tới toàn bộ học viên của lớp {{ $bt->classModel?->name }}?">
                                    @csrf
                                    <button type="submit" class="px-3 py-1 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs transition inline-flex items-center gap-1" title="Gửi thông báo nhắc lịch vào Cổng PH/HS">
                                        <span class="material-symbols-outlined text-[14px]">notifications_active</span>
                                        Nhắc lịch
                                    </button>
                                </form>
                                @endcan
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <a href="{{ route('syllabus.big-tests.results', $bt->id) }}" class="px-3 py-1 rounded-lg bg-orange-50 hover:bg-orange-100 text-primary font-bold text-xs transition inline-block">
                                    Xem điểm
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-400 text-xs">Chưa có lịch thi Big Test nào.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-gray-100"><x-ui.pagination :paginator="$bigTests" unit="đợt thi" /></div>
        </div>
    </div>
</x-app-layout>
