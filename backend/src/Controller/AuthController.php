<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Json;
use App\Support\Totp;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Crypto $crypto,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        \App\Middleware\SessionAuthMiddleware::startSession();
        $body = (array) $request->getParsedBody();
        $username = Validator::str($body['username'] ?? '', 64);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');

        if ($username === '' || $password === '') {
            return Json::error($response, 422, 'validation_failed', 'username and password required');
        }
        if ($this->users->isLockedOut($username, $ip, Config::envInt('LOGIN_MAX_ATTEMPTS', 5))) {
            return Json::error($response, 429, 'rate_limited', 'too many failed attempts — try again in 15 minutes');
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || !password_verify($password, $user->password_hash)) {
            $this->users->recordLogin($username, $ip, false);
            return Json::error($response, 401, 'unauthorized', 'invalid credentials');
        }

        // Second factor when enrolled.
        if ($user->totp_secret !== null) {
            $code = Validator::str($body['totp_code'] ?? '', 6);
            if ($code === '') {
                return Json::error($response, 401, 'totp_required', 'two-factor code required');
            }
            if (!Totp::verify($this->crypto->unseal($user->totp_secret), $code)) {
                $this->users->recordLogin($username, $ip, false);
                return Json::error($response, 401, 'unauthorized', 'invalid two-factor code');
            }
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user->id;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->users->recordLogin($username, $ip, true);

        return Json::data($response, [
            'username' => $user->username,
            'role' => $user->role,
            'csrf' => $_SESSION['csrf'],
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        session_destroy();
        return Json::data($response, ['logged_out' => true]);
    }

    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        return Json::data($response, [
            'username' => $user->username,
            'role' => $user->role,
            'totp_enabled' => $user->totp_secret !== null,
            'csrf' => $request->getAttribute('csrf'),
        ]);
    }

    /** Step 1: generate a secret, keep it pending in the session until confirmed. */
    public function totpSetup(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user->totp_secret !== null) {
            return Json::error($response, 409, 'conflict', 'two-factor is already enabled');
        }
        $secret = Totp::generateSecret();
        $_SESSION['totp_pending'] = $secret;
        return Json::data($response, [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->username),
            'note' => 'scan/import in your authenticator app, then POST /auth/totp/confirm with a code',
        ]);
    }

    /** Step 2: prove the app works before enabling — prevents lockouts. */
    public function totpConfirm(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $pending = $_SESSION['totp_pending'] ?? null;
        if (!is_string($pending)) {
            return Json::error($response, 409, 'conflict', 'no pending setup — call /auth/totp/setup first');
        }
        $code = Validator::str(((array) $request->getParsedBody())['code'] ?? '', 6);
        if (!Totp::verify($pending, $code)) {
            return Json::error($response, 422, 'validation_failed', 'code does not match — check app and clock');
        }
        $this->users->setTotpSecret((int) $user->id, $this->crypto->seal($pending));
        unset($_SESSION['totp_pending']);
        return Json::data($response, ['totp_enabled' => true]);
    }

    /** Disabling requires a valid current code (stolen-session protection). */
    public function totpDisable(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user->totp_secret === null) {
            return Json::error($response, 409, 'conflict', 'two-factor is not enabled');
        }
        $code = Validator::str(((array) $request->getParsedBody())['code'] ?? '', 6);
        if (!Totp::verify($this->crypto->unseal($user->totp_secret), $code)) {
            return Json::error($response, 422, 'validation_failed', 'invalid code');
        }
        $this->users->setTotpSecret((int) $user->id, null);
        return Json::data($response, ['totp_enabled' => false]);
    }
}
