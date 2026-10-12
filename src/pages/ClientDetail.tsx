// @ts-nocheck
import { useMemo, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useClients } from '../hooks/useClients'
import { useProjects } from '../hooks/useProjects'
import { useTasks } from '../hooks/useTasks'
import { useLeads } from '../hooks/useLeads'
import { useCampaigns } from '../hooks/useCampaigns'
import { useInvoices } from '../hooks/useInvoices'
import { useHosting } from '../hooks/useHosting'
import { usePasswordVault } from '../hooks/usePasswordVault'
import { useHourlyServices } from '../hooks/useHourlyServices'
import { formatDateIL, todayYmd } from '../lib/dates'
import { incomeOutstandingAmount } from '../lib/financeIncomePayment'
import { Badge } from '../components/shared/Badge'
import { Modal } from '../components/shared/Modal'
import { boardStatusHe } from '../lib/boardTaskLabels'
import { type TaskStatus, type ClientStatus } from '../types'
import { hubSectionClass } from '../lib/hubSectionBorder'
import { useAuth } from '../contexts/AuthContext'
import { useUsers } from '../hooks/useUsers'
import { useHubStore } from '../store/useStore'
import { isAdminRole } from '../components/RequireAdmin'
import {
  Check,
  X,
  ExternalLink,
  Copy,
  Eye,
  EyeOff,
  KeyRound,
  HardDrive,
  Calendar,
  Loader2,
} from 'lucide-react'
type TabId = 'overview' | 'projects' | 'finance' | 'vault' | 'hosting' | 'leads'

export function ClientDetail() {
  const { id: rawId } = useParams()
  const id = useMemo(() => {
    if (!rawId) return ''
    try {
      return decodeURIComponent(rawId)
    } catch {
      return rawId
    }
  }, [rawId])
  const navigate = useNavigate()
  const { clients, loading: clientsLoading, updateClient } = useClients()
  const { projects } = useProjects()
  const { tasks, setDrawerTaskId } = useTasks()
  const { leads } = useLeads()
  const { campaigns } = useCampaigns()
  const { invoices } = useInvoices()
  const { records: hostingRecords } = useHosting()
  const { services: hourlyServices } = useHourlyServices()

  const { user } = useAuth()
  const { users, loading: usersLoading } = useUsers()
  const hubUser = useHubStore((s) => s.currentUser)
  const pushToast = useHubStore((s) => s.pushToast)

  const roleFromTeam = (Array.isArray(users) ? users : []).find((m) => m.id === user?.uid)?.role ?? ''
  const hubUid = hubUser?.uid
  const hubRoleRaw = hubUser?.role
  const rawRoleForVault =
    hubUid === user?.uid && typeof hubRoleRaw === 'string' && hubRoleRaw.trim() !== ''
      ? hubRoleRaw
      : roleFromTeam

  const isVaultAdmin = Boolean(
    user?.uid &&
      (isAdminRole(rawRoleForVault) ||
        rawRoleForVault === 'technical_admin' ||
        rawRoleForVault === 'vault_admin'),
  )

  const { entries: passwords, verifyEntry, removeEntry } = usePasswordVault({
    uid: user?.uid,
    usersReady: !usersLoading,
    isVaultAdmin,
  })

  const [revealedPasswords, setRevealedPasswords] = useState<Record<string, boolean>>({})
  const [copiedKey, setCopiedKey] = useState<string | null>(null)
  const [actionLoading, setActionLoading] = useState<Record<string, 'verify' | 'delete'>>({})

  const handleCopy = (key: string, text: string) => {
    if (!text) return
    navigator.clipboard.writeText(text)
    setCopiedKey(key)
    setTimeout(() => {
      setCopiedKey((prev) => (prev === key ? null : prev))
    }, 2000)
  }

  const handleVerify = async (pwId: string) => {
    setActionLoading((prev) => ({ ...prev, [pwId]: 'verify' }))
    try {
      await verifyEntry(pwId)
    } finally {
      setActionLoading((prev) => {
        const next = { ...prev }
        delete next[pwId]
        return next
      })
    }
  }

  const handleDelete = async (pwId: string, title?: string) => {
    if (!window.confirm(`האם אתה בטוח שברצונך למחוק את "${title || 'הסיסמה'}" מהכספת?`)) return
    setActionLoading((prev) => ({ ...prev, [pwId]: 'delete' }))
    try {
      await removeEntry(pwId)
    } finally {
      setActionLoading((prev) => {
        const next = { ...prev }
        delete next[pwId]
        return next
      })
    }
  }

  const [activeTab, setActiveTab] = useState<TabId>('overview')
  const [taskViewMode, setTaskViewMode] = useState<'kanban' | 'table'>('kanban')

  const client = useMemo(() => (Array.isArray(clients) ? clients : []).find((c) => c.id === id), [clients, id])

  const [enrichLoading, setEnrichLoading] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)
  const [form, setForm] = useState({
    name: '',
    contact: '',
    phone: '',
    email: '',
    domain: '',
    taxId: '',
    department: '',
    status: 'active' as ClientStatus,
  })
  const [errors, setErrors] = useState<{ name?: string; email?: string }>({})

  const openEdit = () => {
    if (!client) return
    setForm({
      name: client.name || '',
      contact: client.contact || '',
      phone: client.phone || '',
      email: client.email || '',
      domain: client.domain || '',
      taxId: client.taxId || '',
      department: client.department || '',
      status: client.status || 'active',
    })
    setErrors({})
    setModalOpen(true)
  }

  const enrichCompany = async () => {
    if (!form.taxId && !form.name) {
      pushToast('יש להזין ח.פ או שם חברה להעשרה', 'error')
      return
    }
    setEnrichLoading(true)
    try {
      const q = form.taxId || form.name
      const res = await fetch('/api/enrich/company?q=' + encodeURIComponent(q))
      if (!res.ok) {
         pushToast('לא נמצאו נתונים (Data.gov.il)', 'error')
         return
      }
      const data = await res.json()
      setForm((f) => ({
        ...f,
        taxId: data.taxId || f.taxId,
        name: data.legalNameHe || data.legalNameEn || f.name,
      }))
      pushToast('הנתונים הושלמו בהצלחה!', 'success')
    } catch (e) {
      pushToast('שגיאה בתקשורת', 'error')
    } finally {
      setEnrichLoading(false)
    }
  }

  const submitEdit = async () => {
    if (!client) return
    const nextErrors: { name?: string; email?: string } = {}
    if (!form.name.trim()) nextErrors.name = 'שם החברה חובה'
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (form.email && !emailRegex.test(form.email.trim())) {
      nextErrors.email = 'אימייל לא תקין'
    }
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length > 0) return

    try {
      await updateClient(client.id, {
        name: form.name.trim(),
        contact: form.contact.trim(),
        phone: form.phone.trim(),
        email: form.email.trim(),
        domain: form.domain.trim(),
        taxId: form.taxId.trim(),
        department: form.department.trim() || null,
        status: form.status,
      })
      setModalOpen(false)
    } catch (err) {
      console.error('Failed to update client:', err)
    }
  }

  const clientProjects = useMemo(() => (Array.isArray(projects) ? projects : []).filter((p) => p.clientId === id), [projects, id])
  const projectIds = useMemo(() => new Set(clientProjects.map((p) => p.id)), [clientProjects])
  const clientTasks = useMemo(() => (Array.isArray(tasks) ? tasks : []).filter((t) => t.projectId ? projectIds.has(t.projectId) : false), [tasks, projectIds])
  const clientLeads = useMemo(() => (Array.isArray(leads) ? leads : []).filter((l) => l.clientId === id), [leads, id])
  const clientCampaigns = useMemo(() => (Array.isArray(campaigns) ? campaigns : []).filter((c) => c.clientId === id), [campaigns, id])
  const clientInvoices = useMemo(() => (Array.isArray(invoices) ? invoices : []).filter((i) => i.clientId === id || (i as any).client_id === id), [invoices, id])
  const clientHosting = useMemo(() => (Array.isArray(hostingRecords) ? hostingRecords : []).filter((h) => h.clientId === id || (h as any).client_id === id), [hostingRecords, id])
  const clientPasswords = useMemo(() => (Array.isArray(passwords) ? passwords : []).filter((p) => p.clientId === id || (p as any).client_id === id), [passwords, id])

  const { totalDebt, maxDaysOverdue } = useMemo(() => {
    let debt = 0
    let maxDays = 0
    const today = new Date(todayYmd().slice(0, 10)).getTime()

    clientInvoices.forEach((inv: any) => {
      // Assuming finance incomes use documentKind and status
      if (inv.documentKind && inv.documentKind !== 'transaction_account' && inv.documentKind !== 'transaction_invoice') return
      
      const outstanding = incomeOutstandingAmount(inv)
      if (outstanding > 0) {
        debt += outstanding
        const due = (inv.invoiceDate || '').slice(0, 10)
        if (/^\d{4}-\d{2}-\d{2}$/.test(due)) {
          const ms = today - new Date(due).getTime()
          const days = Math.max(0, Math.floor(ms / 86400000))
          if (days > maxDays) maxDays = days
        }
      }
    })
    return { totalDebt: debt, maxDaysOverdue: maxDays }
  }, [clientInvoices])

  const totalRemainingHours = useMemo(() => {
    const clientServices = (Array.isArray(hourlyServices) ? hourlyServices : []).filter(s => s.clientId === id)
    if (clientServices.length === 0) return null // No hours bank exists
    return clientServices.reduce((sum, s) => sum + (s.hoursPackageTotal || 0), 0) // Simplified - in real app would deduct used hours, assuming hoursPackageTotal holds current remaining or similar logic. 
  }, [hourlyServices, id])

  if (clientsLoading) {
    return (
      <div className="flex h-full min-h-[400px] items-center justify-center p-6">
        <div className="flex flex-col items-center gap-3">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-[#F97316] border-t-transparent"></div>
          <span className="text-sm text-[#64748b]">טוען נתוני לקוח...</span>
        </div>
      </div>
    )
  }

  if (!client) {
    return (
      <div className="flex h-full min-h-[400px] flex-col items-center justify-center gap-4 text-center p-6">
        <div className="text-4xl text-[#94a3b8]">👤</div>
        <h2 className="text-xl font-bold text-[#1e293b]">לקוח לא נמצא</h2>
        <p className="text-sm text-[#64748b]">לא נמצא לקוח תואם למזהה המבוקש במערכת.</p>
        <button
          id="client-detail-back"
          onClick={() => navigate('/clients')}
          className="rounded-lg bg-[#F97316] px-4 py-2 text-sm font-medium text-white hover:bg-[#ea580c] transition"
        >
          חזרה לרשימה
        </button>
      </div>
    )
  }

  const tabs: { id: TabId; label: string; count?: number }[] = [
    { id: 'overview', label: 'כללי' },
    { id: 'projects', label: `פרויקטים ומשימות (${clientProjects.length})`, count: clientProjects.length },
    { id: 'finance', label: `כספים (${clientInvoices.length})`, count: clientInvoices.length },
    { id: 'vault', label: `כספת סיסמאות (${clientPasswords.length})`, count: clientPasswords.length },
    { id: 'hosting', label: `תשתיות ואחסון (${clientHosting.length})`, count: clientHosting.length },
    { id: 'leads', label: `שיווק ולידים (${clientLeads.length + clientCampaigns.length})`, count: clientLeads.length + clientCampaigns.length },
  ]

  return (
    <div className="flex min-h-full flex-col p-6">
      <div className="mb-6 flex items-start justify-between">
        <div className="flex items-center gap-4">
          <button
            id="client-detail-back"
            onClick={() => navigate('/clients')}
            title="חזרה לרשימה"
            className="flex items-center gap-2 rounded-xl border border-[#e2e8f0] bg-white px-3.5 py-2 text-sm font-semibold text-[#64748b] shadow-sm transition hover:bg-[#f8fafc] hover:text-[#0f172a]"
          >
            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M9 5l7 7-7 7" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
            <span>חזרה לרשימה</span>
          </button>
          <div>
            <h1 className="text-3xl font-extrabold text-[#0f172a]">{client.name}</h1>
            <div className="mt-1 flex items-center gap-3 text-sm text-[#64748b]">
              <span>איש קשר: {client.contact || 'לא הוזן'}</span>
              <span>•</span>
              <span className="truncate">{client.email || 'ללא אימייל'}</span>
              <span>•</span>
              <span dir="ltr" className="text-right">{client.phone || 'ללא טלפון'}</span>
              <span>•</span>
              <Badge label={client.status === 'active' ? 'פעיל' : 'לא פעיל'} color={client.status === 'active' ? '#22c55e' : '#94a3b8'} />
            </div>
          </div>
        </div>
        <button
          onClick={openEdit}
          className="rounded-xl border border-[#e2e8f0] bg-white px-5 py-2 text-sm font-bold text-[#F97316] shadow-sm transition-all hover:-translate-y-0.5 hover:bg-[#ffedd5] hover:shadow"
        >
          עריכה
        </button>
      </div>

      {(maxDaysOverdue > 30 || totalRemainingHours === 0) && (
        <div className="mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 shadow-sm">
          <div className="rounded-full bg-red-100 p-2">
            <svg viewBox="0 0 24 24" className="h-5 w-5 text-red-600" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          </div>
          <div>
            <h3 className="text-sm font-black">לקוח בחריגת אשראי / בנק שעות ריק — אין להתחיל עבודה ללא אישור מנכ״ל!</h3>
            <p className="mt-1 text-xs font-medium text-red-700">
              {maxDaysOverdue > 30 && `קיימים חובות פתוחים מעל 30 יום. `}
              {totalRemainingHours === 0 && `בנק השעות התאפס.`}
            </p>
          </div>
        </div>
      )}

      {/* TABS */}
      <div className="mb-6 border-b border-[#e2e8f0]">
        <div className="flex gap-6 overflow-x-auto">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center gap-2 whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? 'border-[#F97316] text-[#F97316]'
                  : 'border-transparent text-[#64748b] hover:border-[#cbd5e1] hover:text-[#0f172a]'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* CONTENT AREA */}
      <div className="flex-1 rounded-2xl bg-white p-6 shadow-sm border border-[#e2e8f0]">
        {activeTab === 'overview' && (
          <div className="grid gap-6 md:grid-cols-2">
            <div className={`rounded-xl p-5 ${hubSectionClass(1)} bg-white/50 border border-white/20`}>
              <h3 className="mb-4 text-lg font-bold text-[#1e293b]">פרטי התקשרות</h3>
              <dl className="space-y-3 text-sm">
                <div className="flex justify-between border-b border-dashed border-[#e2e8f0] pb-2">
                  <dt className="text-[#64748b]">שם החברה / לקוח</dt>
                  <dd className="font-medium text-[#0f172a]">{client.name}</dd>
                </div>
                <div className="flex justify-between border-b border-dashed border-[#e2e8f0] pb-2">
                  <dt className="text-[#64748b]">איש קשר</dt>
                  <dd className="font-medium text-[#0f172a]">{client.contact || '-'}</dd>
                </div>
                <div className="flex justify-between border-b border-dashed border-[#e2e8f0] pb-2">
                  <dt className="text-[#64748b]">טלפון</dt>
                  <dd className="font-medium text-[#0f172a]" dir="ltr">{client.phone || '-'}</dd>
                </div>
                <div className="flex justify-between border-b border-dashed border-[#e2e8f0] pb-2">
                  <dt className="text-[#64748b]">אימייל</dt>
                  <dd className="font-medium text-[#0f172a]">{client.email || '-'}</dd>
                </div>
                <div className="flex justify-between pt-1">
                  <dt className="text-[#64748b]">תאריך הצטרפות</dt>
                  <dd className="font-medium text-[#0f172a]">{formatDateIL(client.createdAt) || '—'}</dd>
                </div>
              </dl>
            </div>

            <div className={`rounded-xl p-5 ${hubSectionClass(2)} bg-white/50 border border-white/20`}>
              <h3 className="mb-4 text-lg font-bold text-[#1e293b]">סיכום פעילות</h3>
              <div className="grid grid-cols-2 gap-4">
                <div onClick={() => setActiveTab('projects')} className="rounded-lg bg-white p-4 shadow-sm border border-[#e2e8f0] cursor-pointer hover:border-[#cbd5e1] hover:shadow-md transition-all">
                  <div className="text-2xl font-bold text-[#0f172a]">{clientProjects.length}</div>
                  <div className="text-xs font-medium text-[#64748b]">פרויקטים בארגון</div>
                </div>
                <div onClick={() => setActiveTab('projects')} className="rounded-lg bg-white p-4 shadow-sm border border-[#e2e8f0] cursor-pointer hover:border-[#cbd5e1] hover:shadow-md transition-all">
                  <div className="text-2xl font-bold text-[#0f172a]">{clientTasks.length}</div>
                  <div className="text-xs font-medium text-[#64748b]">משימות משויכות</div>
                </div>
                <div onClick={() => setActiveTab('finance')} className="rounded-lg bg-white p-4 shadow-sm border border-[#e2e8f0] cursor-pointer hover:border-[#cbd5e1] hover:shadow-md transition-all">
                  <div className="text-2xl font-bold text-[#0f172a]">{clientInvoices.length}</div>
                  <div className="text-xs font-medium text-[#64748b]">מסמכי הנהלת חשבונות</div>
                </div>
                <div onClick={() => setActiveTab('vault')} className="rounded-lg bg-white p-4 shadow-sm border border-[#e2e8f0] cursor-pointer hover:border-[#cbd5e1] hover:shadow-md transition-all">
                  <div className="text-2xl font-bold text-[#0f172a]">{clientPasswords.length}</div>
                  <div className="text-xs font-medium text-[#64748b]">פריטי כספת</div>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'projects' && (
          <div className="animate-in fade-in duration-300">
            <h3 className="mb-6 text-xl font-extrabold text-[#1e293b]">פרויקטים</h3>
            {clientProjects.length === 0 ? (
              <div className="rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center text-slate-500">
                אין פרויקטים ללקוח זה.
              </div>
            ) : (
              <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                {clientProjects.map(p => {
                  const pTasks = clientTasks.filter(t => t.projectId === p.id);
                  const pDone = pTasks.filter(t => {
                    const s = (t.status || '').toLowerCase();
                    return ['בוצע', 'done', 'הושלם', 'completed'].includes(s);
                  }).length;
                  const percent = pTasks.length > 0 ? Math.round((pDone / pTasks.length) * 100) : 0;

                  return (
                    <div key={p.id} className="flex flex-col rounded-2xl bg-white/60 backdrop-blur-sm border border-[#e2e8f0]/80 p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] transition-all hover:border-[#cbd5e1] hover:shadow-md">
                      <div className="flex justify-between items-start mb-3">
                        <h4 className="font-bold text-[#0f172a] truncate text-lg">{p.name}</h4>
                        <Badge label={p.status || 'פעיל'} color={p.status === 'active' ? '#22c55e' : '#94a3b8'} />
                      </div>
                      <p className="text-sm text-[#64748b] line-clamp-2 mb-5">{p.description || 'ללא תיאור'}</p>
                      
                      <div className="mt-auto">
                        <div className="flex justify-between text-xs text-[#64748b] mb-2 font-medium">
                          <span>התקדמות משימות ({pDone}/{pTasks.length})</span>
                          <span className="text-[#0f172a] font-bold">{percent}%</span>
                        </div>
                        <div className="h-2 w-full bg-[#f1f5f9] rounded-full overflow-hidden shadow-inner flex" dir="ltr">
                          <div className="h-full bg-gradient-to-r from-[#fb923c] to-[#F97316] rounded-full transition-all duration-700 ease-out" style={{ width: `${percent}%` }}></div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
            
            <div className="mt-12 mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-t border-slate-100 pt-8">
              <h3 className="text-xl font-extrabold text-[#1e293b] flex items-center gap-2">
                משימות קשורות 
                <span className="bg-[#f1f5f9] text-[#64748b] text-sm px-2.5 py-0.5 rounded-full font-medium">{clientTasks.length}</span>
              </h3>
              <div className="flex rounded-lg border border-slate-200 bg-slate-50 p-1 w-max">
                <button
                  onClick={() => setTaskViewMode('kanban')}
                  className={`flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-all ${taskViewMode === 'kanban' ? 'bg-white text-[#F97316] shadow-sm' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'}`}
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>
                  קנבן
                </button>
                <button
                  onClick={() => setTaskViewMode('table')}
                  className={`flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-all ${taskViewMode === 'table' ? 'bg-white text-[#F97316] shadow-sm' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'}`}
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                  טבלה
                </button>
              </div>
            </div>

            {taskViewMode === 'kanban' ? (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {[
                  { id: 'todo', label: 'לביצוע', color: 'bg-slate-50/80', border: 'border-slate-200', titleColor: 'text-slate-700' },
                  { id: 'in_progress', label: 'בתהליך', color: 'bg-blue-50/50', border: 'border-blue-100', titleColor: 'text-blue-700' },
                  { id: 'done', label: 'בוצע', color: 'bg-green-50/50', border: 'border-green-100', titleColor: 'text-green-700' }
                ].map(col => {
                  const colTasks = clientTasks.filter(t => {
                    const s = (t.status || '').toLowerCase();
                    const isDone = ['בוצע', 'done', 'הושלם', 'completed'].includes(s);
                    const isInProgress = ['בתהליך', 'in_progress', 'doing', 'wip'].includes(s);
                    if (col.id === 'done') return isDone;
                    if (col.id === 'in_progress') return isInProgress;
                    return !isDone && !isInProgress;
                  });

                  return (
                    <div key={col.id} className={`flex flex-col rounded-2xl border ${col.border} ${col.color} p-4 backdrop-blur-sm`}>
                      <h4 className={`font-bold ${col.titleColor} mb-4 flex items-center justify-between px-1`}>
                        {col.label}
                        <span className="text-xs bg-white/80 px-2 py-0.5 rounded-full shadow-sm">{colTasks.length}</span>
                      </h4>
                      <div className="flex flex-col gap-3 flex-1">
                        {colTasks.map(t => (
                          <div key={t.id} onClick={() => setDrawerTaskId(t.id)} className="bg-white p-4 rounded-xl shadow-sm border border-slate-100 hover:shadow-md transition-shadow group cursor-pointer">
                            <div className="font-medium text-[#0f172a] mb-3 leading-snug group-hover:text-[#F97316] transition-colors">{t.title}</div>
                            <div className="flex justify-between items-center text-xs">
                              <span className="text-slate-500 truncate max-w-[60%]">{clientProjects.find(p => p.id === t.projectId)?.name || 'ללא פרויקט'}</span>
                              <Badge label={boardStatusHe(t.status as TaskStatus) || 'לביצוע'} color={col.id === 'done' ? '#22c55e' : col.id === 'in_progress' ? '#3b82f6' : '#94a3b8'} />
                            </div>
                          </div>
                        ))}
                        {colTasks.length === 0 && (
                          <div className="flex-1 flex items-center justify-center py-8 text-slate-400 text-sm border-2 border-dashed border-slate-200 rounded-xl bg-white/30">
                            אין משימות
                          </div>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="overflow-x-auto rounded-xl border border-[#e2e8f0] bg-white shadow-sm">
                <table className="w-full text-sm text-right">
                  <thead className="bg-[#f8fafc] text-[#64748b]">
                    <tr>
                      <th className="px-4 py-3 font-medium">משימה</th>
                      <th className="px-4 py-3 font-medium">פרויקט</th>
                      <th className="px-4 py-3 font-medium">סטטוס</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#e2e8f0]">
                    {clientTasks.map(t => {
                      const s = (t.status || '').toLowerCase();
                      const isDone = ['בוצע', 'done', 'הושלם', 'completed'].includes(s);
                      
                      return (
                        <tr key={t.id} onClick={() => setDrawerTaskId(t.id)} className="hover:bg-[#f8fafc] cursor-pointer">
                          <td className="px-4 py-3 font-medium text-[#0f172a]">{t.title}</td>
                          <td className="px-4 py-3 text-[#64748b]">{clientProjects.find(p => p.id === t.projectId)?.name || '—'}</td>
                          <td className="px-4 py-3">
                            <Badge label={boardStatusHe(t.status as TaskStatus) || 'לביצוע'} color={isDone ? '#22c55e' : '#94a3b8'} />
                          </td>
                        </tr>
                      );
                    })}
                    {clientTasks.length === 0 && (
                      <tr><td colSpan={3} className="px-4 py-8 text-center text-slate-400">אין משימות קשורות</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {activeTab === 'finance' && (
          <div>
            <h3 className="mb-4 text-lg font-bold text-[#1e293b]">מסמכי הנהלת חשבונות</h3>
            {clientInvoices.length === 0 ? (
              <p className="text-sm text-[#64748b]">אין מסמכים ללקוח זה.</p>
            ) : (
              <div className="overflow-x-auto rounded-lg border border-[#e2e8f0]">
                <table className="w-full text-sm text-right">
                  <thead className="bg-[#f8fafc] text-[#64748b]">
                    <tr>
                      <th className="px-4 py-3 font-medium">סוג</th>
                      <th className="px-4 py-3 font-medium">מספר</th>
                      <th className="px-4 py-3 font-medium">תיאור</th>
                      <th className="px-4 py-3 font-medium">תאריך</th>
                      <th className="px-4 py-3 font-medium">סכום</th>
                      <th className="px-4 py-3 font-medium">סטטוס</th>
                      <th className="px-4 py-3 font-medium">פעולות</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#e2e8f0]">
                    {clientInvoices.map(inv => {
                      const anyInv = inv as any
                      const rawStatus = String(inv.status || '')
                      let statusText = 'ממתין'
                      let statusColor = '#94a3b8'
                      if (rawStatus === 'paid' || rawStatus === 'שולם') {
                        statusText = 'שולם'
                        statusColor = '#22c55e'
                      } else if (rawStatus === 'partial' || rawStatus === 'חלקי') {
                        statusText = 'חלקי'
                        statusColor = '#f59e0b'
                      } else if (rawStatus === 'overdue') {
                        statusText = 'באיחור'
                        statusColor = '#ef4444'
                      }

                      const invIdStr = String(inv?.id || '')
                      const giDocId = anyInv.greenInvoiceId || (invIdStr.startsWith('gi_') ? invIdStr.slice(3) : '')
                      const morningUrl = giDocId ? `https://app.greeninvoice.co.il/incomes/documents/${giDocId}` : ''
                      const token = localStorage.getItem('hub_os_token') || ''
                      const pdfUrl = giDocId ? `/api/finance/invoice/${encodeURIComponent(giDocId)}/download${token ? `?token=${encodeURIComponent(token)}` : ''}` : ''
                      const invNum = anyInv.invoice_number || inv.invoiceNumber || '—'
                      const docType = anyInv.doc_type || inv.docType || '—'
                      const dueDate = anyInv.due_date || inv.dueDate || inv.createdAt
                      
                      return (
                        <tr key={inv.id} className="hover:bg-[#f8fafc]">
                          <td className="px-4 py-3 text-[#334155]">{docType}</td>
                          <td className="px-4 py-3 font-medium text-[#0f172a]">{invNum}</td>
                          <td className="px-4 py-3 text-[#334155] max-w-[200px] truncate" title={inv.description || ''}>{inv.description || '—'}</td>
                          <td className="px-4 py-3 text-[#64748b]">{formatDateIL(dueDate)}</td>
                          <td className="px-4 py-3 font-medium text-[#0f172a] tabular-nums">₪{(inv.amount ?? 0).toLocaleString()}</td>
                          <td className="px-4 py-3"><Badge label={statusText} color={statusColor} /></td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-1.5">
                              {morningUrl && (
                                <a href={morningUrl} target="_blank" rel="noopener noreferrer" title="צפה ב-Morning" className="rounded-md p-1.5 text-[#64748b] transition-colors hover:bg-[#f1f5f9] hover:text-[#0ea5e9]">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                              )}
                              {pdfUrl && (
                                <a href={pdfUrl} target="_blank" rel="noopener noreferrer" title="הורד PDF" className="rounded-md p-1.5 text-[#64748b] transition-colors hover:bg-[#f1f5f9] hover:text-[#ef4444]">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </a>
                              )}
                              <button type="button" title="העתק מספר חשבונית" onClick={() => { navigator.clipboard.writeText(invNum === '—' ? '' : invNum); }} className="rounded-md p-1.5 text-[#64748b] transition-colors hover:bg-[#f1f5f9] hover:text-[#10b981]">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {activeTab === 'vault' && (
          <div>
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div>
                <h3 className="text-xl font-extrabold text-[#1e293b] flex items-center gap-2">
                  כספת סיסמאות ונכסים
                  <span className="bg-[#f1f5f9] text-[#64748b] text-xs px-2.5 py-1 rounded-full font-semibold">
                    {clientPasswords.length}
                  </span>
                </h3>
                <p className="mt-1 text-sm text-[#64748b]">
                  ניהול פרטי גישה, אימות תקינות ומחיקת סיסמאות ישנות או תקולות
                </p>
              </div>
            </div>

            {clientPasswords.length === 0 ? (
              <div className="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 p-12 text-center">
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 mb-3">
                  <KeyRound className="h-6 w-6" />
                </div>
                <h4 className="font-semibold text-slate-700">אין פריטים בכספת</h4>
                <p className="text-sm text-slate-500 mt-1">לא נמצאו פרטי גישה או סיסמאות המשויכים ללקוח זה.</p>
              </div>
            ) : (
              <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                {clientPasswords.map((pw) => {
                  const anyPw = pw as any
                  const isVerified = pw.status === 'verified'
                  const displayTitle = pw.title || anyPw.service || 'פריט גישה'
                  const displayUrl = pw.url || anyPw.loginUrl
                  const isLoadingVerify = actionLoading[pw.id] === 'verify'
                  const isLoadingDelete = actionLoading[pw.id] === 'delete'
                  const isBusy = Boolean(actionLoading[pw.id])

                  return (
                    <div
                      key={pw.id}
                      className={`relative flex flex-col justify-between rounded-2xl border p-5 shadow-xs transition-all duration-200 hover:shadow-md ${
                        isVerified
                          ? 'border-emerald-200/90 bg-gradient-to-b from-emerald-50/25 via-white to-white'
                          : 'border-slate-200/90 bg-white hover:border-slate-300'
                      }`}
                    >
                      {/* Top Header */}
                      <div>
                        <div className="flex items-start justify-between gap-3 mb-3">
                          <div className="flex items-center gap-3 min-w-0">
                            <div
                              className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border ${
                                isVerified
                                  ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                  : 'bg-orange-50 text-[#F97316] border-orange-100'
                              }`}
                            >
                              <KeyRound className="h-5 w-5" />
                            </div>
                            <div className="min-w-0 flex-1">
                              <h4
                                className="font-bold text-[#0f172a] truncate text-base leading-tight"
                                title={displayTitle}
                              >
                                {displayTitle}
                              </h4>
                              {pw.category && (
                                <span className="text-xs text-slate-400 block truncate mt-0.5">
                                  {pw.category}
                                </span>
                              )}
                            </div>
                          </div>

                          {/* Status Badge */}
                          {isVerified ? (
                            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 border border-emerald-200">
                              <Check className="h-3.5 w-3.5 stroke-[2.5]" />
                              מאומת
                            </span>
                          ) : pw.status === 'pending_verification' ? (
                            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 border border-amber-200">
                              ממתין לאימות
                            </span>
                          ) : pw.status ? (
                            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 border border-slate-200">
                              {pw.status}
                            </span>
                          ) : null}
                        </div>

                        {/* URL Link */}
                        {displayUrl && (
                          <div className="mb-3">
                            <a
                              href={displayUrl}
                              target="_blank"
                              rel="noreferrer"
                              className="inline-flex max-w-full items-center gap-1.5 text-xs font-medium text-blue-600 hover:text-blue-700 hover:underline truncate"
                              dir="ltr"
                              title={displayUrl}
                            >
                              <ExternalLink className="h-3 w-3 shrink-0" />
                              <span className="truncate">{displayUrl}</span>
                            </a>
                          </div>
                        )}

                        {/* Credentials Details Box */}
                        <div className="space-y-2 rounded-xl bg-slate-50/80 p-3.5 border border-slate-100 text-sm">
                          {/* Username */}
                          <div className="flex items-center justify-between gap-2">
                            <span className="text-xs text-slate-500 font-medium shrink-0">שם משתמש:</span>
                            <div className="flex items-center gap-1.5 min-w-0">
                              <span
                                className="font-mono text-xs font-medium text-slate-800 bg-white/80 px-2 py-0.5 rounded border border-slate-200/50 truncate max-w-[150px]"
                                dir="ltr"
                                title={pw.username || '-'}
                              >
                                {pw.username || '-'}
                              </span>
                              {pw.username && (
                                <button
                                  type="button"
                                  onClick={() => handleCopy(`user_${pw.id}`, pw.username)}
                                  className="p-1 text-slate-400 hover:text-slate-700 rounded hover:bg-slate-200/60 transition-colors"
                                  title="העתק שם משתמש"
                                >
                                  {copiedKey === `user_${pw.id}` ? (
                                    <Check className="h-3.5 w-3.5 text-emerald-600" />
                                  ) : (
                                    <Copy className="h-3.5 w-3.5" />
                                  )}
                                </button>
                              )}
                            </div>
                          </div>

                          {/* Password */}
                          <div className="flex items-center justify-between gap-2 pt-2 border-t border-slate-200/50">
                            <span className="text-xs text-slate-500 font-medium shrink-0">סיסמה:</span>
                            <div className="flex items-center gap-1.5 min-w-0">
                              <span
                                className="font-mono text-xs font-medium text-slate-800 bg-white/80 px-2 py-0.5 rounded border border-slate-200/50 truncate max-w-[150px]"
                                dir="ltr"
                              >
                                {revealedPasswords[pw.id] ? (pw.password || '—') : '••••••••'}
                              </span>
                              <button
                                type="button"
                                onClick={() => setRevealedPasswords((prev) => ({ ...prev, [pw.id]: !prev[pw.id] }))}
                                className="p-1 text-slate-400 hover:text-slate-700 rounded hover:bg-slate-200/60 transition-colors"
                                title={revealedPasswords[pw.id] ? 'הסתר סיסמה' : 'הצג סיסמה'}
                              >
                                {revealedPasswords[pw.id] ? (
                                  <EyeOff className="h-3.5 w-3.5" />
                                ) : (
                                  <Eye className="h-3.5 w-3.5" />
                                )}
                              </button>
                              {pw.password && (
                                <button
                                  type="button"
                                  onClick={() => handleCopy(`pass_${pw.id}`, pw.password)}
                                  className="p-1 text-slate-400 hover:text-slate-700 rounded hover:bg-slate-200/60 transition-colors"
                                  title="העתק סיסמה"
                                >
                                  {copiedKey === `pass_${pw.id}` ? (
                                    <Check className="h-3.5 w-3.5 text-emerald-600" />
                                  ) : (
                                    <Copy className="h-3.5 w-3.5" />
                                  )}
                                </button>
                              )}
                            </div>
                          </div>
                        </div>

                        {/* Notes if available */}
                        {pw.notes && (
                          <p
                            className="mt-2.5 text-xs text-slate-500 bg-white/90 p-2.5 rounded-lg border border-slate-100 line-clamp-2"
                            title={pw.notes}
                          >
                            {pw.notes}
                          </p>
                        )}

                        {/* Source & SourceDate Display */}
                        {(pw.source || pw.sourceDate) && (
                          <div className="mt-3.5 flex flex-wrap items-center gap-2 pt-2.5 border-t border-dashed border-slate-200 text-xs">
                            {pw.source && (
                              <span className="inline-flex items-center gap-1.5 rounded-lg bg-slate-100/90 px-2.5 py-1 font-medium text-slate-700 border border-slate-200/70 shadow-2xs">
                                <HardDrive className="h-3.5 w-3.5 text-slate-500" />
                                <span>מקור: <strong className="font-bold text-slate-900">{pw.source}</strong></span>
                              </span>
                            )}
                            {pw.sourceDate && (
                              <span
                                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1 text-slate-600 border border-slate-200/50 shadow-2xs"
                                title={pw.sourceDate}
                              >
                                <Calendar className="h-3.5 w-3.5 text-slate-400" />
                                <span>תאריך מקור: {formatDateIL(pw.sourceDate)}</span>
                              </span>
                            )}
                          </div>
                        )}
                      </div>

                      {/* Verify & Delete Buttons (Check for 'Works', X for 'Broken') */}
                      <div className="mt-4 pt-3.5 border-t border-slate-100 flex items-center justify-between gap-2">
                        <span className="text-xs font-semibold text-slate-500">אימות תקינות:</span>
                        <div className="flex items-center gap-2">
                          {/* Check for 'Works' (Verify) */}
                          <button
                            type="button"
                            onClick={() => handleVerify(pw.id)}
                            disabled={isBusy || isVerified}
                            title={isVerified ? 'הסיסמה כבר מאומתת' : 'סמן כעובד (מאמת את הסיסמה)'}
                            className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all shadow-xs ${
                              isVerified
                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 cursor-default'
                                : 'bg-emerald-600 text-white hover:bg-emerald-700 hover:shadow-emerald-600/20 active:scale-95'
                            } disabled:opacity-80`}
                          >
                            {isLoadingVerify ? (
                              <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                              <Check className="h-3.5 w-3.5 stroke-[2.5]" />
                            )}
                            <span>{isVerified ? 'מאומת (עובד)' : 'עובד'}</span>
                          </button>

                          {/* X for 'Broken' (Delete) */}
                          <button
                            type="button"
                            onClick={() => handleDelete(pw.id, displayTitle)}
                            disabled={isBusy}
                            title="סמן כתקול ומחק פריט זה מהכספת"
                            className="flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition-all hover:bg-rose-600 hover:text-white hover:border-rose-600 hover:shadow-xs active:scale-95 disabled:opacity-50"
                          >
                            {isLoadingDelete ? (
                              <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                              <X className="h-3.5 w-3.5 stroke-[2.5]" />
                            )}
                            <span>תקול (מחיקה)</span>
                          </button>
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}

        {activeTab === 'hosting' && (
          <div>
            <h3 className="mb-4 text-lg font-bold text-[#1e293b]">תשתיות ואחסון</h3>
            {clientHosting.length === 0 ? (
              <p className="text-sm text-[#64748b]">אין רישומי אחסון ללקוח זה.</p>
            ) : (
              <div className="grid gap-4 md:grid-cols-2">
                {clientHosting.map(h => {
                  const anyH = h as any
                  return (
                    <div key={h.id} className="rounded-xl border border-[#e2e8f0] p-4">
                      <h4 className="font-bold text-[#0f172a] truncate text-lg mb-1">{h.siteName || h.domain || '—'}</h4>
                      <div className="text-sm text-[#64748b] space-y-1 mt-3">
                        <div className="flex justify-between"><span className="text-[#94a3b8]">שרת:</span> <span className="font-medium text-[#334155]">{h.serverProvider || anyH.platform || '—'}</span></div>
                        {anyH.ipAddress && <div className="flex justify-between"><span className="text-[#94a3b8]">IP:</span> <span className="font-medium text-[#334155]" dir="ltr">{anyH.ipAddress}</span></div>}
                        <div className="flex justify-between"><span className="text-[#94a3b8]">סוג שרת:</span> <span className="font-medium text-[#334155]">{h.serverType || '—'}</span></div>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}

        {activeTab === 'leads' && (
          <div>
            <h3 className="mb-4 text-lg font-bold text-[#1e293b]">לידים</h3>
            {clientLeads.length === 0 ? (
              <p className="text-sm text-[#64748b]">אין לידים ללקוח זה.</p>
            ) : (
              <ul className="space-y-3 mb-8">
                {clientLeads.map(l => (
                  <li key={l.id} className="rounded-lg border border-[#e2e8f0] p-3 flex justify-between items-center bg-[#f8fafc]">
                    <div>
                      <div className="font-bold text-[#0f172a]">{l.name}</div>
                      <div className="text-xs text-[#64748b]">{l.phone || l.email}</div>
                    </div>
                    <Badge label={l.status || 'חדש'} color="#94a3b8" />
                  </li>
                ))}
              </ul>
            )}

            <h3 className="mb-4 text-lg font-bold text-[#1e293b]">קמפיינים</h3>
            {clientCampaigns.length === 0 ? (
              <p className="text-sm text-[#64748b]">אין קמפיינים ללקוח זה.</p>
            ) : (
              <div className="grid gap-3 md:grid-cols-2">
                {clientCampaigns.map(c => (
                  <div key={c.id} className="rounded-lg border border-[#e2e8f0] p-3">
                    <div className="font-bold text-[#0f172a] truncate">{c.name}</div>
                    <div className="text-xs text-[#64748b] mt-1">{c.platform} • {c.status}</div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      <Modal
        isOpen={modalOpen}
        onClose={() => setModalOpen(false)}
        title="עריכת לקוח"
      >
        <div className="space-y-5 px-1 py-2">
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              שם חברה *
            </label>
            <input
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
            />
            {errors.name ? (
              <p className="mt-1.5 text-xs font-bold text-red-500">{errors.name}</p>
            ) : null}
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              ח.פ / עוסק (הזן ולחץ להשלמה)
            </label>
            <div className="flex gap-2">
              <input
                className="w-full flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
                value={form.taxId}
                onChange={(e) =>
                  setForm((f) => ({ ...f, taxId: e.target.value }))
                }
              />
              <button
                type="button"
                onClick={enrichCompany}
                disabled={enrichLoading}
                className="rounded-xl border border-slate-200 bg-white px-3 text-[#0f172a] hover:bg-slate-50 disabled:opacity-50"
                title="השלם נתונים אוטומטית מ-Data.gov.il"
              >
                {enrichLoading ? '⏳' : '🪄'}
              </button>
            </div>
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              איש קשר
            </label>
            <input
              value={form.contact}
              onChange={(e) => setForm((f) => ({ ...f, contact: e.target.value }))}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
            />
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              טלפון
            </label>
            <input
              value={form.phone}
              onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
            />
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              מחלקה
            </label>
            <input
              value={form.department}
              onChange={(e) => setForm((f) => ({ ...f, department: e.target.value }))}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
            />
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              אימייל
            </label>
            <input
              type="email"
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
            />
            {errors.email ? (
              <p className="mt-1.5 text-xs font-bold text-red-500">{errors.email}</p>
            ) : null}
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              דומיין
            </label>
            <input
              type="text"
              value={form.domain}
              onChange={(e) => setForm((f) => ({ ...f, domain: e.target.value }))}
              placeholder="example.co.il"
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
              dir="ltr"
            />
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-semibold text-[#0f172a]">
              סטטוס
            </label>
            <select
              value={form.status}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as ClientStatus }))}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-[#F97316] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#F97316]/10"
            >
              <option value="active">פעיל</option>
              <option value="inactive">לא פעיל</option>
            </select>
          </div>
          <div className="flex justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => setModalOpen(false)}
              className="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition-all hover:bg-slate-50 hover:shadow"
            >
              ביטול
            </button>
            <button
              type="button"
              onClick={submitEdit}
              className="rounded-xl bg-[#F97316] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:-translate-y-0.5 hover:bg-[#ea580c] hover:shadow-md"
            >
              שמור
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
