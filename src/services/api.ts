import { hubFetch } from '../lib/hubFetch'

// Generic response handler
async function handleResponse<T>(res: Response): Promise<T> {
  if (!res.ok) {
    const error = await res.text().catch(() => 'Unknown error')
    throw new Error(`API Error ${res.status}: ${error}`)
  }
  return res.json()
}

// ============ Clients ============
export const clientsApi = {
  getAll: () => hubFetch('/api/clients').then(r => handleResponse<any[]>(r)),
  getById: (id: string) => hubFetch(`/api/clients/${id}`).then(r => handleResponse<any>(r)),
  create: (data: any) => hubFetch('/api/clients', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  update: (id: string, data: any) => hubFetch(`/api/clients/${id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  delete: (id: string) => hubFetch(`/api/clients/${id}`, { method: 'DELETE' }).then(r => handleResponse<any>(r)),
}

// ============ Projects ============
export const projectsApi = {
  getAll: () => hubFetch('/api/projects').then(r => handleResponse<any[]>(r)),
  getById: (id: string) => hubFetch(`/api/projects/${id}`).then(r => handleResponse<any>(r)),
  create: (data: any) => hubFetch('/api/projects', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  update: (id: string, data: any) => hubFetch(`/api/projects/${id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  delete: (id: string) => hubFetch(`/api/projects/${id}`, { method: 'DELETE' }).then(r => handleResponse<any>(r)),
}

// ============ Tasks ============
export const tasksApi = {
  getAll: () => hubFetch('/api/tasks').then(r => handleResponse<any[]>(r)),
  getById: (id: string) => hubFetch(`/api/tasks/${id}`).then(r => handleResponse<any>(r)),
  create: (data: any) => hubFetch('/api/tasks', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  update: (id: string, data: any) => hubFetch(`/api/tasks/${id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  delete: (id: string) => hubFetch(`/api/tasks/${id}`, { method: 'DELETE' }).then(r => handleResponse<any>(r)),
}

// ============ Time Entries ============
export const timeEntriesApi = {
  getAll: (params?: { project_id?: string; user_id?: string }) => {
    let url = '/api/time-entries'
    if (params) {
      const q = new URLSearchParams()
      if (params.project_id) q.set('project_id', params.project_id)
      if (params.user_id) q.set('user_id', params.user_id)
      const qs = q.toString()
      if (qs) url += `?${qs}`
    }
    return hubFetch(url).then(r => handleResponse<any[]>(r))
  },
  create: (data: any) => hubFetch('/api/time-entries', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  delete: (id: string) => hubFetch(`/api/time-entries/${id}`, { method: 'DELETE' }).then(r => handleResponse<any>(r)),
  heartbeat: (data: any) => hubFetch('/api/time-entries/heartbeat', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
}

// ============ Users ============
export const usersApi = {
  getAll: () => hubFetch('/api/users').then(r => handleResponse<any[]>(r)),
  getById: (id: string) => hubFetch(`/api/users/${id}`).then(r => handleResponse<any>(r)),
  update: (id: string, data: any) => hubFetch(`/api/users/${id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
}

// ============ Finance (Documents) ============
export const financeApi = {
  getInvoices: () => hubFetch('/api/documents/invoices').then(r => handleResponse<any[]>(r)),
  getExpenses: () => hubFetch('/api/documents/expenses').then(r => handleResponse<any[]>(r)),
  getCollections: () => hubFetch('/api/documents/collections').then(r => handleResponse<any[]>(r)),
}

// ============ Generic Documents ============
export const documentsApi = {
  getByCollection: (collection: string) => hubFetch(`/api/documents/${collection}`).then(r => handleResponse<any[]>(r)),
  getById: (collection: string, id: string) => hubFetch(`/api/documents/${collection}/${id}`).then(r => handleResponse<any>(r)),
  create: (collection: string, data: any) => hubFetch(`/api/documents/${collection}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  update: (collection: string, id: string, data: any) => hubFetch(`/api/documents/${collection}/${id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => handleResponse<any>(r)),
  delete: (collection: string, id: string) => hubFetch(`/api/documents/${collection}/${id}`, { method: 'DELETE' }).then(r => handleResponse<any>(r)),
}

// ============ Collection Engine ============
export const collectionApi = {
  getStatus: () => hubFetch('/api/collection/status').then(r => handleResponse<any>(r)),
  getSummary: () => hubFetch('/api/collection/summary').then(r => handleResponse<any>(r)),
  getDrafts: (status?: string) => {
    const url = status && status !== 'all' ? `/api/collection/drafts?status=${status}` : '/api/collection/drafts'
    return hubFetch(url).then(r => handleResponse<{ ok: boolean; dryRun: boolean; count: number; drafts: any[] }>(r))
  },
  scan: () => hubFetch('/api/collection/scan', { method: 'POST' }).then(r => handleResponse<any>(r)),
  approveDraft: (id: string) => hubFetch(`/api/collection/drafts/${id}/approve`, { method: 'POST' }).then(r => handleResponse<any>(r)),
  dismissDraft: (id: string, reason?: string) => hubFetch(`/api/collection/drafts/${id}/dismiss`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ reason })
  }).then(r => handleResponse<any>(r)),
}

// ============ Health ============
export const systemApi = {
  health: () => hubFetch('/api/health').then(r => handleResponse<any>(r)),
}
