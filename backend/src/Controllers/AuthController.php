<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Services\JwtService;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 8) {
            return JsonResponse::error($response, 'Nama, email valid, dan password (min. 8 karakter) wajib diisi.', 422);
        }

        $pdo = Database::connection();

        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $check->execute(['email' => $email]);
        if ($check->fetch()) {
            return JsonResponse::error($response, 'Email sudah terdaftar.', 409);
        }

        $id = AuditLogger::uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash, created_at, updated_at)
             VALUES (:id, :name, :email, :hash, NOW(), NOW())'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        AuditLogger::log($id, 'create', 'user', $id, null, ['name' => $name, 'email' => $email]);

        return JsonResponse::ok($response, ['id' => $id, 'name' => $name, 'email' => $email], 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            AuditLogger::log(null, 'login_failed', 'user', null, null, ['email' => $email]);
            return JsonResponse::error($response, 'Email atau password salah.', 401);
        }

        $accessToken = $this->jwt->issueAccessToken(['sub' => $user['id'], 'email' => $user['email']]);

        $refreshToken = $this->jwt->generateOpaqueRefreshToken();
        $refreshExpiry = (int) ($_ENV['JWT_REFRESH_EXPIRY_SECONDS'] ?? 1209600);

        $rt = $pdo->prepare(
            'INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, created_at)
             VALUES (:id, :user_id, :hash, NOW() + (:sec || \' seconds\')::interval, NOW())'
        );
        $rt->execute([
            'id' => AuditLogger::uuid(),
            'user_id' => $user['id'],
            'hash' => $this->jwt->hashToken($refreshToken),
            'sec' => $refreshExpiry,
        ]);

        AuditLogger::log($user['id'], 'login', 'user', $user['id']);

        return JsonResponse::ok($response, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ]);
    }

    public function refresh(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $refreshToken = (string) ($body['refresh_token'] ?? '');

        if ($refreshToken === '') {
            return JsonResponse::error($response, 'refresh_token wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $hash = $this->jwt->hashToken($refreshToken);

        $stmt = $pdo->prepare(
            'SELECT rt.*, u.email FROM refresh_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token_hash = :hash AND rt.revoked = 0 AND rt.expires_at > NOW()'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        if (!$row) {
            return JsonResponse::error($response, 'Refresh token tidak valid atau kedaluwarsa.', 401);
        }

        $accessToken = $this->jwt->issueAccessToken(['sub' => $row['user_id'], 'email' => $row['email']]);

        return JsonResponse::ok($response, ['access_token' => $accessToken]);
    }

    public function me(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, email, base_currency, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return JsonResponse::error($response, 'User tidak ditemukan.', 404);
        }

        return JsonResponse::ok($response, $user);
    }
}
