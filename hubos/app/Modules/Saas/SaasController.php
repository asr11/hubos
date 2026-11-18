<?php

declare(strict_types=1);

namespace HubOS\Modules\Saas;

use HubOS\Core\Context;
use HubOS\Core\HttpException;
use HubOS\Core\Response;
use HubOS\Core\ValidationException;
use HubOS\Modules\Partners\PartnerService;
use InvalidArgumentException;

final class SaasController
{
    public function index(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $svc = new SaasService($ctx->pdo, $ctx->tenant());
        if (!SaasService::isPlatformOwner($user)) {
            throw new HttpException(403, 'ניהול ארגוני SaaS שמור לבעלי הפלטפורמה בלבד');
        }

        return $ctx->render('saas/index', [
            'title'               => 'ניהול ארגונים ורישוי SaaS',
            'active'              => 'saas',
            'tenants'             => $svc->listTenants($user),
            'publicSignupEnabled' => $svc->isPublicSignupEnabled(),
        ]);
    }

    public function storeTenant(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $svc = new SaasService($ctx->pdo, $ctx->tenant());
        try {
            $res = $svc->provisionTenant($user, [
                'company_name'   => $ctx->input('company_name'),
                'slug'           => $ctx->input('slug'),
                'owner_name'     => $ctx->input('owner_name'),
                'owner_email'    => $ctx->input('owner_email'),
                'owner_password' => $ctx->input('owner_password'),
                'plan'           => $ctx->input('plan'),
                'billing_url'    => $ctx->input('billing_url'),
            ]);
            $ctx->flash('ארגון SaaS חדש הוקם בהצלחה במרחב מבודד (Tenant #' . $res['tenant_id'] . ')');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/settings/saas');
    }

    /** @param array<string, string> $params */
    public function updateTenant(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $svc = new SaasService($ctx->pdo, $ctx->tenant());
        try {
            $svc->updateTenantPlan($user, (int) $params['id'], [
                'plan'        => $ctx->input('plan'),
                'status'      => $ctx->input('status'),
                'billing_url' => $ctx->input('billing_url'),
            ]);
            $ctx->flash('הגדרות החבילה והסטטוס של הארגון עודכנו');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/settings/saas');
    }

    public function togglePublicSignup(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $svc = new SaasService($ctx->pdo, $ctx->tenant());
        $enabled = $ctx->input('enabled') === '1';
        $svc->setPublicSignup($user, $enabled);
        $ctx->flash($enabled ? 'הרשמת ניסיון ציבורית (/signup) הופעלה' : 'הרשמת ניסיון ציבורית (/signup) ננעלה');
        return Response::redirect('/settings/saas');
    }

    public function saveMorning(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $svc = new SaasService($ctx->pdo, $ctx->tenant());
        try {
            $svc->saveMorningCredentials($user, $ctx->input('morning_key_id'), $ctx->input('morning_key_secret'));
            $ctx->flash('מפתחות Morning (קריאה בלבד) הוצפנו ב-AES-256-GCM ונשמרו לארגון זה');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/settings/users');
    }

    public function submitPartnerLead(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        try {
            (new PartnerService($ctx->tenant()))->createLead($user, [
                'name'  => $ctx->input('name'),
                'phone' => $ctx->input('phone'),
                'email' => $ctx->input('email'),
                'notes' => $ctx->input('notes'),
            ]);
            $ctx->flash('הליד נרשם בהצלחה ושויך אוטומטית לחשבון השותף שלך!');
        } catch (ValidationException $e) {
            $ctx->flashError(implode(' · ', $e->errors));
        } catch (HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/dashboard');
    }

    public function showSignup(Context $ctx): Response
    {
        if ($ctx->user() !== null) {
            return Response::redirect('/dashboard');
        }
        $svc = new SaasService($ctx->pdo, new \HubOS\Core\Tenant($ctx->pdo, 1));
        if (!$svc->isPublicSignupEnabled()) {
            throw new HttpException(404, 'ההרשמה העצמאית סגורה כעת — לקבלת סביבת ארגון פנה להאב מערכות מתקדמות');
        }
        return $ctx->render('saas/signup', ['title' => 'פתיחת סביבת ארגון חדשה', 'error' => null, 'old' => []], 200, 'layouts/auth');
    }

    public function signup(Context $ctx): Response
    {
        $svc = new SaasService($ctx->pdo, new \HubOS\Core\Tenant($ctx->pdo, 1));
        if (!$svc->isPublicSignupEnabled()) {
            throw new HttpException(404, 'ההרשמה העצמאית סגורה כעת');
        }
        try {
            $email = strtolower(trim($ctx->input('owner_email')));
            $pw = $ctx->input('owner_password');
            $svc->provisionTenant(null, [
                'company_name'   => $ctx->input('company_name'),
                'slug'           => $ctx->input('slug'),
                'owner_name'     => $ctx->input('owner_name'),
                'owner_email'    => $email,
                'owner_password' => $pw,
                'plan'           => 'trial',
            ]);
            $attempt = $ctx->auth->attempt($email, $pw, $ctx->ip());
            if ($attempt['ok']) {
                return Response::redirect('/dashboard');
            }
            return Response::redirect('/login');
        } catch (InvalidArgumentException $e) {
            return $ctx->render('saas/signup', [
                'title' => 'פתיחת סביבת ארגון חדשה',
                'error' => $e->getMessage(),
                'old'   => [
                    'company_name' => $ctx->input('company_name'),
                    'slug'         => $ctx->input('slug'),
                    'owner_name'   => $ctx->input('owner_name'),
                    'owner_email'  => $ctx->input('owner_email'),
                ],
            ], 422, 'layouts/auth');
        }
    }
}
