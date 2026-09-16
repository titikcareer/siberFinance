import { openDB } from 'idb';

const DB_NAME = 'finance-app-db';
const DB_VERSION = 1;

/**
 * Skema IndexedDB (client-side source of truth untuk mode Offline-First).
 * - accounts, transactions, categories, budgets: cache lokal dari server, dibaca UI secara instan (zero-latency)
 * - outbox: antrian perubahan yang belum berhasil dikirim ke server (dipakai SyncService)
 * - meta: menyimpan `lastSyncedAt` per entity untuk differential sync
 */
export async function getDb() {
  return openDB(DB_NAME, DB_VERSION, {
    upgrade(db) {
      if (!db.objectStoreNames.contains('accounts')) {
        db.createObjectStore('accounts', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('transactions')) {
        const store = db.createObjectStore('transactions', { keyPath: 'id' });
        store.createIndex('by-occurred-at', 'occurred_at');
        store.createIndex('by-account', 'account_id');
      }
      if (!db.objectStoreNames.contains('categories')) {
        db.createObjectStore('categories', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('budgets')) {
        db.createObjectStore('budgets', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('outbox')) {
        const outbox = db.createObjectStore('outbox', { keyPath: 'localId', autoIncrement: true });
        outbox.createIndex('by-entity', 'entity_type');
      }
      if (!db.objectStoreNames.contains('meta')) {
        db.createObjectStore('meta', { keyPath: 'key' });
      }
    },
  });
}

export async function putRecord(storeName, record) {
  const db = await getDb();
  return db.put(storeName, record);
}

export async function putManyRecords(storeName, records) {
  const db = await getDb();
  const tx = db.transaction(storeName, 'readwrite');
  await Promise.all(records.map((r) => tx.store.put(r)));
  await tx.done;
}

export async function getAllRecords(storeName) {
  const db = await getDb();
  return db.getAll(storeName);
}

export async function deleteRecord(storeName, id) {
  const db = await getDb();
  return db.delete(storeName, id);
}

export async function enqueueOutbox(entityType, entityId, operation, payload) {
  const db = await getDb();
  return db.add('outbox', {
    entity_type: entityType,
    entity_id: entityId,
    operation,
    payload,
    client_timestamp: new Date().toISOString(),
  });
}

export async function getOutboxItems() {
  const db = await getDb();
  return db.getAll('outbox');
}

export async function clearOutboxItem(localId) {
  const db = await getDb();
  return db.delete('outbox', localId);
}

export async function getMeta(key) {
  const db = await getDb();
  const row = await db.get('meta', key);
  return row?.value ?? null;
}

export async function setMeta(key, value) {
  const db = await getDb();
  return db.put('meta', { key, value });
}
