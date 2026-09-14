<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 要求当前已认证用户必须是管理员。
 *
 * 必须与 auth:sanctum 串联使用（由后者负责身份认证），
 * 本中间件只做角色校验，未通过时统一返回 403 JSON。
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_admin) {
            abort(Response::HTTP_FORBIDDEN, '仅管理员可执行该操作。');
        }

        return $next($request);
    }
}
