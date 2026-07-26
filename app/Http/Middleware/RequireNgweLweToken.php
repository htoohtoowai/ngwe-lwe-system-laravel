<?php

namespace App\Http\Middleware;

use App\Services\NgweLweTokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireNgweLweToken
{
    public function __construct(
        private readonly NgweLweTokenService $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $authorization = $request->header('Authorization');

            if ($authorization === null && ! $request->is('api/*')) {
                $token = $request->cookie('ngwe_lwe_api_token');
                $authorization = is_string($token) && $token !== ''
                    ? 'Bearer '.rawurldecode($token)
                    : null;
            }

            $user = $this->tokens->userFromBearer($authorization);
        } catch (AuthenticationException $e) {
            // API callers need the machine-readable auth error. Browser and
            // Inertia visits must receive a redirect so Inertia never tries
            // to render a plain JSON payload as a page response.
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json(['message' => $e->getMessage()], 401);
            }

            return redirect()->route('login', [
                'return' => $request->getRequestUri(),
            ]);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
