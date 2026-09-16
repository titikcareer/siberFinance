<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

final class AuditLogger
{
    public static function log(
        ?string $userId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $oldValue = null,
        ?array $newValue = null
    ): void {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
             VALUES (:id, :user_id, :action, :entity_type, :entity_id, :old_value, :new_value, :ip, :ua, NOW())'
        );

        $stmt->execute([
            'id'          => self::uuid(),
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_value'   => $oldValue !== null ? json_encode($oldValue) : null,
            'new_value'   => $newValue !== null ? json_encode($newValue) : null,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua'          => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public static function uuid(): string
    {
        // Fallback UUID v4 generator (hindari dependency tambahan ramsey/uuid agar composer.json ringan)
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
