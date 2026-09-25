{{-- Địa chỉ các cơ sở + điện thoại trung tâm (App\Support\CenterInfo), dùng trong phiếu in. --}}
@php
    $centerBranches = \App\Support\CenterInfo::branches();
    $centerPhone = \App\Support\CenterInfo::phone();
@endphp
@foreach ($centerBranches as $centerBranch)
    <div>{{ $loop->first ? 'Địa chỉ: ' : '' }}{{ $centerBranch->name }}: {{ $centerBranch->address }}</div>
@endforeach
@if ($centerPhone)
    <div>Điện thoại: {{ $centerPhone }}</div>
@endif
