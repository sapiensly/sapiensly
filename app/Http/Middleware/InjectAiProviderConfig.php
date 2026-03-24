<?php

namespace App\Http\Middleware;

use App\Services\AiProviderService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectAiProviderConfig
{
    public function __construct(
        private AiProviderService $aiProviderService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $this->aiProviderService->applyRuntimeConfig($user);
        }

        return $next($request);
    }
}
