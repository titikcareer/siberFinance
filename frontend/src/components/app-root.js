import { LitElement, html, css } from 'lit';
import { isAuthenticated } from '../services/api.js';
import './app-login.js';
import './app-shell.js';

export class AppRoot extends LitElement {
  static properties = {
    authenticated: { state: true },
  };

  static styles = css`
    :host {
      display: block;
      min-height: 100vh;
    }
  `;

  constructor() {
    super();
    this.authenticated = isAuthenticated();
    this._onLogin = () => (this.authenticated = true);
    this._onLogout = () => (this.authenticated = false);
  }

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener('auth:login', this._onLogin);
    window.addEventListener('auth:logout', this._onLogout);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    window.removeEventListener('auth:login', this._onLogin);
    window.removeEventListener('auth:logout', this._onLogout);
  }

  render() {
    return this.authenticated
      ? html`<app-shell></app-shell>`
      : html`<app-login></app-login>`;
  }
}

customElements.define('app-root', AppRoot);
