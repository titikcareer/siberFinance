import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';
import Chart from 'chart.js/auto';

export class AppSimulator extends LitElement {
  static properties = {
    assumptions: { state: true },
    result: { state: true },
    loading: { state: true },
  };

  static styles = css`
    :host { display: block; }
    .layout { display: grid; grid-template-columns: 340px 1fr; gap: 1.5rem; }
    .card { background: var(--color-surface); border-radius: 12px; padding: 1.25rem; }
    .assumption-row { display: grid; grid-template-columns: 1fr auto; gap: 0.4rem; align-items: center; margin-bottom: 0.6rem; }
    input, select { padding: 0.5rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: var(--color-text); width: 100%; }
    button.primary { background: var(--color-primary); color: #052e16; border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 1rem; }
    button.remove { background: transparent; border: none; color: var(--color-danger); cursor: pointer; font-size: 1.1rem; }
    button.add { background: transparent; border: 1px dashed #334155; color: var(--color-text-muted); border-radius: 8px; padding: 0.5rem; width: 100%; cursor: pointer; margin-top: 0.5rem; }
    .summary { display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .summary .stat { flex: 1; min-width: 140px; }
    .stat .label { font-size: 0.75rem; color: var(--color-text-muted); }
    .stat .value { font-size: 1.2rem; font-weight: 700; }
    .risk-critical { color: var(--color-danger); }
    .risk-high { color: var(--color-warning); }
    .risk-medium { color: #eab308; }
    .risk-low { color: var(--color-primary); }
  `;

  constructor() {
    super();
    this.assumptions = [{ label: 'Cicilan Motor', type: 'recurring_expense', amount: 1500000, start_month: 1, duration_months: 24 }];
    this.result = null;
    this.loading = false;
  }

  render() {
    return html`
      <h2>🔮 Predictive What-If Simulator</h2>
      <p style="color: var(--color-text-muted)">
        Simulasikan dampak keputusan finansial masa depan terhadap cashflow & runway tabungan hingga 5 tahun ke depan.
      </p>

      <div class="layout">
        <div class="card">
          <h3>Asumsi Skenario</h3>
          ${this.assumptions.map(
            (a, idx) => html`
              <div class="assumption-row">
                <input
                  placeholder="Label (mis. Cicilan Motor)"
                  .value=${a.label}
                  @input=${(e) => this._updateAssumption(idx, 'label', e.target.value)}
                />
                <button class="remove" @click=${() => this._removeAssumption(idx)}>✕</button>
              </div>
              <select .value=${a.type} @change=${(e) => this._updateAssumption(idx, 'type', e.target.value)}>
                <option value="recurring_expense">Pengeluaran Berulang</option>
                <option value="recurring_income_delta">Perubahan Pemasukan</option>
                <option value="one_time_expense">Pengeluaran Sekali Waktu</option>
              </select>
              <div class="assumption-row" style="grid-template-columns: 1fr 1fr;">
                <input
                  type="number"
                  placeholder="Jumlah (Rp)"
                  .value=${a.amount}
                  @input=${(e) => this._updateAssumption(idx, 'amount', Number(e.target.value))}
                />
                <input
                  type="number"
                  placeholder="Mulai bulan ke-"
                  .value=${a.start_month}
                  @input=${(e) => this._updateAssumption(idx, 'start_month', Number(e.target.value))}
                />
              </div>
              <hr style="border-color:#334155; margin: 0.75rem 0;" />
            `
          )}
          <button class="add" @click=${this._addAssumption}>+ Tambah Asumsi</button>
          <button class="primary" @click=${this._runSimulation} ?disabled=${this.loading}>
            ${this.loading ? 'Menghitung proyeksi...' : 'Jalankan Simulasi'}
          </button>
        </div>

        <div class="card">
          <h3>Hasil Proyeksi</h3>
          ${this.result ? this._renderResult() : html`<p style="color: var(--color-text-muted)">Belum ada simulasi dijalankan.</p>`}
        </div>
      </div>
    `;
  }

  _renderResult() {
    const lastMonth = this.result.projection.at(-1);
    return html`
      <div class="summary">
        <div class="stat">
          <div class="label">Runway Tabungan</div>
          <div class="value">
            ${this.result.savings_runway_months ? `${this.result.savings_runway_months} bulan` : 'Aman (>5 tahun)'}
          </div>
        </div>
        <div class="stat">
          <div class="label">Saldo Proyeksi Akhir</div>
          <div class="value">${this._formatCurrency(lastMonth.projected_balance)}</div>
        </div>
        <div class="stat">
          <div class="label">Level Risiko Saat Ini</div>
          <div class="value risk-${lastMonth.risk_level}">${lastMonth.risk_level.toUpperCase()}</div>
        </div>
      </div>
      <canvas id="projectionChart"></canvas>
    `;
  }

  _addAssumption() {
    this.assumptions = [
      ...this.assumptions,
      { label: '', type: 'recurring_expense', amount: 0, start_month: 1, duration_months: null },
    ];
  }

  _removeAssumption(idx) {
    this.assumptions = this.assumptions.filter((_, i) => i !== idx);
  }

  _updateAssumption(idx, field, value) {
    this.assumptions = this.assumptions.map((a, i) => (i === idx ? { ...a, [field]: value } : a));
  }

  async _runSimulation() {
    this.loading = true;
    try {
      this.result = await api.runSimulation({ horizon_months: 60, assumptions: this.assumptions });
      await this.updateComplete;
      this._renderChart();
    } catch (err) {
      alert('Gagal menjalankan simulasi: ' + err.message);
    } finally {
      this.loading = false;
    }
  }

  _renderChart() {
    const canvas = this.renderRoot.querySelector('#projectionChart');
    if (!canvas) return;
    if (this._chart) this._chart.destroy();

    this._chart = new Chart(canvas, {
      type: 'line',
      data: {
        labels: this.result.projection.map((p) => `Bln ${p.month}`),
        datasets: [
          {
            label: 'Proyeksi Saldo',
            data: this.result.projection.map((p) => p.projected_balance),
            borderColor: '#22c55e',
            backgroundColor: 'rgba(34,197,94,0.1)',
            fill: true,
            tension: 0.3,
          },
        ],
      },
      options: {
        plugins: { legend: { labels: { color: '#e2e8f0' } } },
        scales: {
          x: { ticks: { color: '#94a3b8' } },
          y: { ticks: { color: '#94a3b8' } },
        },
      },
    });
  }

  _formatCurrency(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
  }
}

customElements.define('app-simulator', AppSimulator);
