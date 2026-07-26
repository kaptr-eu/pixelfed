<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class AikidoMiddleware
{
    public function handle($request, Closure $next)
    {
        if (!extension_loaded('aikido')) {
            return $next($request);
        }

        $userId = Auth::id();

        if ($userId) {
            \aikido\set_user($userId, Auth::user()?->username);
        }

        $decision = \aikido\should_block_request();
        if ($decision->block) {
            if ($decision->type == 'ratelimited') {
                abort(429, 'You are rate limited, please wait a minute before trying again.');
            } else {
                abort(403, 'You are blocked, contact samuel@aikido.dev if you think this is a mistake.');
            }
        }

        return $next($request);
    }
}
