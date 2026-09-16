import './components/app-root.js';
import { syncEngine } from './services/sync.js';
import { isAuthenticated } from './services/api.js';

if (isAuthenticated()) {
  syncEngine.startBackgroundSync();
}

window.addEventListener('auth:login', () => syncEngine.startBackgroundSync());

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((err) => {
      console.warn('Service worker registration failed (opsional untuk dev):', err);
    });
  });
}
