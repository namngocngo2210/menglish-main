@extends('errors.layout')
@section('code', '403')
@section('title', 'Bạn không có quyền truy cập')
{{-- abort(403, '...') của app viết tiếng Việt (có dấu) → hiện nguyên văn; thông báo tiếng Anh của framework/spatie thì thay. --}}
@section('message', preg_match('/[^\x00-\x7F]/', (string) $exception->getMessage()) ? $exception->getMessage() : 'Chức năng này nằm ngoài quyền của tài khoản. Liên hệ quản trị viên nếu bạn cần được cấp quyền.')
