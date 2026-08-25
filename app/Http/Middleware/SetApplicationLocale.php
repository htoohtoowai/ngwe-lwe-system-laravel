<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    /**
     * Keep server-side validation and flash messages aligned with the Vue locale.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->cookie('ngwe_lwe_locale', 'mm');

        App::setLocale(in_array($locale, ['en', 'mm'], true) ? $locale : 'mm');

        return $next($request);
    }
}
