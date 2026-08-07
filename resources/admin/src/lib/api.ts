export class ApiError extends Error {
  status: number

  constructor(message: string, status = 0) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

const securePath = () => window.settings?.secure_path || 'admin'
const adminBase = () => `/api/v2/${encodeURIComponent(securePath())}`
const demoMode = () => new URLSearchParams(window.location.search).has('demo')

async function demoGet<T>(path: string): Promise<T> {
  const { demoAdminGet } = await import('./demo')
  return demoAdminGet(path) as T
}

async function demoPost<T>(path: string): Promise<T> {
  const { demoAdminPost } = await import('./demo')
  return demoAdminPost(path) as T
}

function adminGet<T>(path: string): Promise<T> {
  if (import.meta.env.DEV && demoMode()) return demoGet<T>(path)
  return request<T>(`${adminBase()}/${path}`)
}

function adminPost<T>(path: string, payload: unknown): Promise<T> {
  if (import.meta.env.DEV && demoMode()) return demoPost<T>(path)
  return request<T>(`${adminBase()}/${path}`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

function isEnvelope(value: unknown): value is { status: string; message?: string; data?: unknown; error?: unknown } {
  return Boolean(value && typeof value === 'object' && 'status' in value)
}

async function request<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, {
    credentials: 'include',
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...init?.headers,
    },
  })

  const contentType = response.headers.get('content-type') || ''
  const body = contentType.includes('application/json') ? await response.json() : await response.text()

  if (!response.ok) {
    const message = typeof body === 'object' && body
      ? String(body.message || body.error || `请求失败 (${response.status})`)
      : String(body || `请求失败 (${response.status})`)
    throw new ApiError(message, response.status)
  }

  if (isEnvelope(body)) {
    if (body.status !== 'success') {
      throw new ApiError(body.message || '请求失败', response.status)
    }
    return body.data as T
  }

  return body as T
}

export const api = {
  authCheck: () => request<{ is_login: boolean; is_admin?: boolean }>('/api/v1/user/checkLogin'),
  login: (email: string, password: string) => request('/api/v2/passport/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  }),
  logout: () => request('/api/v2/passport/auth/logout', { method: 'POST', body: '{}' }),
  adminGet,
  adminPost,
}
