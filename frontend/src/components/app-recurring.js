import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';

const FREQ_LABELS = { daily: 'Harian', weekly: 'Mingguan', monthly: 'Bulanan', yearly: 'Tahunan' };

export class AppRecurring extends LitElement {
  static properties = {
    templates: { state: true },
    accounts: { state: true },
    showForm: { state: true },
    saving: { state: true },
    generating: { state: true },
  };

  static styles = css`
    :host { display: block; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.6rem; }
    button.primary { background: var(--color-primary); color: #052e16; border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
    button.secondary { background: transparent; border: 1px solid #334155; color: var(--color-text); padding: 0.6rem 1rem; border-radius: 8px; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.6rem 0.5rem; border-bottom: 1px solid #334155; font-size: 0.88rem; }
    .remove { color: var(--color-danger); cursor: pointer; font-size: 0.8rem; }
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; }
    .modal { background: var(--color-surface); padding: 1.5rem; border-radius: 12px; width: 100%; max-width: 400px; }
    label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.7rem; margin-bottom: 0.25rem; }
    input, select { width: 100%; padding: 0.55rem 0.7rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: var(--color-text); }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
  `;

  constructor() {
    super();
    this.templates = [];
    this.accounts = [];
    this.showForm = false;
    this.saving = false;
    this.generating = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this._load();
  }

  async _load() {
    this.templates = await api.listRecurring().catch(() => []);
    this.accounts = await api.listAccounts().catch(() => []);
  }

  render() {
    return html`
      <div class="toolbar">
        <h2>Transaksi Berulang</h2>
        <div style="display:flex; gap:0.6rem;">
          <button class="secondary" @click=${this._generateDue} ?disabled=${this.generating}>
            ${this.generating ? 'Memproses...' : '⏱ Generate yang Jatuh Tempo'}
          </button>
          <button class="primary" @click=${() => (this.showForm = true)}>+ Tambah Template</button>
        </div>
      </div>

      <table>
        <thead>
          <tr><th>Deskripsi</th><th>Akun</th><th>Frekuensi</th><th>Jumlah</th><th>Jatuh Tempo Berikutnya</th><th></th></tr>
        </thead>
        <tbody>
          ${this.templates.map(
            (t) => html`
              <tr>
                <td>${t.description || '-'}</td>
                <td>${t.account_name || t.account_id}</td>
                <td>${FREQ_LABELS[t.frequency] || t.frequency}</td>
                <td>${this._formatCurrency(t.amount)}</td>
                <td>${t.next_run_date}</td>
                <td><span class="remove" @click=${() => this._delete(t.id)}>Nonaktifkan</span></td>
              </tr>
            `
          )}
          ${this.templates.length === 0
            ? html`<tr><td colspan="6" style="color: var(--color-text-muted);">Belum ada template recurring.</td></tr>`
            : ''}
        </tbody>
      </table>

      ${this.showForm ? this._renderFormModal() : ''}
    `;
  }

  _renderFormModal() {
    return html`
      <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && (this.showForm = false)}>
        <div class="modal">
          <h3>Template Transaksi Berulang</h3>
          <form @submit=${this._onSubmit}>
            <label>Tipe</label>
            <select name="type" required>
              <option value="expense">Pengeluaran</option>
              <option value="income">Pemasukan</option>
            </select>

            <label>Akun</label>
            <select name="account_id" required>
              ${this.accounts.map((a) => html`<option value=${a.id}>${a.name}</option>`)}
            </select>

            <label>Deskripsi</label>
            <input name="description" placeholder="Mis. Cicilan Motor, Netflix" required />

            <label>Jumlah (Rp)</label>
            <input name="amount" type="number" min="1" required />

            <label>Frekuensi</label>
            <select name="frequency" required>
              ${Object.entries(FREQ_LABELS).map(([v, l]) => html`<option value=${v}>${l}</option>`)}
            </select>

            <label>Tanggal Mulai</label>
            <input name="start_date" type="date" required />

            <label>Tanggal Selesai (opsional)</label>
            <input name="end_date" type="date" />

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
    payload.amount = Number(payload.amount);
    if (!payload.end_date) delete payload.end_date;

    try {
      await api.createRecurring(payload);
      await this._load();
    } catch (err) {
      alert('Gagal menyimpan template: ' + err.message);
    } finally {
      this.saving = false;
      this.showForm = false;
    }
  }

  async _delete(id) {
    if (!confirm('Nonaktifkan template ini?')) return;
    await api.deleteRecurring(id).catch((err) => alert(err.message));
    await this._load();
  }

  async _generateDue() {
    this.generating = true;
    try {
      const result = await api.generateDueRecurring();
      alert(`${result.generated_count} transaksi berhasil digenerate dari template yang jatuh tempo.`);
    } catch (err) {
      alert('Gagal generate: ' + err.message);
    } finally {
      this.generating = false;
    }
  }

  _formatCurrency(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
  }
}

customElements.define('app-recurring', AppRecurring);
