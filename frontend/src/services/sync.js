import { api } from './api.js';
import { getOutboxItems, clearOutboxItem, getMeta, setMeta, putManyRecords } from './db.js';

const DEVICE_ID_KEY = 'device_id';

function getDeviceId() {
  let id = localStorage.getItem(DEVICE_ID_KEY);
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem(DEVICE_ID_KEY, id);
  }
  return id;
}

/**
 * SyncEngine: dijalankan periodik (interval) & saat event `online`.
 * 1. PUSH: kirim semua item di outbox (perubahan yang dibuat saat offline).
 * 2. PULL: ambil perubahan dari device lain sejak `lastSyncedAt`, terapkan ke IndexedDB lokal.
 *
 * Ini adalah bagian client dari arsitektur "Slim sebagai validator & sync differential,
 * Lit + IndexedDB sebagai pemilik state lokal (offline-first, zero-latency UI)".
 */
export class SyncEngine {
  constructor() {
    this.deviceId = getDeviceId();
    this.isSyncing = false;
  }

  async syncNow() {
    if (this.isSyncing || !navigator.onLine) return;
    this.isSyncing = true;

    try {
      await this.pushOutbox();
      await this.pullChanges();
      window.dispatchEvent(new CustomEvent('sync:completed'));
    } catch (err) {
      console.warn('[SyncEngine] sync gagal, akan dicoba lagi:', err.message);
    } finally {
      this.isSyncing = false;
    }
  }

  async pushOutbox() {
    const items = await getOutboxItems();
    if (items.length === 0) return;

    const changes = items.map((item) => ({
      entity_type: item.entity_type,
      entity_id: item.entity_id,
      operation: item.operation,
      payload: item.payload,
      client_timestamp: item.client_timestamp,
    }));

    await api.syncPush({ device_id: this.deviceId, changes });

    for (const item of items) {
      await clearOutboxItem(item.localId);
    }
  }

  async pullChanges() {
    const since = (await getMeta('lastSyncedAt')) || '1970-01-01 00:00:00';
    const result = await api.syncPull(since, this.deviceId);

    const grouped = { accounts: [], transactions: [], budgets: [], categories: [] };
    for (const change of result.changes) {
      const storeName = this.mapEntityToStore(change.entity_type);
      if (storeName && change.operation !== 'delete') {
        grouped[storeName]?.push(change.payload);
      }
    }

    for (const [store, records] of Object.entries(grouped)) {
      if (records.length > 0) await putManyRecords(store, records);
    }

    await setMeta('lastSyncedAt', result.server_time);
  }

  mapEntityToStore(entityType) {
    const map = {
      account: 'accounts',
      transaction: 'transactions',
      budget: 'budgets',
      category: 'categories',
    };
    return map[entityType] || null;
  }

  startBackgroundSync(intervalMs = 30000) {
    this.syncNow();
    window.addEventListener('online', () => this.syncNow());
    setInterval(() => this.syncNow(), intervalMs);
  }
}

export const syncEngine = new SyncEngine();
