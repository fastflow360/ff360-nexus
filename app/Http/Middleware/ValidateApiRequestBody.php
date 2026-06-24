<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiRequestBody
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $payload = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();

        Validator::make($payload, [
            'env' => ['required', 'in:stage,production'],
            'document' => ['required', 'string'],
        ])->validate();

        return $next($request);
    }
}
