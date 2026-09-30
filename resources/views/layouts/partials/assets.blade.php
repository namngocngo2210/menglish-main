{{-- CSS dùng chung của các trang Blade còn lại (bản in, trang lỗi) + preload font icon. --}}
<link rel="preload" href="{{ asset('fonts/material-symbols-outlined.woff2') }}" as="font" type="font/woff2" crossorigin>
@vite(['resources/css/app.css'])
