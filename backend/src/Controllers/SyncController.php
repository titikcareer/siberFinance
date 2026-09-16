<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\SyncEncryptionService;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Endpoint sync untuk arsitektur Offline-First:
 *  - PUSH: client mengirim batch perubahan yang terjadi saat offline (dari IndexedDB outbox)
 *  - PULL: client meminta semua perubahan dari device lain sejak `since` timestamp tertentu
 *
 * Strategi konflik: last-write-wins berbasis client_timestamp per field/entity (CRDT-lite).
 * Untuk data finansial yang butuh konsistensi kuat (saldo akun), operasi tetap divalidasi
 * ulang di server (lihat TransactionController) - sync hanya membawa metadata perubahan,
 * bukan sumber kebenaran saldo.
 */
final class SyncController
{
    public function __construct(private SyncEncryptionService $enc)
    {
    }

    public function push(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();
        $deviceId = (string) ($body['device_id'] ?? 'unknown-device');
        $changes = (array) ($body['changes'] ?? []);

        if (empty($changes)) {
            return JsonResponse::error($response, 'changes tidak boleh kosong.', 422);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO sync_changes (user_id, device_id, entity_type, entity_id, operation, payload_encrypted, client_timestamp, server_timestamp)
             VALUES (:uid, :device, :entity_type, :entity_id, :op, :payload, :client_ts, NOW())'
        );

        $accepted = [];
        foreach ($changes as $change) {
            $encryptedPayload = $this->enc->encrypt($change['payload'] ?? []);

            $stmt->execute([
                'uid' => $userId,
                'device' => $deviceId,
                'entity_type' => $change['entity_type'] ?? 'unknown',
                'entity_id' => $change['entity_id'] ?? null,
                'op' => $change['operation'] ?? 'update',
                'payload' => $encryptedPayload,
                'client_ts' => $change['client_timestamp'] ?? date('Y-m-d H:i:s.v'),
            ]);
            $accepted[] = $change['entity_id'] ?? null;
        }

        return JsonResponse::ok($response, [
            'accepted_ids' => $accepted,
            'server_time' => (new \DateTime())->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function pull(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = $request->getQueryParams();
        $since = $params['since'] ?? '1970-01-01 00:00:00';
        $deviceId = $params['device_id'] ?? null;

        $pdo = Database::connection();

        $sql = 'SELECT id, entity_type, entity_id, operation, payload_encrypted, client_timestamp, server_timestamp, device_id
                FROM sync_changes
                WHERE user_id = :uid AND server_timestamp > :since';
        $bind = ['uid' => $userId, 'since' => $since];

        // Jangan kirim balik perubahan milik device yang sama (hindari echo loop)
        if ($deviceId) {
            $sql .= ' AND device_id != :device_id';
            $bind['device_id'] = $deviceId;
        }
        $sql .= ' ORDER BY server_timestamp ASC LIMIT 1000';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        $changes = array_map(function ($r) {
            return [
                'entity_type' => $r['entity_type'],
                'entity_id' => $r['entity_id'],
                'operation' => $r['operation'],
                'payload' => $this->enc->decrypt($r['payload_encrypted']),
                'client_timestamp' => $r['client_timestamp'],
                'server_timestamp' => $r['server_timestamp'],
            ];
        }, $rows);

        return JsonResponse::ok($response, [
            'changes' => $changes,
            'server_time' => (new \DateTime())->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }
}
