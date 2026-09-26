<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
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
     * Tìm route hiển thị form (cùng controller, GET) theo thứ tự:
     *   1. Quy ước resource: `x.store` → `x.create`, `x.update` → `x.edit`.
     *   2. Màn cha: `x.<hành động>` → `x` — luồng nhiều bước trên cùng 1 màn, vd. `crm.import.preview` / `crm.import.store`
     *      → `crm.import`, `tuition.import.store` → `tuition.import`.
     *   3. Tài nguyên con trên màn chi tiết: `x.<con>.<hành động>` → `x.show`, vd. `tickets.messages.store` → `tickets.show`
     *      (ô trả lời nằm trong modal xem ticket).
     * Action của route form được gọi ngay trong request hiện tại (cùng tham số route); lỗi + old input chỉ sống trong
     * request này (session()->now), không rò sang request sau.
     * Trả null (để Laravel xử lý như cũ: redirect back) khi: không phải htmx, không tìm được route form, route form cần
     * middleware mà route submit không có (middleware route form KHÔNG chạy lại), hoặc route form trả redirect.
     */
    public static function renderValidationForm(ValidationException $e, Request $request): ?Response
    {
        $route = $request->route();
        if (! self::isRequest($request) || ! $route?->getName() || ! $route->getControllerClass()) {
            return null;
        }

        $router = app(Router::class);
        $formRoute = self::formRouteFor($route, $router);
        if (! $formRoute) {
            return null;
        }

        $request->session()->now('_old_input', self::withoutFiles($request->except(self::DONT_FLASH)));
        View::share('errors', (new ViewErrorBag)->put($e->errorBag, $e->validator->errors()));

        $formRoute = clone $formRoute;
        $formRoute->parameters = $route->parameters();

        $response = Router::toResponse($request, $formRoute->run());

        return $response->isRedirection() ? null : $response->setStatusCode(422);
    }

    /** Route hiển thị form của route submit (xem renderValidationForm). */
    private static function formRouteFor(Route $route, Router $router): ?Route
    {
        $segments = explode('.', $route->getName());
        $action = array_pop($segments);
        $candidates = array_filter([
            isset(self::FORM_ROUTE[$action]) ? implode('.', [...$segments, self::FORM_ROUTE[$action]]) : null,
            $segments ? implode('.', $segments) : null,
            count($segments) >= 2 ? implode('.', [...array_slice($segments, 0, -1), 'show']) : null,
        ]);

        // Middleware route submit (đã chạy) phải bao trùm middleware route form → không vượt quyền khi render form.
        $middleware = fn (Route $r) => collect($router->gatherRouteMiddleware($r))->unique()->all();
        foreach ($candidates as $name) {
            $formRoute = $router->getRoutes()->getByName($name);
            if ($formRoute
                && in_array('GET', $formRoute->methods(), true)
                && $formRoute->getControllerClass() === $route->getControllerClass()
                && array_diff($middleware($formRoute), $middleware($route)) === []) {
                return $formRoute;
            }
        }

        return null;
    }

    /** Bỏ file upload khỏi old input (giống RedirectResponse::withInput). */
    private static function withoutFiles(array $input): array
    {
        return array_filter(
            array_map(fn ($v) => is_array($v) ? self::withoutFiles($v) : $v, $input),
            fn ($v) => ! $v instanceof UploadedFile,
        );
    }
}
