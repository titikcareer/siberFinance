<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

final class JwtService
{
    private string $secret;
    private int $expiry;

    public function __construct()
    {
        $this->secret = $_ENV['JWT_SECRET'] ?? 'insecure-default-change-me';
        $this->expiry = (int) ($_ENV['JWT_EXPIRY_SECONDS'] ?? 3600);
    }

    /**
     * @param array<string,mixed> $claims
     */
    public function issueAccessToken(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $this->expiry,
            'typ' => 'access',
        ]);

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * @return array<string,mixed>|null null jika invalid/expired
     */
    public function verify(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            return (array) $decoded;
        } catch (ExpiredException $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function generateOpaqueRefreshToken(): string
    {
        return bin2hex(random_bytes(48));
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
