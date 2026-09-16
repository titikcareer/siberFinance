import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';
import { getAllRecords, putRecord, putManyRecords, enqueueOutbox } from '../services/db.js';
import { syncEngine } from '../services/sync.js';

export class AppTransactions extends LitElement {
  static properties = {
    transactions: { state: true },
    accounts: { state: true },
    showForm: { state: true },
    showContext: { state: true },
    saving: { state: true },
  };

  static styles = css`
    :host { display: block; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
    button.primary {
      background: var(--color-primary); color: #052e16; border: none;
      padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer;
    }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.6rem 0.5rem; border-bottom: 1px solid #334155; font-size: 0.88rem; }
    .amount.income { color: var(--color-primary); }
    .amount.expense { color: var(--color-danger); }
    .modal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.6);
      display: flex; align-items: center; justify-content: center;
    }
    .modal { background: var(--color-surface); padding: 1.5rem; border-radius: 12px; width: 100%; max-width: 420px; }
    label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.7rem; margin-bottom: 0.25rem; }
    input, select, textarea {
      width: 100%; padding: 0.55rem 0.7rem; border-radius: 6px;
      border: 1px solid #334155; background: #0f172a; color: var(--color-text);
    }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
    .form-actions button.secondary { background: transparent; border: 1px solid #334155; color: var(--color-text); border-radius: 8px; padding: 0.55rem 1rem; cursor: pointer; }
    .context-toggle { margin-top: 1rem; font-size: 0.85rem; color: var(--color-primary); cursor: pointer; }
    .range-row { display: flex; align-items: center; gap: 0.5rem; }
  `;

  constructor() {
    super();
    this.transactions = [];
    this.accounts = [];
    this.showForm = false;
    this.showContext = false;
    this.saving = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this._load();
    this._onSyncDone = () => this._load();
    window.addEventListener('sync:completed', this._onSyncDone);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    window.removeEventListener('sync:completed', this._onSyncDone);
  }

  async _load() {
    try {
      this.transactions = await api.listTransactions();
      await putManyRecords('transactions', this.transactions);
    } catch {
      this.transactions = await getAllRecords('transactions');
    }

    try {
      this.accounts = await api.listAccounts();
      await putManyRecords('accounts', this.accounts);
    } catch {
      this.accounts = await getAllRecords('accounts');
    }
  }

  render() {
    return html`
      <div class="toolbar">
        <h2>Transaksi</h2>
        <button class="primary" @click=${() => (this.showForm = true)}>+ Catat Transaksi</button>
      </div>

      <table>
        <thead>
          <tr><th>Tanggal</th><th>Deskripsi</th><th>Kategori</th><th>Akun</th><th>Jumlah</th></tr>
        </thead>
        <tbody>
          ${this.transactions.map(
            (t) => html`
              <tr>
                <td>${new Date(t.occurred_at).toLocaleDateString('id-ID')}</td>
                <td>${t.description || '-'}</td>
                <td>${t.category_name || '-'}</td>
                <td>${t.account_name || t.account_id}</td>
                <td class="amount ${t.type}">
                  ${t.type === 'expense' ? '-' : '+'} ${this._formatCurrency(t.amount)}
                </td>
              </tr>
            `
          )}
        </tbody>
      </table>

      ${this.showForm ? this._renderFormModal() : ''}
    `;
  }

  _renderFormModal() {
    return html`
      <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && (this.showForm = false)}>
        <div class="modal">
          <h3>Catat Transaksi Baru</h3>
          <form @submit=${this._onSubmit}>
            <label>Tipe</label>
            <select name="type" required>
              <option value="expense">Pengeluaran</option>
              <option value="income">Pemasukan</option>
              <option value="transfer">Transfer</option>
            </select>

            <label>Akun</label>
            <select name="account_id" required>
              ${this.accounts.map((a) => html`<option value=${a.id}>${a.name}</option>`)}
            </select>

            <label>Jumlah (Rp)</label>
            <input name="amount" type="number" min="1" step="1" required />

            <label>Deskripsi</label>
            <input name="description" type="text" placeholder="Mis. Makan siang" />

            <label>Tanggal & Waktu</label>
            <input name="occurred_at" type="datetime-local" required />

            <div class="context-toggle" @click=${() => (this.showContext = !this.showContext)}>
              ${this.showContext ? '▾' : '▸'} Tambah konteks (mood/urgensi) — fitur micro-journaling
            </div>

            ${this.showContext
              ? html`
                  <div class="range-row">
                    <label style="margin:0">Stres (1-5)</label>
                    <input name="stress_level" type="range" min="1" max="5" />
                  </div>
                  <div class="range-row">
                    <label style="margin:0">Urgensi (1-5)</label>
                    <input name="urgency_level" type="range" min="1" max="5" />
                  </div>
                  <label>Situasi Sosial</label>
                  <select name="social_situation">
                    <option value="sendirian">Sendirian</option>
                    <option value="bersama_teman">Bersama Teman</option>
                    <option value="keluarga">Bersama Keluarga</option>
                    <option value="kerja">Lingkungan Kerja</option>
                  </select>
                `
              : ''}

            <div class="form-actions">
              <button type="button" class="secondary" @click=${() => (this.showForm = false)}>Batal</button>
              <button type="submit" class="primary" ?disabled=${this.saving}>
                ${this.saving ? 'Menyimpan...' : 'Simpan'}
              </button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  async _onSubmit(e) {
    e.preventDefault();
    this.saving = true;
    const form = new FormData(e.target);
    const clientUuid = crypto.randomUUID();

    const payload = {
      type: form.get('type'),
      account_id: form.get('account_id'),
      amount: Number(form.get('amount')),
      description: form.get('description'),
      occurred_at: form.get('occurred_at').replace('T', ' ') + ':00',
      client_uuid: clientUuid,
    };

    if (this.showContext) {
      payload.context = {
        stress_level: Number(form.get('stress_level')) || null,
        urgency_level: Number(form.get('urgency_level')) || null,
        social_situation: form.get('social_situation'),
      };
    }

    try {
      await api.createTransaction(payload);
      await this._load();
    } catch (err) {
      if (err.isOffline) {
        // --- Offline-First path: simpan lokal dulu, antrikan ke outbox untuk di-sync nanti ---
        const localRecord = { id: clientUuid, ...payload, occurred_at: payload.occurred_at, __pendingSync: true };
        await putRecord('transactions', localRecord);
        await enqueueOutbox('transaction', clientUuid, 'create', payload);
        this.transactions = await getAllRecords('transactions');
      } else {
        alert('Gagal menyimpan transaksi: ' + err.message);
      }
    } finally {
      this.saving = false;
      this.showForm = false;
      this.showContext = false;
      syncEngine.syncNow();
    }
  }

  _formatCurrency(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
  }
}

customElements.define('app-transactions', AppTransactions);
