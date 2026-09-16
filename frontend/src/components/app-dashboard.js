import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';
import { getAllRecords } from '../services/db.js';
import Chart from 'chart.js/auto';

export class AppDashboard extends LitElement {
  static properties = {
    netWorth: { state: true },
    budgets: { state: true },
    offline: { state: true },
    insight: { state: true },
    toast: { state: true },
  };

  static styles = css`
    :host { display: block; }
    h2 { margin-top: 0; }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }
    .card {
      background: var(--color-surface);
      border-radius: 12px;
      padding: 1.25rem;
    }
    .card .label { color: var(--color-text-muted); font-size: 0.8rem; }
    .card .value { font-size: 1.4rem; font-weight: 700; margin-top: 0.3rem; }
    .budget-item { margin-bottom: 1rem; }
    .bar-bg { background: #334155; border-radius: 999px; height: 10px; overflow: hidden; }
    .bar-fill { height: 100%; border-radius: 999px; }
    .bar-fill.ok { background: var(--color-primary); }
    .bar-fill.warning { background: var(--color-warning); }
    .bar-fill.exceeded { background: var(--color-danger); }
    .offline-banner {
      background: #78350f;
      color: #fef3c7;
      padding: 0.6rem 1rem;
      border-radius: 8px;
      margin-bottom: 1.25rem;
      font-size: 0.85rem;
    }
    .toast {
      position: fixed;
      bottom: 1.5rem;
      right: 1.5rem;
      background: #7f1d1d;
      color: #fee2e2;
      padding: 0.8rem 1.2rem;
      border-radius: 10px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.4);
      font-size: 0.85rem;
      max-width: 320px;
      z-index: 50;
    }
    .toast.warning { background: #78350f; color: #fef3c7; }
    .toast .close { float: right; cursor: pointer; margin-left: 0.5rem; }
    .insight-card { background: linear-gradient(135deg, #1e293b, #312e81); }
    canvas { max-height: 280px; }
  `;

  constructor() {
    super();
    this.netWorth = null;
    this.budgets = [];
    this.offline = false;
    this.insight = null;
    this.toast = null;
  }

  connectedCallback() {
    super.connectedCallback();
    this._loadData();
  }

  async _loadData() {
    const period = new Date().toISOString().slice(0, 7);

    // Auto-generate transaksi dari template recurring yang sudah jatuh tempo
    try {
      const gen = await api.generateDueRecurring();
      if (gen.generated_count > 0) {
        console.info(`${gen.generated_count} transaksi recurring otomatis digenerate.`);
      }
    } catch {
      // Diamkan jika offline - generator akan jalan lagi saat online
    }

    try {
      this.netWorth = await api.netWorth();
      this.offline = false;
    } catch (err) {
      this.offline = true;
      const accounts = await getAllRecords('accounts');
      const totalAssets = accounts
        .filter((a) => a.type !== 'liability')
        .reduce((sum, a) => sum + Number(a.balance || 0), 0);
      const totalLiabilities = accounts
        .filter((a) => a.type === 'liability')
        .reduce((sum, a) => sum + Number(a.balance || 0), 0);
      this.netWorth = { total_assets: totalAssets, total_liabilities: totalLiabilities, net_worth: totalAssets - totalLiabilities };
    }

    try {
      this.budgets = await api.budgetStatus(period);
      this._checkBudgetAlerts();
    } catch {
      this.budgets = [];
    }

    try {
      this.insight = await api.journalInsights({});
    } catch {
      this.insight = null;
    }

    await this.updateComplete;
    this._renderExpenseChart();
    this._renderCashflowChart();
  }

  _checkBudgetAlerts() {
    const exceeded = this.budgets.find((b) => b.status === 'exceeded');
    const warning = this.budgets.find((b) => b.status === 'warning');

    if (exceeded) {
      this.toast = { level: 'exceeded', message: `Budget "${exceeded.group_label || 'kategori'}" sudah melebihi 100%! Segera evaluasi pengeluaran Anda.` };
    } else if (warning) {
      this.toast = { level: 'warning', message: `Budget "${warning.group_label || 'kategori'}" sudah mencapai ${warning.percentage_used}% dari batas.` };
    }
  }

  async _renderExpenseChart() {
    let breakdown = [];
    try {
      breakdown = await api.expenseBreakdown({});
    } catch {
      breakdown = [];
    }
    const canvas = this.renderRoot.querySelector('#expenseChart');
    if (!canvas || breakdown.length === 0) return;

    if (this._expenseChart) this._expenseChart.destroy();
    this._expenseChart = new Chart(canvas, {
      type: 'doughnut',
      data: {
        labels: breakdown.map((b) => b.category_name),
        datasets: [{ data: breakdown.map((b) => b.total), backgroundColor: breakdown.map((b) => b.color || '#22c55e') }],
      },
      options: { plugins: { legend: { labels: { color: '#e2e8f0' } } } },
    });
  }

  async _renderCashflowChart() {
    let data = [];
    try {
      data = await api.cashflow(`granularity=monthly&year=${new Date().getFullYear()}`);
    } catch {
      data = [];
    }
    const canvas = this.renderRoot.querySelector('#cashflowChart');
    if (!canvas || data.length === 0) return;

    if (this._cashflowChart) this._cashflowChart.destroy();
    this._cashflowChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: data.map((d) => d.period),
        datasets: [
          { label: 'Pemasukan', data: data.map((d) => d.income), backgroundColor: '#22c55e' },
          { label: 'Pengeluaran', data: data.map((d) => d.expense), backgroundColor: '#ef4444' },
        ],
      },
      options: {
        plugins: { legend: { labels: { color: '#e2e8f0' } } },
        scales: { x: { ticks: { color: '#94a3b8' } }, y: { ticks: { color: '#94a3b8' } } },
      },
    });
  }

  _formatCurrency(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
  }

  render() {
    return html`
      <h2>Dashboard</h2>
      ${this.offline
        ? html`<div class="offline-banner">⚠️ Anda sedang offline. Data ditampilkan dari cache lokal (IndexedDB) dan akan tersinkron otomatis saat online kembali.</div>`
        : ''}

      <div class="grid">
        <div class="card">
          <div class="label">Total Aset</div>
          <div class="value">${this._formatCurrency(this.netWorth?.total_assets)}</div>
        </div>
        <div class="card">
          <div class="label">Total Kewajiban</div>
          <div class="value">${this._formatCurrency(this.netWorth?.total_liabilities)}</div>
        </div>
        <div class="card">
          <div class="label">Net Worth</div>
          <div class="value">${this._formatCurrency(this.netWorth?.net_worth)}</div>
        </div>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr;">
        <div class="card">
          <h3>Status Budget Bulan Ini</h3>
          ${this.budgets.length === 0
            ? html`<p style="color: var(--color-text-muted)">Belum ada budget diatur.</p>`
            : this.budgets.map(
                (b) => html`
                  <div class="budget-item">
                    <div style="display:flex; justify-content:space-between; font-size:0.85rem; margin-bottom:0.3rem;">
                      <span>${b.group_label || b.category_id || 'Umum'}</span>
                      <span>${b.percentage_used}%</span>
                    </div>
                    <div class="bar-bg">
                      <div class="bar-fill ${b.status}" style="width:${Math.min(b.percentage_used, 100)}%"></div>
                    </div>
                  </div>
                `
              )}
        </div>
        <div class="card">
          <h3>Breakdown Pengeluaran</h3>
          <canvas id="expenseChart"></canvas>
        </div>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr;">
        <div class="card">
          <h3>Cashflow Bulanan (Tahun Berjalan)</h3>
          <canvas id="cashflowChart"></canvas>
        </div>
        <div class="card insight-card">
          <h3>🧠 Insight Micro-Journaling</h3>
          ${this.insight
            ? html`<p>${this.insight.insight_text}</p>`
            : html`<p style="color: var(--color-text-muted)">Tambahkan konteks (stres/urgensi) saat mencatat pengeluaran untuk mendapatkan insight korelasi.</p>`}
        </div>
      </div>

      ${this.toast
        ? html`
            <div class="toast ${this.toast.level}">
              <span class="close" @click=${() => (this.toast = null)}>✕</span>
              ${this.toast.message}
            </div>
          `
        : ''}
    `;
  }
}

customElements.define('app-dashboard', AppDashboard);
