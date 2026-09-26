{{-- Kết quả duyệt / từ chối hàng loạt: tóm tắt + danh sách mục lỗi (htmx swap vào #approval-results). Biến: results, error --}}
@php
    $results ??= [];
    $failed = array_values(array_filter($results, fn ($r) => ! $r['ok']));
    $okCount = count($results) - count($failed);
@endphp
@if ($error)
    <x-ui.alert type="error" class="mb-lg" dismissible>{{ $error }}</x-ui.alert>
@elseif ($results !== [])
    <x-ui.alert :type="$failed ? 'warning' : 'success'" class="mb-lg" dismissible
                :title="'Đã xử lý '.$okCount.'/'.count($results).' mục'.($failed ? ' — '.count($failed).' mục không xử lý được' : '')">
        @if ($failed)
            <ul class="mt-xs list-disc space-y-0.5 pl-md" data-approval-failures>
                @foreach ($failed as $row)
                    <li><span class="font-semibold">{{ $row['title'] }}</span>: {{ $row['message'] }}</li>
                @endforeach
            </ul>
        @endif
    </x-ui.alert>
@endif
