import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';
import { getAllRecords, putManyRecords } from '../services/db.js';

const TYPE_LABELS = { income: 'Pemasukan', expense: 'Pengeluaran', transfer: 'Transfer' };
const PALETTE = ['#22c55e', '#ef4444', '#3b82f6', '#f59e0b', '#a855f7', '#14b8a6', '#ec4899', '#84cc16'];

export class AppCategories extends LitElement {
  static properties = {
    categories: { state: true },
    showForm: { state: true },
    saving: { state: true },
  };

  static styles = css`
    :host { display: block; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
    button.primary { background: var(--color-primary); color: #052e16; border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
    .group { margin-bottom: 1.5rem; }
    .group h3 { font-size: 0.95rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
    .chip-row { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .chip { display: flex; align-items: center; gap: 0.4rem; background: var(--color-surface); padding: 0.4rem 0.75rem; border-radius: 999px; font-size: 0.85rem; }
    .dot { width: 10px; height: 10px; border-radius: 50%; }
    .chip .remove { cursor: pointer; color: var(--color-text-muted); }
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; }
    .modal { background: var(--color-surface); padding: 1.5rem; border-radius: 12px; width: 100%; max-width: 360px; }
    label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.7rem; margin-bottom: 0.25rem; }
    input, select { width: 100%; padding: 0.55rem 0.7rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: var(--color-text); }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
    .form-actions button.secondary { background: transparent; border: 1px solid #334155; color: var(--color-text); border-radius: 8px; padding: 0.55rem 1rem; cursor: pointer; }
  `;

  constructor() {
    super();
    this.categories = [];
    this.showForm = false;
    this.saving = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this._load();
  }

  async _load() {
    try {
      this.categories = await api.listCategories();
      await putManyRecords('categories', this.categories);
    } catch {
      this.categories = await getAllRecords('categories');
    }
  }

  render() {
    return html`
      <div class="toolbar">
        <h2>Kategori</h2>
        <button class="primary" @click=${() => (this.showForm = true)}>+ Tambah Kategori</button>
      </div>

      ${Object.entries(TYPE_LABELS).map(
        ([type, label]) => html`
          <div class="group">
            <h3>${label}</h3>
            <div class="chip-row">
              ${this.categories
                .filter((c) => c.type === type)
                .map(
                  (c) => html`
                    <div class="chip">
                      <span class="dot" style="background:${c.color || '#22c55e'}"></span>
                      ${c.name}
                      <span class="remove" @click=${() => this._delete(c.id)}>✕</span>
                    </div>
                  `
                )}
              ${this.categories.filter((c) => c.type === type).length === 0
                ? html`<span style="color: var(--color-text-muted); font-size: 0.85rem;">Belum ada kategori.</span>`
                : ''}
            </div>
          </div>
        `
      )}

      ${this.showForm ? this._renderFormModal() : ''}
    `;
  }

  _renderFormModal() {
    return html`
      <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && (this.showForm = false)}>
        <div class="modal">
          <h3>Tambah Kategori</h3>
          <form @submit=${this._onSubmit}>
            <label>Nama</label>
            <input name="name" placeholder="Mis. Makan, Transportasi" required />

            <label>Tipe</label>
            <select name="type" required>
              ${Object.entries(TYPE_LABELS).map(([value, label]) => html`<option value=${value}>${label}</option>`)}
            </select>

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
    payload.color = PALETTE[this.categories.length % PALETTE.length];

    try {
      await api.createCategory(payload);
      await this._load();
    } catch (err) {
      alert('Gagal menambah kategori: ' + err.message);
    } finally {
      this.saving = false;
      this.showForm = false;
    }
  }

  async _delete(id) {
    if (!confirm('Hapus kategori ini?')) return;
    try {
      await api.deleteCategory(id);
      await this._load();
    } catch (err) {
      alert('Gagal menghapus kategori: ' + err.message);
    }
  }
}

customElements.define('app-categories', AppCategories);
