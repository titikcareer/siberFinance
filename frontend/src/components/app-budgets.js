import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';
import Chart from 'chart.js/auto';

export class AppBudgets extends LitElement {
  static properties = {
    period: { state: true },
    budgets: { state: true },
    statuses: { state: true },
    categories: { state: true },
    showForm: { state: true },
    saving: { state: true },
    allocationBudgetId: { state: true },
    allocationData: { state: true },
  };

  static styles = css`
    :host { display: block; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap; }
    .actions { display: flex; gap: 0.6rem; }
    button.primary { background: var(--color-primary); color: #052e16; border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
    button.secondary { background: transparent; border: 1px solid #334155; color: var(--color-text); padding: 0.6rem 1rem; border-radius: 8px; cursor: pointer; }
    .list { display: grid; gap: 0.9rem; }
    .row { background: var(--color-surface); border-radius: 12px; padding: 1rem 1.25rem; }
    .row-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
    .row-top .title { font-weight: 600; }
    .row-top .pct { font-size: 0.85rem; }
    .bar-bg { background: #334155; border-radius: 999px; height: 10px; overflow: hidden; margin-bottom: 0.5rem; }
    .bar-fill { height: 100%; border-radius: 999px; }
    .bar-fill.ok { background: var(--color-primary); }
    .bar-fill.warning { background: var(--color-warning); }
    .bar-fill.exceeded { background: var(--color-danger); }
    .row-meta { display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--color-text-muted); }
    .alert-banner { background: #7f1d1d; color: #fee2e2; padding: 0.6rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
    .alert-banner.warning { background: #78350f; color: #fef3c7; }
    .link-btn { color: var(--color-primary); font-size: 0.8rem; cursor: pointer; margin-top: 0.4rem; display: inline-block; }
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10; }
    .modal { background: var(--color-surface); padding: 1.5rem; border-radius: 12px; width: 100%; max-width: 420px; max-height: 85vh; overflow-y: auto; }
    label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.7rem; margin-bottom: 0.25rem; }
    input, select { width: 100%; padding: 0.55rem 0.7rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: var(--color-text); }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
  `;

  constructor() {
    super();
    this.period = new Date().toISOString().slice(0, 7);
    this.budgets = [];
    this.statuses = [];
    this.categories = [];
    this.showForm = false;
    this.saving = false;
    this.allocationBudgetId = null;
    this.allocationData = null;
  }

  connectedCallback() {
    super.connectedCallback();
    this._load();
    api.listCategories().then((cats) => (this.categories = cats.filter((c) => c.type === 'expense'))).catch(() => {});
  }

  async _load() {
    try {
      this.statuses = await api.budgetStatus(this.period);
    } catch {
      this.statuses = [];
    }
  }

  render() {
    const exceeded = this.statuses.filter((s) => s.status === 'exceeded');
    const warning = this.statuses.filter((s) => s.status === 'warning');

    return html`
      <div class="toolbar">
        <h2>Budgeting</h2>
        <div class="actions">
          <button class="secondary" @click=${this._applyRule}>⚡ Auto Rule 50/30/20</button>
          <button class="primary" @click=${() => (this.showForm = true)}>+ Budget Kategori (Envelope)</button>
        </div>
      </div>

      ${exceeded.length > 0
        ? html`<div class="alert-banner">🚨 ${exceeded.length} budget sudah melebihi batas (≥100%) bulan ini!</div>`
        : ''}
      ${warning.length > 0
        ? html`<div class="alert-banner warning">⚠️ ${warning.length} budget mendekati batas (≥80%).</div>`
        : ''}

      <div class="list">
        ${this.statuses.map(
          (s) => html`
            <div class="row">
              <div class="row-top">
                <span class="title">${s.group_label || 'Kategori: ' + (s.category_id || '-')}</span>
                <span class="pct">${s.percentage_used}%</span>
              </div>
              <div class="bar-bg">
                <div class="bar-fill ${s.status}" style="width:${Math.min(s.percentage_used, 100)}%"></div>
              </div>
              <div class="row-meta">
                <span>Terpakai: ${this._formatCurrency(s.spent)}</span>
                <span>Limit: ${this._formatCurrency(s.amount_limit)}</span>
              </div>
              <div class="link-btn" @click=${() => this._viewAllocation(s.budget_id)}>
                📊 Lihat Alokasi Harian Dinamis
              </div>
            </div>
          `
        )}
        ${this.statuses.length === 0
          ? html`<p style="color: var(--color-text-muted)">Belum ada budget untuk periode ${this.period}. Gunakan tombol di atas untuk membuat.</p>`
          : ''}
      </div>

      ${this.showForm ? this._renderFormModal() : ''}
      ${this.allocationBudgetId ? this._renderAllocationModal() : ''}
    `;
  }

  _renderFormModal() {
    return html`
      <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && (this.showForm = false)}>
        <div class="modal">
          <h3>Budget Kategori (Envelope System)</h3>
          <form @submit=${this._onSubmit}>
            <label>Kategori (Pengeluaran)</label>
            <select name="category_id" required>
              ${this.categories.length === 0
                ? html`<option value="" disabled selected>Belum ada kategori pengeluaran — buat di halaman Kategori</option>`
                : this.categories.map((c) => html`<option value=${c.id}>${c.name}</option>`)}
            </select>

            <label>Bulan (YYYY-MM)</label>
            <input name="period_month" .value=${this.period} required />

            <label>Batas Anggaran (Rp)</label>
            <input name="amount_limit" type="number" min="1" required />

            <div class="form-actions">
              <button type="button" class="secondary" @click=${() => (this.showForm = false)}>Batal</button>
              <button type="submit" class="primary" ?disabled=${this.saving}>${this.saving ? 'Menyimpan...' : 'Simpan'}</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  _renderAllocationModal() {
    return html`
      <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && (this.allocationBudgetId = null)}>
        <div class="modal">
          <h3>Alokasi Harian Dinamis</h3>
          <p style="color: var(--color-text-muted); font-size: 0.85rem;">
            Surplus/defisit harian otomatis dibagi ulang secara pro-rata ke sisa hari dalam bulan ini.
          </p>
          <canvas id="allocationChart"></canvas>
          <div class="form-actions">
            <button class="secondary" @click=${() => (this.allocationBudgetId = null)}>Tutup</button>
          </div>
        </div>
      </div>
    `;
  }

  async _viewAllocation(budgetId) {
    this.allocationBudgetId = budgetId;
    try {
      this.allocationData = await api.recalculateAllocation(budgetId);
    } catch (err) {
      alert('Gagal menghitung alokasi: ' + err.message);
      this.allocationBudgetId = null;
      return;
    }
    await this.updateComplete;
    this._renderAllocationChart();
  }

  _renderAllocationChart() {
    const canvas = this.renderRoot.querySelector('#allocationChart');
    if (!canvas || !this.allocationData) return;
    if (this._allocChart) this._allocChart.destroy();

    this._allocChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: this.allocationData.map((d) => d.date.slice(-2)),
        datasets: [
          {
            label: 'Limit Harian Disesuaikan',
            data: this.allocationData.map((d) => d.adjusted_daily_limit),
            backgroundColor: '#22c55e',
          },
          {
            label: 'Terpakai',
            data: this.allocationData.map((d) => d.spent),
            backgroundColor: '#ef4444',
          },
        ],
      },
      options: {
        plugins: { legend: { labels: { color: '#e2e8f0' } } },
        scales: { x: { ticks: { color: '#94a3b8' } }, y: { ticks: { color: '#94a3b8' } } },
      },
    });
  }

  async _applyRule() {
    try {
      const result = await api.applyFiftyThirtyTwenty(this.period);
      alert(`Budget 50/30/20 dibuat berdasarkan rata-rata pemasukan ${this._formatCurrency(result.based_on_avg_income)}/bulan.`);
      await this._load();
    } catch (err) {
      alert(err.message);
    }
  }

  async _onSubmit(e) {
    e.preventDefault();
    this.saving = true;
    const form = new FormData(e.target);
    const payload = Object.fromEntries(form.entries());
    payload.amount_limit = Number(payload.amount_limit);

    try {
      await api.createBudget(payload);
      await this._load();
    } catch (err) {
      alert('Gagal membuat budget: ' + err.message);
    } finally {
      this.saving = false;
      this.showForm = false;
    }
  }

  _formatCurrency(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
  }
}

customElements.define('app-budgets', AppBudgets);
