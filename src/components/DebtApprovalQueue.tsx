import { useState, useEffect, useMemo, useCallback } from 'react'
import {
  ShieldCheck,
  RefreshCw,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  Clock,
  Search,
  ChevronDown,
  ChevronUp,
  FileText,
  Mail,
  Send,
  Info,
  Check,
} from 'lucide-react'
import { collectionApi } from '../services/api'
import { formatNis } from '../lib/financeCalc'

export interface CollectionDraft {
  id: string
  client_id?: string
  client_name: string
  client_email?: string
  invoice_id: string
  invoice_number?: string
  invoice_date: string
  due_date: string
  amount: number
  paid_amount: number
  open_amount: number
  days_overdue: number
  reminder_type: 'overdue_notice' | 'friendly_reminder' | 'urgent_demand'
  subject: string
  message_body: string
  status: 'draft' | 'approved' | 'dismissed'
  approved_at?: string | null
  dismissed_at?: string | null
  created_at: string
  updated_at: string
}

export interface CollectionSummary {
  totalDrafts: number
  openDraftsCount: number
  approvedCount: number
  dismissedCount: number
  totalOpenDebt: number
  overdue30DaysDebt: number
  isDryRun: boolean
}

type TabFilter = 'draft' | 'approved' | 'dismissed' | 'all'
type SeverityFilter = 'all' | 'critical' | 'medium' | 'mild'

export function DebtApprovalQueue() {
  const [drafts, setDrafts] = useState<CollectionDraft[]>([])
  const [summary, setSummary] = useState<CollectionSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [scanning, setScanning] = useState(false)
  const [actionLoadingId, setActionLoadingId] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<TabFilter>('draft')
  const [severityFilter, setSeverityFilter] = useState<SeverityFilter>('all')
  const [searchQuery, setSearchQuery] = useState('')
  const [expandedDraftIds, setExpandedDraftIds] = useState<Set<string>>(new Set())
  const [notification, setNotification] = useState<{ type: 'success' | 'error' | 'info'; message: string } | null>(null)

  const showNotification = useCallback((type: 'success' | 'error' | 'info', message: string) => {
    setNotification({ type, message })
    setTimeout(() => {
      setNotification((curr) => (curr?.message === message ? null : curr))
    }, 4500)
  }, [])

  const loadData = useCallback(async () => {
    setLoading(true)
    try {
      const [draftsRes, summaryRes] = await Promise.all([
        collectionApi.getDrafts('all'),
        collectionApi.getSummary().catch(() => null),
      ])

      if (draftsRes.ok && Array.isArray(draftsRes.drafts)) {
        setDrafts(draftsRes.drafts)
      }
      if (summaryRes?.ok && summaryRes.summary) {
        setSummary(summaryRes.summary)
      }
    } catch (err: any) {
      showNotification('error', `שגיאה בטעינת נתונים: ${(err as any).message}`)
    } finally {
      setLoading(false)
    }
  }, [showNotification])

  useEffect(() => {
    loadData()
  }, [loadData])

  const handleScan = async () => {
    setScanning(true)
    try {
      const res = await collectionApi.scan()
      if (res.ok) {
        showNotification(
          'success',
          `הסריקה הושלמה בהצלחה: ${res.created} טיוטות חדשות נוצרו, ${res.updated} עודכנו (DRY_RUN פעיל)`,
        )
        await loadData()
      } else {
        showNotification('error', res.message || 'הסריקה נכשלה')
      }
    } catch (err: any) {
      showNotification('error', `שגיאה בהפעלת סריקה: ${(err as any).message}`)
    } finally {
      setScanning(false)
    }
  };

  const handleApprove = async (draftId: string) => {
    setActionLoadingId(draftId)
    try {
      const res = await collectionApi.approveDraft(draftId)
      if (res.ok) {
        setDrafts((prev) =>
          prev.map((d) =>
            d.id === draftId
              ? { ...d, status: 'approved', approved_at: new Date().toISOString() }
              : d,
          ),
        )
        if (summary) {
          setSummary({
            ...summary,
            openDraftsCount: Math.max(0, summary.openDraftsCount - 1),
            approvedCount: summary.approvedCount + 1,
          })
        }
        showNotification(
          'success',
          res.message || 'הטיוטה אושרה והועברה לתור לשליחה מבוקרת (DRY_RUN - ללא שליחה במייל)',
        )
      } else {
        showNotification('error', res.message || 'אישור הטיוטה נכשל')
      }
    } catch (err: any) {
      showNotification('error', `שגיאה באישור: ${(err as any).message}`)
    } finally {
      setActionLoadingId(null)
    }
  }

  const handleDismiss = async (draftId: string) => {
    setActionLoadingId(draftId)
    try {
      const res = await collectionApi.dismissDraft(draftId, 'בוטל על ידי משתמש בתור האישורים')
      if (res.ok) {
        setDrafts((prev) =>
          prev.map((d) =>
            d.id === draftId
              ? { ...d, status: 'dismissed', dismissed_at: new Date().toISOString() }
              : d,
          ),
        )
        if (summary) {
          setSummary({
            ...summary,
            openDraftsCount: Math.max(0, summary.openDraftsCount - 1),
            dismissedCount: summary.dismissedCount + 1,
          })
        }
        showNotification('info', res.message || 'הטיוטה בוטלה והוסרה מהתור')
      } else {
        showNotification('error', res.message || 'ביטול הטיוטה נכשל')
      }
    } catch (err: any) {
      showNotification('error', `שגיאה בביטול: ${(err as any).message}`)
    } finally {
      setActionLoadingId(null)
    }
  }

  const toggleExpand = (draftId: string) => {
    setExpandedDraftIds((prev) => {
      const next = new Set(prev)
      if (next.has(draftId)) next.delete(draftId)
      else next.add(draftId)
      return next
    })
  }

  // Filtered drafts list
  const filteredDrafts = useMemo(() => {
    return drafts.filter((d) => {
      // Status filter
      if (activeTab !== 'all' && d.status !== activeTab) {
        return false
      }

      // Severity filter
      if (severityFilter === 'critical' && d.days_overdue <= 30) return false
      if (severityFilter === 'medium' && (d.days_overdue < 15 || d.days_overdue > 30)) return false
      if (severityFilter === 'mild' && (d.days_overdue < 1 || d.days_overdue >= 15)) return false

      // Search query
      if (searchQuery.trim()) {
        const q = searchQuery.trim().toLowerCase()
        const clientMatch = d.client_name?.toLowerCase().includes(q)
        const invMatch = d.invoice_number?.toLowerCase().includes(q)
        const emailMatch = d.client_email?.toLowerCase().includes(q)
        if (!clientMatch && !invMatch && !emailMatch) return false
      }

      return true
    })
  }, [drafts, activeTab, severityFilter, searchQuery])

  // Overdue badge styling
  const renderOverdueBadge = (days: number) => {
    if (days > 30) {
      return (
        <span className="inline-flex items-center gap-1 rounded-full border border-red-200 bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700">
          <AlertTriangle className="h-3.5 w-3.5 text-red-600" />
          {days} ימים באיחור
        </span>
      )
    }
    if (days > 14) {
      return (
        <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700">
          <Clock className="h-3.5 w-3.5 text-amber-600" />
          {days} ימים באיחור
        </span>
      )
    }
    if (days > 0) {
      return (
        <span className="inline-flex items-center gap-1 rounded-full border border-yellow-200 bg-yellow-50 px-2.5 py-0.5 text-xs font-medium text-yellow-800">
          <Clock className="h-3.5 w-3.5 text-yellow-600" />
          {days} ימים באיחור
        </span>
      )
    }
    return (
      <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
        <Check className="h-3.5 w-3.5 text-emerald-600" />
        במועד
      </span>
    )
  }

  return (
    <div className="w-full space-y-6 text-right" dir="rtl">
      {/* Toast Notification */}
      {notification && (
        <div
          className={`flex items-center justify-between gap-3 rounded-xl border p-4 shadow-lg transition-all ${
            notification.type === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
              : notification.type === 'error'
              ? 'border-red-200 bg-red-50 text-red-900'
              : 'border-blue-200 bg-blue-50 text-blue-900'
          }`}
        >
          <div className="flex items-center gap-2">
            {notification.type === 'success' && <CheckCircle2 className="h-5 w-5 text-emerald-600" />}
            {notification.type === 'error' && <XCircle className="h-5 w-5 text-red-600" />}
            {notification.type === 'info' && <Info className="h-5 w-5 text-blue-600" />}
            <span className="text-sm font-semibold">{notification.message}</span>
          </div>
          <button
            onClick={() => setNotification(null)}
            className="text-xs font-medium opacity-70 hover:opacity-100"
          >
            ✕
          </button>
        </div>
      )}

      {/* Safety Banner & Actions Header */}
      <div className="flex flex-col gap-4 rounded-2xl border border-emerald-200 bg-gradient-to-l from-emerald-50/70 via-white to-blue-50/40 p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <div className="rounded-xl bg-emerald-600 p-2.5 text-white shadow-md shadow-emerald-600/20">
            <ShieldCheck className="h-6 w-6" />
          </div>
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-extrabold text-gray-900">
                תור אישור תזכורות גבייה (HUB Collection Queue)
              </h2>
              <span className="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">
                DRY_RUN: פעיל (מצב בטוח)
              </span>
            </div>
            <p className="mt-1 text-xs leading-relaxed text-gray-600">
              כל התזכורות מנוהלות בתור מבוקר. אישור טיוטה מוסיף אותה לתור בטוח ללא הפעלת שרתי דואר חיצוניים (Zero SMTP Side-Effects).
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={handleScan}
            disabled={scanning || loading}
            className="inline-flex items-center gap-2 rounded-xl bg-gray-900 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-gray-800 active:scale-95 disabled:opacity-50"
          >
            <RefreshCw className={`h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
            {scanning ? 'סורק חובות…' : 'רענן וסרוק חובות'}
          </button>
        </div>
      </div>

      {/* KPI Stats Cards */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>סה״כ חוב פתוח בטיוטות</span>
            <span className="rounded bg-amber-50 p-1 text-amber-600">₪</span>
          </div>
          <div className="mt-2 text-2xl font-black text-amber-600">
            {formatNis(summary?.totalOpenDebt ?? 0)}
          </div>
          <p className="mt-1 text-[11px] text-gray-400">יתרה לגבייה עבור חשבוניות פתוחות</p>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>טיוטות ממתינות לאישור</span>
            <FileText className="h-4 w-4 text-blue-500" />
          </div>
          <div className="mt-2 text-2xl font-black text-blue-600">
            {summary?.openDraftsCount ?? drafts.filter((d) => d.status === 'draft').length}
          </div>
          <p className="mt-1 text-[11px] text-gray-400">דורשות בדיקה ואישור אנושי בתור</p>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>חוב באיחור קריטי (+30 יום)</span>
            <AlertTriangle className="h-4 w-4 text-red-500" />
          </div>
          <div className="mt-2 text-2xl font-black text-red-600">
            {formatNis(summary?.overdue30DaysDebt ?? 0)}
          </div>
          <p className="mt-1 text-[11px] text-gray-400">דורש עדיפות עליונה בטיפול</p>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>אושרו והועברו לתור</span>
            <CheckCircle2 className="h-4 w-4 text-emerald-500" />
          </div>
          <div className="mt-2 text-2xl font-black text-emerald-600">
            {summary?.approvedCount ?? drafts.filter((d) => d.status === 'approved').length}
          </div>
          <p className="mt-1 text-[11px] text-gray-400">שמורים בתור מאובטח ללא שליחה</p>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm md:flex-row md:items-center md:justify-between">
        {/* Status Tabs */}
        <div className="flex flex-wrap items-center gap-1 rounded-xl bg-gray-100 p-1 text-xs font-bold">
          <button
            type="button"
            onClick={() => setActiveTab('draft')}
            className={`rounded-lg px-3 py-1.5 transition ${
              activeTab === 'draft'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-900'
            }`}
          >
            ממתינות לאישור ({drafts.filter((d) => d.status === 'draft').length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('approved')}
            className={`rounded-lg px-3 py-1.5 transition ${
              activeTab === 'approved'
                ? 'bg-white text-emerald-800 shadow-sm'
                : 'text-gray-500 hover:text-gray-900'
            }`}
          >
            אושרו בתור ({drafts.filter((d) => d.status === 'approved').length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('dismissed')}
            className={`rounded-lg px-3 py-1.5 transition ${
              activeTab === 'dismissed'
                ? 'bg-white text-gray-700 shadow-sm'
                : 'text-gray-500 hover:text-gray-900'
            }`}
          >
            בוטלו ({drafts.filter((d) => d.status === 'dismissed').length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('all')}
            className={`rounded-lg px-3 py-1.5 transition ${
              activeTab === 'all'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-900'
            }`}
          >
            הכל ({drafts.length})
          </button>
        </div>

        {/* Severity & Search Controls */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Overdue filter */}
          <select
            value={severityFilter}
            onChange={(e) => setSeverityFilter(e.target.value as SeverityFilter)}
            className="rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 focus:border-gray-900 focus:outline-none"
          >
            <option value="all">כל רמות האיחור</option>
            <option value="critical">קריטי (+30 יום)</option>
            <option value="medium">בינוני (15-30 יום)</option>
            <option value="mild">קל (1-14 יום)</option>
          </select>

          {/* Search box */}
          <div className="relative min-w-[200px]">
            <Search className="absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="חיפוש לקוח / חשבונית..."
              className="w-full rounded-xl border border-gray-200 bg-white py-1.5 pr-8 pl-3 text-xs text-gray-800 placeholder-gray-400 focus:border-gray-900 focus:outline-none"
            />
          </div>
        </div>
      </div>

      {/* Drafts List / Table */}
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        {loading ? (
          <div className="flex flex-col items-center justify-center py-16 text-gray-400">
            <RefreshCw className="h-8 w-8 animate-spin text-gray-400" />
            <p className="mt-3 text-sm font-medium">טוען תור אישורים…</p>
          </div>
        ) : filteredDrafts.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 text-center">
            <CheckCircle2 className="h-12 w-12 text-emerald-400" />
            <h3 className="mt-3 text-base font-bold text-gray-800">
              {activeTab === 'draft' ? 'אין טיוטות הממתינות לאישור' : 'לא נמצאו טיוטות מתאימות'}
            </h3>
            <p className="mt-1 text-xs text-gray-500">
              {activeTab === 'draft'
                ? 'כל החובות עודכנו, או שכל הטיוטות אושרו והועברו לתור.'
                : 'נסה לשנות את מסנני החיפוש או לבצע סריקה מחדש.'}
            </p>
          </div>
        ) : (
          <div className="divide-y divide-gray-100">
            {filteredDrafts.map((draft) => {
              const isExpanded = expandedDraftIds.has(draft.id)
              const isActionLoading = actionLoadingId === draft.id

              return (
                <div
                  key={draft.id}
                  className={`p-4 transition hover:bg-gray-50/70 sm:p-5 ${
                    draft.status === 'approved' ? 'bg-emerald-50/20' : ''
                  }`}
                >
                  <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    {/* Left/Client Info */}
                    <div className="min-w-0 flex-1 space-y-1.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-base font-extrabold text-gray-900">
                          {draft.client_name}
                        </span>
                        {draft.client_email && (
                          <span className="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600" dir="ltr">
                            <Mail className="h-3 w-3 text-gray-400" />
                            {draft.client_email}
                          </span>
                        )}
                        {renderOverdueBadge(draft.days_overdue)}
                      </div>

                      <div className="flex flex-wrap items-center gap-3 text-xs text-gray-500">
                        <span>
                          חשבונית מס׳: <strong className="text-gray-700">{draft.invoice_number || '—'}</strong>
                        </span>
                        <span>•</span>
                        <span>
                          תאריך הפקה: <span className="text-gray-700">{draft.invoice_date}</span>
                        </span>
                        <span>•</span>
                        <span>
                          מועד פירעון: <strong className="text-gray-800">{draft.due_date}</strong>
                        </span>
                      </div>
                    </div>

                    {/* Middle: Debt Breakdown */}
                    <div className="flex items-center gap-6 rounded-xl bg-gray-50 px-4 py-2 text-xs">
                      <div>
                        <div className="text-[10px] text-gray-400">סכום מקורי</div>
                        <div className="font-semibold text-gray-600">{formatNis(draft.amount)}</div>
                      </div>
                      <div className="h-6 w-px bg-gray-200" />
                      <div>
                        <div className="text-[10px] text-gray-400">שולם בפועל</div>
                        <div className="font-semibold text-emerald-600">
                          {formatNis(draft.paid_amount || 0)}
                        </div>
                      </div>
                      <div className="h-6 w-px bg-gray-200" />
                      <div>
                        <div className="text-[10px] font-bold text-amber-700">יתרה לתשלום</div>
                        <div className="text-sm font-extrabold text-amber-700">
                          {formatNis(draft.open_amount)}
                        </div>
                      </div>
                    </div>

                    {/* Right: Actions */}
                    <div className="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        onClick={() => toggleExpand(draft.id)}
                        className="inline-flex items-center gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                      >
                        {isExpanded ? 'הסתר נוסח' : 'תצוגה מקדימה'}
                        {isExpanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
                      </button>

                      {draft.status === 'draft' ? (
                        <>
                          <button
                            type="button"
                            onClick={() => handleApprove(draft.id)}
                            disabled={isActionLoading}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95 disabled:opacity-50"
                          >
                            {isActionLoading ? (
                              <RefreshCw className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                              <CheckCircle2 className="h-3.5 w-3.5" />
                            )}
                            אישור והוספה לתור
                          </button>

                          <button
                            type="button"
                            onClick={() => handleDismiss(draft.id)}
                            disabled={isActionLoading}
                            title="ביטול טיוטה"
                            className="inline-flex items-center gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-500 hover:bg-red-50 hover:text-red-700 disabled:opacity-50"
                          >
                            <XCircle className="h-3.5 w-3.5" />
                            התעלם
                          </button>
                        </>
                      ) : draft.status === 'approved' ? (
                        <span className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-100 px-3.5 py-2 text-xs font-bold text-emerald-800">
                          <Check className="h-3.5 w-3.5 text-emerald-700" />
                          אושר בתור (DRY_RUN)
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-xl bg-gray-100 px-3.5 py-2 text-xs font-bold text-gray-600">
                          בוטל והוסר
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Expanded Reminder Preview Box */}
                  {isExpanded && (
                    <div className="mt-4 rounded-xl border border-gray-200 bg-gray-50/70 p-4 text-xs">
                      <div className="mb-2 flex items-center justify-between border-b border-gray-200 pb-2">
                        <div className="flex items-center gap-2">
                          <Send className="h-3.5 w-3.5 text-gray-500" />
                          <span className="font-bold text-gray-700">נושא ההודעה המיועדת:</span>
                          <span className="font-medium text-gray-900">{draft.subject}</span>
                        </div>
                        <span className="text-[11px] text-gray-400">
                          סוג תזכורת: {draft.reminder_type}
                        </span>
                      </div>
                      <div className="whitespace-pre-wrap font-sans text-gray-700 leading-relaxed">
                        {draft.message_body}
                      </div>
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </div>
    </div>
  )
}
export default DebtApprovalQueue
