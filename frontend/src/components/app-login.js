import { LitElement, html, css } from 'lit';
import { api, setTokens } from '../services/api.js';

export class AppLogin extends LitElement {
  static properties = {
    mode: { state: true },
    error: { state: true },
    loading: { state: true },
  };

  static styles = css`
    :host {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: linear-gradient(160deg, #0f172a 0%, #111827 100%);
    }
    .card {
      background: var(--color-surface);
      padding: 2.5rem;
      border-radius: 16px;
      width: 100%;
      max-width: 380px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    }
    h1 { font-size: 1.4rem; margin-bottom: 0.25rem; }
    p.subtitle { color: var(--color-text-muted); margin-top: 0; margin-bottom: 1.5rem; font-size: 0.9rem; }
    input {
      width: 100%;
      padding: 0.7rem 0.9rem;
      margin-bottom: 0.9rem;
      border-radius: 8px;
      border: 1px solid #334155;
      background: #0f172a;
      color: var(--color-text);
      font-size: 0.95rem;
    }
    button {
      width: 100%;
      padding: 0.75rem;
      border-radius: 8px;
      border: none;
      background: var(--color-primary);
      color: #052e16;
      font-weight: 600;
      cursor: pointer;
    }
    button:disabled { opacity: 0.6; cursor: not-allowed; }
    .switch { text-align: center; margin-top: 1rem; font-size: 0.85rem; color: var(--color-text-muted); }
    .switch a { color: var(--color-primary); cursor: pointer; }
    .error { color: var(--color-danger); font-size: 0.85rem; margin-bottom: 0.75rem; }
  `;

  constructor() {
    super();
    this.mode = 'login';
    this.error = '';
    this.loading = false;
  }

  render() {
    return html`
      <div class="card">
        <h1>${this.mode === 'login' ? 'Masuk' : 'Buat Akun'}</h1>
        <p class="subtitle">Personal Finance Manager</p>
        ${this.error ? html`<div class="error">${this.error}</div>` : ''}
        <form @submit=${this._onSubmit}>
          ${this.mode === 'register'
            ? html`<input name="name" placeholder="Nama lengkap" required />`
            : ''}
          <input name="email" type="email" placeholder="Email" required />
          <input name="password" type="password" placeholder="Password" required minlength="8" />
          <button type="submit" ?disabled=${this.loading}>
            ${this.loading ? 'Memproses...' : this.mode === 'login' ? 'Masuk' : 'Daftar'}
          </button>
        </form>
        <div class="switch">
          ${this.mode === 'login'
            ? html`Belum punya akun? <a @click=${() => (this.mode = 'register')}>Daftar</a>`
            : html`Sudah punya akun? <a @click=${() => (this.mode = 'login')}>Masuk</a>`}
        </div>
      </div>
    `;
  }

  async _onSubmit(e) {
    e.preventDefault();
    this.error = '';
    this.loading = true;
    const form = new FormData(e.target);
    const payload = Object.fromEntries(form.entries());

    try {
      if (this.mode === 'register') {
        await api.register(payload);
        this.mode = 'login';
        this.error = '';
      } else {
        const data = await api.login(payload);
        setTokens(data);
        window.dispatchEvent(new CustomEvent('auth:login'));
      }
    } catch (err) {
      this.error = err.message;
    } finally {
      this.loading = false;
    }
  }
}

customElements.define('app-login', AppLogin);
