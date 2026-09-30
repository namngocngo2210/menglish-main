{{--
    Trang gốc của mọi màn Inertia (Vue). Nội dung trang do resources/js/app.js dựng từ props của controller
    (Inertia::render / Inertia::modal); khung ứng dụng là resources/js/Layouts/AppLayout.vue.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        {{-- Trạng thái thu gọn sidebar (≥1200px): áp trước khi vẽ để không nháy layout --}}
        <script>try { if (localStorage.getItem('sidebar_collapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}</script>

        {{-- Font & icon tự host qua Vite (resources/css/app.css) --}}
        <link rel="preload" href="{{ asset('fonts/material-symbols-outlined.woff2') }}" as="font" type="font/woff2" crossorigin>
        @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'MEnglish') }}</title>
        </x-inertia::head>
    </head>
    <body class="bg-background font-body-base text-body-base text-on-surface antialiased">
        <x-inertia::app />
    </body>
</html>
