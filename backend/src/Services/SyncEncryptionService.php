<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Backend HANYA berperan sebagai validator, enkriptor, dan penyinkron perbedaan
 * status (differential sync). Data sesungguhnya "dimiliki" oleh client (IndexedDB),
 * server menyimpan payload terenkripsi di sync_changes sebagai append-only log,
 * sehingga bahkan operator database tidak bisa membaca isi transaksi mentah.
 */
final class SyncEncryptionService
{
    private string $key;

    public function __construct()
    {
        $base64Key = $_ENV['SYNC_ENCRYPTION_KEY'] ?? '';
        $this->key = $base64Key !== '' ? base64_decode($base64Key) : str_repeat("\0", 32);
    }

    public function encrypt(array $payload): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            json_encode($payload),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $encoded): array
    {
        $raw = base64_decode($encoded);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);

        $json = openssl_decrypt($cipherText, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        return $json !== false ? (json_decode($json, true) ?? []) : [];
    }
}
