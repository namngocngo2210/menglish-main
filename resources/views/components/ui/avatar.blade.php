{{--
    <x-ui.avatar> — avatar chữ cái đầu (không dùng ảnh ngoài). Màu nền cố định theo tên.
    Props: name (bắt buộc), size: sm (32px) | md (40px, mặc định) | lg (64px)
    Ví dụ: <x-ui.avatar :name="$user->name" size="sm" />   ("Nguyễn Anh Tuấn" => "NA")
--}}
@props(['name' => '', 'size' => 'md'])

@php
    $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $initials = mb_strtoupper(collect(array_slice($words, 0, 2))->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    $tones = [
        'bg-primary-fixed text-on-primary-fixed',
        'bg-secondary-fixed text-on-secondary-fixed',
        'bg-tertiary-fixed text-on-tertiary-fixed',
        'bg-surface-variant text-on-surface-variant',
    ];
    $tone = $tones[abs(crc32((string) $name)) % count($tones)];
    $sizes = ['sm' => 'h-8 w-8 text-body-small', 'md' => 'h-10 w-10 text-body-medium', 'lg' => 'h-16 w-16 text-h3'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 select-none items-center justify-center rounded-full font-bold ' . $tone . ' ' . ($sizes[$size] ?? $sizes['md'])]) }}
      title="{{ $name }}" aria-hidden="true">{{ $initials }}</span>
