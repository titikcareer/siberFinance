import { LitElement, html, css } from 'lit';
import { api } from '../services/api.js';

const ACTION_LABELS = {
  create: '➕ Dibuat',
  update: '✏️ Diubah',
  delete: '🗑 Dihapus',
  login: '🔑 Login',
  login_failed: '⛔ Login Gagal',
};

export class AppAuditLog extends LitElement {
  static properties = { logs: { state: true } };

  static styles = css`
    :host { display: block; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.6rem 0.5rem; border-bottom: 1px solid #334155; font-size: 0.85rem; vertical-align: top; }
    .entity { color: var(--color-text-muted); font-size: 0.78rem; }
    details { cursor: pointer; }
    pre { white-space: pre-wrap; font-size: 0.75rem; color: var(--color-text-muted); max-width: 320px; }
  `;

  constructor() {
    super();
    this.logs = [];
  }

  connectedCallback() {
    super.connectedCallback();
    this._load();
  }

  async _load() {
    this.logs = await api.auditLogs().catch(() => []);
  }

  render() {
    return html`
      <h2>🔒 Audit Trail Log</h2>
      <p style="color: var(--color-text-muted)">Riwayat seluruh perubahan data keuangan Anda untuk transparansi & keamanan.</p>
      <table>
        <thead>
          <tr><th>Waktu</th><th>Aksi</th><th>Entitas</th><th>Detail</th></tr>
        </thead>
        <tbody>
          ${this.logs.map(
            (l) => html`
              <tr>
                <td>${new Date(l.created_at).toLocaleString('id-ID')}</td>
                <td>${ACTION_LABELS[l.action] || l.action}</td>
                <td class="entity">${l.entity_type}${l.entity_id ? ` #${l.entity_id.slice(0, 8)}` : ''}</td>
                <td>
                  ${l.new_value || l.old_value
                    ? html`<details><summary>Lihat</summary><pre>${JSON.stringify(l.new_value || l.old_value, null, 2)}</pre></details>`
                    : '-'}
                </td>
              </tr>
            `
          )}
          ${this.logs.length === 0 ? html`<tr><td colspan="4" style="color: var(--color-text-muted);">Belum ada aktivitas tercatat.</td></tr>` : ''}
        </tbody>
      </table>
    `;
  }
}

customElements.define('app-audit-log', AppAuditLog);
