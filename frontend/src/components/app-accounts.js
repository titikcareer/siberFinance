import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';
import { getAllRecords, putManyRecords } from '../services/db.js';

const TYPE_LABELS = {
  bank: '🏦 Bank',
  cash: '💵 Cash',
  ewallet: '📱 E-Wallet',
  investment: '📈 Investasi',
  liability: '⚠️ Kewajiban/Hutang',
};

export class AppAccounts extends LitElement {
  static properties = {
    accounts: { state: true },
    showForm: { state: true },
    saving: { state: true },
  };

  static styles = css`
    :host { display: block; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
    button.primary { background: var(--color-primary); color: #052e16; border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
    .card { background: var(--color-surface); border-radius: 12px; padding: 1.1rem; }
    .card .type { font-size: 0.75rem; color: var(--color-text-muted); }
    .card .name { font-weight: 600; margin: 0.25rem 0; }
    .card .balance { font-size: 1.25rem; font-weight: 700; }
    .card .balance.negative { color: var(--color-danger); }
    .delete-btn { color: var(--color-danger); font-size: 0.78rem; cursor: pointer; margin-top: 0.5rem; }
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; }
    .modal { background: var(--color-surface); padding: 1.5rem; border-radius: 12px; width: 100%; max-width: 380px; }
    label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.7rem; margin-bottom: 0.25rem; }
    input, select { width: 100%; padding: 0.55rem 0.7rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: var(--color-text); }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
    .form-actions button.secondary { background: transparent; border: 1px solid #334155; color: var(--color-text); border-radius: 8px; padding: 0.55rem 1rem; cursor: pointer; }
  `;

  constructor() {
    super();
    this.accounts = [];
    this.showForm = false;
    this.saving = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this._load();
  }

  async _load() {
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
        <h2>Akun & Aset</h2>
        <button class="primary" @click=${() => (this.showForm = true)}>+ Tambah Akun</button>
      </div>

      <div class="grid">
        ${this.accounts.map(
          (a) => html`
            <div class="card">
              <div class="type">${TYPE_LABELS[a.type] || a.type}</div>
              <div class="name">${a.name}</div>
              <div class="balance ${Number(a.balance) < 0 ? 'negative' : ''}">${this._formatCurrency(a.balance)}</div>
              <div class="delete-btn" @click=${() => this._delete(a.id)}>Arsipkan</div>
            </div>
          `
        )}
      </div>

      ${this.showForm ? this._renderFormModal() : ''}
    `;
  }

  _renderFormModal() {
    return html`
      <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && (this.showForm = false)}>
        <div class="modal">
          <h3>Tambah Akun</h3>
          <form @submit=${this._onSubmit}>
            <label>Nama Akun</label>
            <input name="name" placeholder="Mis. BCA, GoPay, Dompet" required />

            <label>Tipe</label>
            <select name="type" required>
              ${Object.entries(TYPE_LABELS).map(([value, label]) => html`<option value=${value}>${label}</option>`)}
            </select>

            <label>Saldo Awal (Rp)</label>
            <input name="balance" type="number" step="1" value="0" required />

            <div class="form-actions">
              <button type="button" class="secondary" @click=${() => (this.showForm = false)}>Batal</button>
              <button type="submit" class="primary" ?disabled=${this.saving}>${this.saving ? 'Menyimpan...' : 'Simpan'}</button>
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
    const payload = Object.fromEntries(form.entries());
    payload.balance = Number(payload.balance);

    try {
      await api.createAccount(payload);
      await this._load();
    } catch (err) {
      alert('Gagal menambah akun: ' + err.message);
    } finally {
      this.saving = false;
      this.showForm = false;
    }
  }

  async _delete(id) {
    if (!confirm('Arsipkan akun ini? Riwayat transaksi tetap tersimpan.')) return;
    try {
      await api.deleteAccount(id);
      await this._load();
    } catch (err) {
      alert('Gagal mengarsipkan akun: ' + err.message);
    }
  }

  _formatCurrency(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
  }
}

customElements.define('app-accounts', AppAccounts);
