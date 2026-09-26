<x-app-layout>
    <x-ui.page-header title="Xin điều chỉnh tiến độ" description="Gửi yêu cầu điều chỉnh thời gian cho các lớp hoặc chặng học hiện tại." :back="route('syllabus.versions')">
        <x-slot:actions>
            @can('syllabus.approve_adjustment')
                <x-ui.button icon="rule" :href="route('syllabus.adjustment-requests')">Duyệt yêu cầu tiến độ</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>


    {{-- Mockup 03_Cong_Giao_Vien/14: form Gửi yêu cầu (lớp/chặng đang mở, lý do, số buổi 1/2) + Danh sách yêu cầu đã gửi. --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-5 flex flex-col gap-4 min-w-0">
            @if ($classes->isEmpty())
                <section class="bg-surface-container-lowest rounded-xl border border-outline-variant p-xl shadow-sm flex flex-col items-center text-center gap-sm">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-surface-container-high text-on-surface-variant"><span class="material-symbols-outlined text-[28px]">info</span></span>
                    <h3 class="font-h3 text-h3 text-on-surface">Không có chặng học nào đang mở</h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">Bạn hiện không có chặng học nào đang mở để gửi yêu cầu.</p>
                </section>
            @else
            <section class="bg-surface-container-lowest rounded-xl border border-outline-variant p-lg shadow-sm">
                <h2 class="font-h3 text-h3 text-on-surface mb-md">Gửi yêu cầu</h2>

                <form action="{{ route('syllabus.adjustment-requests.store') }}" method="POST" class="space-y-md" x-data="{ sessions: @js((string) old('extra_sessions', '')) }">
                    @csrf
                    <x-ui.select label="Lớp học / Chặng học" name="class_id" required>
                        <option disabled @selected(! old('class_id')) value="">Chọn lớp/chặng cần xin giãn</option>
                        @foreach ($classes as $cl)
                            <option value="{{ $cl->id }}" @selected((string) old('class_id') === (string) $cl->id)>{{ $cl->name }} - {{ $openAssignments[$cl->id]?->stage?->label ?? $openAssignments[$cl->id]?->stage_name }} ({{ $cl->code }})</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.textarea name="reason" label="Lý do xin điều chỉnh" required rows="4" placeholder="Vui lòng ghi rõ lý do (VD: Học sinh chưa nắm vững kiến thức, cháy giáo án do mất điện...)" />

                    <x-ui.field label="Số buổi cần thêm" name="extra_sessions" required hint="Khi được duyệt, hệ thống thêm đúng số buổi này vào cuối lịch học của lớp (theo TKB, bỏ qua ngày nghỉ).">
                        <div class="grid grid-cols-2 gap-md">
                            @foreach ([1, 2] as $n)
                                <label class="relative flex cursor-pointer items-center justify-center rounded-lg border px-md py-md font-body-medium text-body-medium transition-all"
                                       :class="sessions === '{{ $n }}' ? 'border-primary-container ring-1 ring-primary-container bg-surface-container-low' : 'border-outline-variant bg-surface-container-lowest'">
                                    <input type="radio" name="extra_sessions" value="{{ $n }}" x-model="sessions" required class="sr-only">
                                    <span>{{ $n }} buổi</span>
                                    <span class="material-symbols-outlined absolute right-sm text-[18px] text-primary transition-opacity" :class="sessions === '{{ $n }}' ? 'opacity-100' : 'opacity-0'">check_circle</span>
                                </label>
                            @endforeach
                        </div>
                    </x-ui.field>

                    <x-ui.alert type="warning" class="font-body-small text-body-small">
                        <p><strong>Quy định SLA:</strong> Yêu cầu được Ban Học thuật xem xét và phản hồi trong vòng {{ \App\Models\SyllabusAdjustmentRequest::SLA_HOURS }} giờ.</p>
                    </x-ui.alert>

                    <x-ui.button type="submit" icon="send" class="w-full">Gửi yêu cầu</x-ui.button>
                </form>
            </section>
            @endif
        </div>

        <div class="lg:col-span-7 flex flex-col gap-4 min-w-0">
            <x-ui.data-table min-width="640px">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Danh sách yêu cầu đã gửi</h2>
                        <x-ui.badge>{{ $requests->total() }} yêu cầu</x-ui.badge>
                    </div>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Ngày gửi</th>
                            <th scope="col">Lớp / Chặng</th>
                            <th scope="col">Lý do</th>
                            <th scope="col" class="text-center">Số buổi thêm</th>
                            <th scope="col">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($requests as $req)
                            <tr>
                                <td class="font-mono text-on-surface-variant whitespace-nowrap">{{ $req->created_at->format('d/m/Y') }}</td>
                                <td class="font-body-medium text-body-small text-on-surface">{{ $req->class_stage_label }}</td>
                                <td class="max-w-[220px]">
                                    <p class="truncate" title="{{ $req->reason }}">{{ $req->reason }}</p>
                                    @if ($req->status === 'approved' && $req->applied_note)
                                        <p class="font-caption text-caption text-tertiary whitespace-normal">{{ $req->applied_note }}</p>
                                    @endif
                                </td>
                                <td class="text-center font-semibold">{{ $req->extra_sessions ?: 0 }}</td>
                                <td class="whitespace-nowrap">
                                    <x-ui.badge :color="['pending' => 'warning', 'approved' => 'success', 'rejected' => 'error'][$req->status] ?? 'neutral'">{{ ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối'][$req->status] ?? $req->status_label }}</x-ui.badge>
                                    @if ($req->status === 'rejected' && $req->rejection_reason)
                                        <p class="mt-1 max-w-[200px] whitespace-normal font-caption text-caption text-error">{{ $req->rejection_reason }}</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state icon="speed" title="Bạn chưa gửi yêu cầu xin điều chỉnh tiến độ nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$requests" unit="yêu cầu" /></x-slot:footer>
            </x-ui.data-table>
        </div>
    </div>
</x-app-layout>
