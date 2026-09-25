<?php

namespace Database\Seeders\Concerns;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use ReflectionMethod;
use ReflectionNamedType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;

/**
 * Seeder dữ liệu demo gọi action controller thật với vai trò $user (bỏ qua middleware; controller tự kiểm tra
 * phạm vi / quy tắc nghiệp vụ). Lỗi validate hoặc thông báo lỗi nghiệp vụ (withErrors / flash "error") được ném ra
 * để seed dừng lại thay vì tạo dữ liệu sai.
 */
trait InvokesControllersAsUser
{
    protected function asUser(User $user, string $controller, string $method, array $input = [], array $parameters = []): mixed
    {
        $request = Request::create('/demo-seed', 'POST', $input);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        Auth::setUser($user);
        session()->forget(['errors', 'error']);

        // Action nhận FormRequest (vd. HolidayRequest): để container tự dựng + validate từ request hiện tại.
        $first = (new ReflectionMethod($controller, $method))->getParameters()[0] ?? null;
        $type = $first?->getType();
        $usesFormRequest = $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class);

        $response = app()->call([app($controller), $method], ($usesFormRequest ? [] : ['request' => $request]) + $parameters);

        $errors = session()->pull('errors');
        $flashError = session()->pull('error');
        if ($errors instanceof ViewErrorBag && $errors->any()) {
            throw new RuntimeException(class_basename(static::class).": {$controller}@{$method} báo lỗi: ".implode(' ', $errors->all()));
        }
        if (is_string($flashError) && $flashError !== '') {
            throw new RuntimeException(class_basename(static::class).": {$controller}@{$method} báo lỗi: {$flashError}");
        }

        return $response;
    }
}
