<?php

declare(strict_types=1);

namespace HubOS\Modules\Auth;

use HubOS\Core\Context;
use HubOS\Core\Csrf;
use HubOS\Core\Response;
use HubOS\Core\ValidationException;

final class AccountController
{
    public function showPassword(Context $ctx): Response
    {
        $ctx->requireUser();
        return $ctx->render('account/password', ['title' => 'החלפת סיסמה', 'active' => '', 'errors' => []]);
    }

    public function changePassword(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $email = (string) ($user['email'] ?? '');
        $ip = $ctx->ip();

        if ($ctx->auth->isThrottled($email, $ip)) {
            return $ctx->render('account/password', [
                'title'  => 'החלפת סיסמה',
                'active' => '',
                'errors' => ['current_password' => 'יותר מדי ניסיונות כושלים. נסו שוב בעוד 15 דקות.'],
            ], 429);
        }

        try {
            $ok = (new AccountService($ctx->pdo, $ctx->tenant()))->changePassword(
                $user,
                $ctx->input('current_password'),
                $ctx->input('new_password'),
                $ctx->input('confirm_password')
            );
        } catch (ValidationException $e) {
            return $ctx->render('account/password', ['title' => 'החלפת סיסמה', 'active' => '', 'errors' => $e->errors], 422);
        }

        if (!$ok) {
            $ctx->auth->recordAttempt($email, $ip, false);
            return $ctx->render('account/password', [
                'title'  => 'החלפת סיסמה',
                'active' => '',
                'errors' => ['current_password' => 'הסיסמה הנוכחית שגויה'],
            ], 422);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Csrf::rotate();
        $ctx->flash('הסיסמה הוחלפה בהצלחה ונרשמה ביומן האירועים');
        return Response::redirect('/dashboard');
    }
}
