<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'MEnglish') }}</title>

        {{-- Favicon --}}
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        {{-- Font & icon được tự host qua Vite (resources/css/app.css) --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-background font-body-base text-body-base text-on-surface antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-background">
            <div>
                <a href="/">
                    <img src="{{ asset('images/menglish-logo.png') }}" alt="MEnglish" class="h-20 w-20 rounded-xl bg-white object-contain shadow-sm" width="80" height="80">
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-surface-container-lowest border border-surface-container-highest shadow-level-2 overflow-hidden sm:rounded-xl">
                {{ $slot }}
            </div>

            <div class="py-6 text-center font-caption text-caption text-on-surface-variant">
                <span>Phát triển bởi</span>
                <a href="https://vmst.vn" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary hover:underline">VMST Media</a>
            </div>
        </div>
    </body>
</html>
