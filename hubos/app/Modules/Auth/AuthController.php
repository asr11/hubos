<?php

declare(strict_types=1);

namespace HubOS\Modules\Auth;

use HubOS\Core\Context;
use HubOS\Core\Response;

final class AuthController
{
    public function showLogin(Context $ctx): Response
    {
        if ($ctx->user() !== null) {
            return Response::redirect('/dashboard');
        }
        return $ctx->render('auth/login', ['title' => 'כניסה', 'email' => '', 'error' => null], 200, 'layouts/auth');
    }

    public function login(Context $ctx): Response
    {
        $email = trim($ctx->input('email'));
        $result = $ctx->auth->attempt($email, $ctx->input('password'), $ctx->ip());
        if ($result['ok']) {
            return Response::redirect('/dashboard');
        }
        $throttled = ($result['error'] ?? '') === 'throttled';
        return $ctx->render('auth/login', [
            'title' => 'כניסה',
            'email' => $email,
            'error' => $throttled ? 'יותר מדי ניסיונות כושלים. נסו שוב בעוד 15 דקות.' : 'אימייל או סיסמה שגויים.',
        ], $throttled ? 429 : 401, 'layouts/auth');
    }

    public function logout(Context $ctx): Response
    {
        $ctx->auth->logout();
        return Response::redirect('/login');
    }
}
