<?php

namespace App\Support\Approvals;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Chạy action duyệt / từ chối SẴN CÓ của module (theo tên route) cho một mục, để hộp "Việc cần duyệt" dùng lại
 * đúng luật nghiệp vụ của màn gốc mà không viết lại (cùng cách DemoPhase*Seeder gọi controller).
 *
 *  - Quyền: kiểm tra mọi middleware `can:` của route (kể cả group) — middleware không chạy khi gọi thẳng action.
 *  - Request con: POST tới URL của route, user hiện tại, KHÔNG có header HX-Request (action trả nhánh thường).
 *  - Kết quả: redirect kèm `errors` / `error` → thất bại; kèm `status` / `success` / `info` → thành công. Flash của
 *    action được lấy ra khỏi session để không hiện lại ở trang kế tiếp.
 */
class ControllerActionInvoker
{
    private const SUCCESS_KEYS = ['status', 'success', 'info'];

    public function __construct(private readonly Router $router) {}

    /**
     * @param  array<string, mixed>  $parameters  tham số route
     * @param  array<string, mixed>  $input  dữ liệu form
     */
    public function run(User $user, string $routeName, array $parameters, array $input = []): ApprovalResult
    {
        $route = $this->router->getRoutes()->getByName($routeName);
        if (! $route) {
            return ApprovalResult::failure('Chức năng duyệt không còn tồn tại.');
        }

        foreach ($this->abilities($route->gatherMiddleware()) as $ability) {
            if (! $user->can($ability)) {
                return ApprovalResult::failure('Bạn không có quyền thực hiện thao tác này.');
            }
        }

        $original = app('request');
        $session = $original->hasSession() ? $original->session() : app('session.store');
        $request = Request::create(route($routeName, $parameters), 'POST', $input);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($session);

        $route = (clone $route)->bind($request);
        $request->setRouteResolver(fn () => $route);

        $this->forgetFlash($session); // flash cũ (nếu có) không được lẫn vào kết quả
        $this->swapRequest($request);
        try {
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $response = $route->run();

            return $this->resultFrom($response, $session);
        } catch (ValidationException $e) {
            return ApprovalResult::failure((string) collect($e->errors())->flatten()->first());
        } catch (HttpExceptionInterface $e) {
            return ApprovalResult::failure($e->getMessage() ?: ($e->getStatusCode() === 403 ? 'Bạn không có quyền thực hiện thao tác này.' : 'Không xử lý được mục này.'));
        } catch (ModelNotFoundException) {
            return ApprovalResult::failure('Không tìm thấy dữ liệu (có thể đã bị xoá).');
        } finally {
            $this->forgetFlash($session);
            $this->swapRequest($original);
        }
    }

    /**
     * @param  array<int, mixed>  $middleware
     * @return list<string>
     */
    private function abilities(array $middleware): array
    {
        $abilities = [];
        foreach ($middleware as $item) {
            if (is_string($item) && str_starts_with($item, 'can:')) {
                $abilities[] = explode(',', substr($item, 4), 2)[0];
            }
        }

        return array_values(array_unique($abilities));
    }

    private function resultFrom(mixed $response, Session $session): ApprovalResult
    {
        $errors = $session->get('errors');
        if ($errors instanceof ViewErrorBag && $errors->any()) {
            return ApprovalResult::failure((string) $errors->first());
        }
        if ($errors instanceof MessageBag && $errors->any()) {
            return ApprovalResult::failure((string) $errors->first());
        }
        if ($error = $session->get('error')) {
            return ApprovalResult::failure((string) $error);
        }
        if ($response instanceof Response && $response->getStatusCode() >= 400) {
            return ApprovalResult::failure('Không xử lý được mục này (mã '.$response->getStatusCode().').');
        }

        foreach (self::SUCCESS_KEYS as $key) {
            if ($message = $session->get($key)) {
                return ApprovalResult::success((string) $message);
            }
        }

        return ApprovalResult::success('Đã xử lý.');
    }

    private function forgetFlash(Session $session): void
    {
        try {
            $session->forget(['errors', 'error', '_old_input', ...self::SUCCESS_KEYS]);
        } catch (Throwable $e) {
            Log::warning('Không dọn được flash sau khi duyệt từ inbox: '.$e->getMessage());
        }
    }

    private function swapRequest(Request $request): void
    {
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }
}
