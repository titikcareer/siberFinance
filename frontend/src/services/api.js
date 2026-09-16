const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api/v1';

function getAccessToken() {
  return localStorage.getItem('access_token');
}

function getRefreshToken() {
  return localStorage.getItem('refresh_token');
}

export function setTokens({ access_token, refresh_token }) {
  if (access_token) localStorage.setItem('access_token', access_token);
  if (refresh_token) localStorage.setItem('refresh_token', refresh_token);
}

export function clearTokens() {
  localStorage.removeItem('access_token');
  localStorage.removeItem('refresh_token');
}

export function isAuthenticated() {
  return !!getAccessToken();
}

async function refreshAccessToken() {
  const refresh_token = getRefreshToken();
  if (!refresh_token) throw new Error('No refresh token available');

  const res = await fetch(`${API_BASE_URL}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token }),
  });

  if (!res.ok) throw new Error('Refresh token invalid');
  const json = await res.json();
  setTokens({ access_token: json.data.access_token });
  return json.data.access_token;
}

/**
 * Wrapper fetch utama. Jika offline (network error), lempar error khusus
 * `OfflineError` supaya caller bisa fallback ke IndexedDB / outbox queue.
 */
export async function apiFetch(path, { method = 'GET', body, retry = true } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  const token = getAccessToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  let res;
  try {
    res = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (networkErr) {
    const err = new Error('Network unavailable');
    err.isOffline = true;
    throw err;
  }

  if (res.status === 401 && retry) {
    try {
      await refreshAccessToken();
      return apiFetch(path, { method, body, retry: false });
    } catch {
      clearTokens();
      window.dispatchEvent(new CustomEvent('auth:logout'));
      throw new Error('Session expired');
    }
  }

  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(json.error || `Request failed with status ${res.status}`);
  }
  return json.data;
}

export const api = {
  register: (payload) => apiFetch('/auth/register', { method: 'POST', body: payload }),
  login: (payload) => apiFetch('/auth/login', { method: 'POST', body: payload }),
  me: () => apiFetch('/auth/me'),

  listAccounts: () => apiFetch('/accounts'),
  createAccount: (payload) => apiFetch('/accounts', { method: 'POST', body: payload }),
  updateAccount: (id, payload) => apiFetch(`/accounts/${id}`, { method: 'PUT', body: payload }),
  deleteAccount: (id) => apiFetch(`/accounts/${id}`, { method: 'DELETE' }),
  netWorth: () => apiFetch('/accounts/net-worth'),

  listCategories: () => apiFetch('/categories'),
  createCategory: (payload) => apiFetch('/categories', { method: 'POST', body: payload }),
  updateCategory: (id, payload) => apiFetch(`/categories/${id}`, { method: 'PUT', body: payload }),
  deleteCategory: (id) => apiFetch(`/categories/${id}`, { method: 'DELETE' }),

  listTransactions: (query = '') => apiFetch(`/transactions${query}`),
  createTransaction: (payload) => apiFetch('/transactions', { method: 'POST', body: payload }),
  deleteTransaction: (id) => apiFetch(`/transactions/${id}`, { method: 'DELETE' }),

  listRecurring: () => apiFetch('/recurring-transactions'),
  createRecurring: (payload) => apiFetch('/recurring-transactions', { method: 'POST', body: payload }),
  deleteRecurring: (id) => apiFetch(`/recurring-transactions/${id}`, { method: 'DELETE' }),
  generateDueRecurring: () => apiFetch('/recurring-transactions/generate-due', { method: 'POST' }),

  listBudgets: (period) => apiFetch(`/budgets?period=${period}`),
  createBudget: (payload) => apiFetch('/budgets', { method: 'POST', body: payload }),
  updateBudget: (id, payload) => apiFetch(`/budgets/${id}`, { method: 'PUT', body: payload }),
  deleteBudget: (id) => apiFetch(`/budgets/${id}`, { method: 'DELETE' }),
  budgetStatus: (period) => apiFetch(`/budgets/status?period=${period}`),
  applyFiftyThirtyTwenty: (periodMonth) => apiFetch('/budgets/apply-50-30-20', { method: 'POST', body: { period_month: periodMonth } }),
  recalculateAllocation: (budgetId) => apiFetch(`/budgets/${budgetId}/recalculate-allocation`, { method: 'POST' }),

  cashflow: (params) => apiFetch(`/reports/cashflow?${params}`),
  expenseBreakdown: (params) => apiFetch(`/reports/expense-breakdown?${params}`),
  journalInsights: (params) => apiFetch(`/reports/journal-insights?${params}`),

  auditLogs: (limit = 100) => apiFetch(`/audit-logs?limit=${limit}`),

  runSimulation: (payload) => apiFetch('/simulator/run', { method: 'POST', body: payload }),

  syncPush: (payload) => apiFetch('/sync/push', { method: 'POST', body: payload }),
  syncPull: (since, deviceId) => apiFetch(`/sync/pull?since=${encodeURIComponent(since)}&device_id=${deviceId}`),
};
