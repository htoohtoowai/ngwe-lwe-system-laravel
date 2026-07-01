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
            $user = $this->tokens->userFromBearer($request->header('Authorization'));
        } catch (AuthenticationException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
