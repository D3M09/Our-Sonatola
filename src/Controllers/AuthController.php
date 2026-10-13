<?php
declare(strict_types=1);

final class AuthController
{
    private PDO $pdo;
    private AuthService $svc;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->svc = new AuthService($pdo);
    }

    public function register(Request $req, array $params): void
    {
        RateLimit::check('reg:' . $req->ip(), 10, 3600);
        $out = $this->svc->register($req->body, $req);
        Response::json($out, 201);
    }

    public function login(Request $req, array $params): void
    {
        $email = (string) ($req->body['email'] ?? '');
        $pass = (string) ($req->body['password'] ?? '');
        if ($email === '' || $pass === '') {
            throw AppException::unprocessable('Email and password are required.');
        }
        $user = $this->svc->login($email, $pass, $req);
        Auth::sessionLogin((int) $user['id']);
        $token = $this->svc->issueApiToken((int) $user['id']);
        unset($user['password_hash']);
        Response::json(['user' => $user, 'token' => $token, 'csrf' => Csrf::token()]);
    }

    public function logout(Request $req, array $params): void
    {
        $bearer = $req->bearerToken();
        if ($bearer) {
            Auth::revokeToken($this->pdo, $bearer);
        }
        Auth::sessionLogout();
        Response::json(['message' => 'Logged out.']);
    }

    public function me(Request $req, array $params): void
    {
        AuthMiddleware::requireAuth($req);
        Response::json($req->user);
    }

    public function updateProfile(Request $req, array $params): void
    {
        AuthMiddleware::requireAuth($req);
        Csrf::requireValid($req);
        $name = trim((string) ($req->body['name'] ?? ''));
        $phone = trim((string) ($req->body['phone'] ?? ''));
        if ($name !== '') {
            if (mb_strlen($name) > 100) {
                throw AppException::unprocessable('Name too long.');
            }
            $st = $this->pdo->prepare('UPDATE users SET name=:n WHERE id=:id');
            $st->execute([':n' => $name, ':id' => $req->user['id']]);
        }
        if ($phone !== '') {
            if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
                throw AppException::unprocessable('Invalid phone.');
            }
            $st = $this->pdo->prepare('UPDATE users SET phone=:p WHERE id=:id');
            $st->execute([':p' => $phone, ':id' => $req->user['id']]);
        }
        Audit::log($this->pdo, (int) $req->user['id'], 'user.profile_update', 'users', $req->user['id'], $req);
        Response::json(['message' => 'Profile updated.']);
    }

    public function requestReset(Request $req, array $params): void
    {
        RateLimit::check('reset:' . $req->ip(), 5, 3600);
        $email = (string) ($req->body['email'] ?? '');
        if ($email === '') {
            throw AppException::unprocessable('Email is required.');
        }
        $this->svc->requestPasswordReset($email, $req);
        Response::json(['message' => 'If the email exists, a reset link has been issued.']);
    }

    public function performReset(Request $req, array $params): void
    {
        $token = (string) ($req->body['token'] ?? '');
        $pass = (string) ($req->body['password'] ?? '');
        if ($token === '' || $pass === '') {
            throw AppException::unprocessable('Token and new password are required.');
        }
        $this->svc->resetPassword($token, $pass, $req);
        Response::json(['message' => 'Password has been reset.']);
    }
}
