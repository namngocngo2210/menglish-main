<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tiện ích cho request htmx (header `HX-Request: true`) — dùng bởi trait RendersModals và bootstrap/app.php.
 */
final class Htmx
{
    /** Không đưa lại vào form khi validate lỗi (giống Handler::$dontFlash). */
    private const DONT_FLASH = ['_token', '_method', 'current_password', 'password', 'password_confirmation'];

    /** Route submit → route hiển thị form tương ứng (quy ước resource). */
    private const FORM_ROUTE = ['store' => 'create', 'update' => 'edit'];

    public static function isRequest(?Request $request = null): bool
    {
        return ($request ?? request())->header('HX-Request') === 'true';
    }

    /**
     * Validate lỗi trong modal: render lại form fragment kèm lỗi, status 422 (htmx swap lại vào #remote-modal-body).
     *
     * Cách làm: route `x.store` → gọi action của `x.create`, `x.update` → `x.edit` (cùng controller, cùng tham số route)
     * ngay trong request hiện tại; lỗi + old input chỉ sống trong request này (session()->now), không rò sang request sau.
     * Trả null (để Laravel xử lý như cũ: redirect back) khi: không phải htmx, route không theo quy ước, hoặc route form
     * thuộc controller khác. Middleware của route form KHÔNG chạy lại nên chỉ áp dụng khi hai route có cùng bộ middleware.
     */
    public static function renderValidationForm(ValidationException $e, Request $request): ?Response
    {
        $route = $request->route();
        if (! self::isRequest($request) || ! $route?->getName() || ! $route->getControllerClass()) {
            return null;
        }

        $segments = explode('.', $route->getName());
        $action = array_pop($segments);
        if (! isset(self::FORM_ROUTE[$action])) {
            return null;
        }

        $router = app(Router::class);
        $formRoute = $router->getRoutes()->getByName(implode('.', [...$segments, self::FORM_ROUTE[$action]]));
        if (! $formRoute || $formRoute->getControllerClass() !== $route->getControllerClass()) {
            return null;
        }
        // Middleware route form không chạy lại → chỉ render khi 2 route có cùng bộ middleware (cùng quyền).
        $middleware = fn ($r) => collect($router->gatherRouteMiddleware($r))->sort()->values()->all();
        if ($middleware($formRoute) !== $middleware($route)) {
            return null;
        }

        $request->session()->now('_old_input', $request->except(self::DONT_FLASH));
        View::share('errors', (new ViewErrorBag)->put($e->errorBag, $e->validator->errors()));

        $formRoute = clone $formRoute;
        $formRoute->parameters = $route->parameters();

        return Router::toResponse($request, $formRoute->run())->setStatusCode(422);
    }
}
