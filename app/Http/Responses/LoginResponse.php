<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 200);
        }

        $response = redirect()->intended(route('dashboard'));

        // Ensure redirect Location is a relative path to avoid host mismatches
        // (e.g. localhost vs 127.0.0.1) which can prevent cookies from being sent.
        $location = $response->headers->get('Location');
        if ($location) {
            $parts = parse_url($location);
            $path = ($parts['path'] ?? '/')
                . (isset($parts['query']) ? '?'.$parts['query'] : '')
                . (isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

            $response->headers->set('Location', $path);
        }

        return $response;
    }
}
