import { LitElement, html, css } from 'lit';
import { clearTokens } from '../services/api.js';
import './app-dashboard.js';
import './app-transactions.js';
import './app-accounts.js';
import './app-categories.js';
import './app-budgets.js';
import './app-recurring.js';
import './app-simulator.js';
import './app-audit-log.js';

const PAGES = [
  { id: 'dashboard', label: '📊 Dashboard' },
  { id: 'transactions', label: '💸 Transaksi' },
  { id: 'accounts', label: '🏦 Akun & Aset' },
  { id: 'categories', label: '🏷️ Kategori' },
  { id: 'budgets', label: '🎯 Budgeting' },
  { id: 'recurring', label: '🔁 Berulang' },
  { id: 'simulator', label: '🔮 What-If Simulator' },
  { id: 'audit', label: '🔒 Audit Log' },
];

export class AppShell extends LitElement {
  static properties = {
    activePage: { state: true },
  };

  static styles = css`
    :host {
      display: grid;
      grid-template-columns: 220px 1fr;
      min-height: 100vh;
    }
    nav {
      background: var(--color-surface);
      padding: 1.5rem 1rem;
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }
    .brand { font-weight: 700; margin-bottom: 1.5rem; font-size: 1.1rem; }
    .nav-item {
      padding: 0.6rem 0.8rem;
      border-radius: 8px;
      cursor: pointer;
      color: var(--color-text-muted);
      font-size: 0.9rem;
    }
    .nav-item.active {
      background: rgba(34, 197, 94, 0.15);
      color: var(--color-primary);
      font-weight: 600;
    }
    .logout {
      margin-top: auto;
      color: var(--color-danger);
      cursor: pointer;
      font-size: 0.85rem;
    }
    main {
      padding: 2rem;
      overflow-y: auto;
    }
    @media (max-width: 720px) {
      :host { grid-template-columns: 1fr; }
      nav { flex-direction: row; overflow-x: auto; }
      .brand, .logout { display: none; }
    }
  `;

  constructor() {
    super();
    this.activePage = 'dashboard';
  }

  render() {
    return html`
      <nav>
        <div class="brand">💰 FinanceApp</div>
        ${PAGES.map(
          (p) => html`
            <div
              class="nav-item ${this.activePage === p.id ? 'active' : ''}"
              @click=${() => (this.activePage = p.id)}
            >
              ${p.label}
            </div>
          `
        )}
        <div class="logout" @click=${this._logout}>🚪 Keluar</div>
      </nav>
      <main>
        ${this.activePage === 'dashboard' ? html`<app-dashboard></app-dashboard>` : ''}
        ${this.activePage === 'transactions' ? html`<app-transactions></app-transactions>` : ''}
        ${this.activePage === 'accounts' ? html`<app-accounts></app-accounts>` : ''}
        ${this.activePage === 'categories' ? html`<app-categories></app-categories>` : ''}
        ${this.activePage === 'budgets' ? html`<app-budgets></app-budgets>` : ''}
        ${this.activePage === 'recurring' ? html`<app-recurring></app-recurring>` : ''}
        ${this.activePage === 'simulator' ? html`<app-simulator></app-simulator>` : ''}
        ${this.activePage === 'audit' ? html`<app-audit-log></app-audit-log>` : ''}
      </main>
    `;
  }

  _logout() {
    clearTokens();
    window.dispatchEvent(new CustomEvent('auth:logout'));
  }
}

customElements.define('app-shell', AppShell);
