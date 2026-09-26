{{-- CSS/JS dùng chung của mọi layout + preload font (icon hiện ngay, không nháy chữ tên icon khi chuyển trang). --}}
<link rel="preload" href="{{ asset('fonts/material-symbols-outlined.woff2') }}" as="font" type="font/woff2" crossorigin>
@vite(['resources/css/app.css', 'resources/js/app.js'])
