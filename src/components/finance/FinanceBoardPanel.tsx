import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useInvoices } from '../../hooks/useInvoices'
import { useClients } from '../../hooks/useClients'
import { useCollectionTracking } from '../../hooks/useCollectionTracking'
import { useMorningIntegration } from '../../hooks/useMorningIntegration'
import { useFinanceIncomes } from '../../hooks/useFinanceIncomes'
import { formatNis } from '../../lib/financeCalc'
import { formatDateTimeIL } from '../../lib/dates'

export function FinanceBoardPanel() {
  const navigate = useNavigate()
  const { invoices } = useInvoices()
  const { clients } = useClients()
  const { entries: collections } = useCollectionTracking()
  const { status, loading: morningLoading } = useMorningIntegration()

  const pendingInvoices = useMemo(
    () => invoices.filter((i) => i.status === 'pending' || i.status === 'overdue'),
    [invoices]
  )
  const totalMoneyOnFloor = pendingInvoices.reduce((acc, inv) => acc + inv.amount, 0)
  const overdueInvoices = pendingInvoices.filter((i) => i.status === 'overdue')
  const totalOverdue = overdueInvoices.reduce((acc, inv) => acc + inv.amount, 0)

  const { incomes } = useFinanceIncomes()

  const clientsWithDebt = useMemo(() => {
    const debtMap = new Map<string, number>()
    for (const inc of incomes) {
      if (inc.status === 'pending' || inc.status === 'overdue' || inc.status === 'partial') {
        const amt = inc.amount - (inc.paidAmount ?? 0)
        if (amt > 0) {
          debtMap.set(inc.clientId, (debtMap.get(inc.clientId) ?? 0) + amt)
        }
      }
    }
    const result = []
    for (const [clientId, balance] of debtMap.entries()) {
      const client = clients.find(c => c.id === clientId)
      if (client) {
        result.push({ ...client, computedBalance: balance })
      }
    }
    return result
  }, [clients, incomes])

  const openCollections = useMemo(() => {
    return collections.filter((c) => !c.paidDate)
  }, [collections])

  const totalClientDebt = clientsWithDebt.reduce((acc, c) => acc + c.computedBalance, 0)

  // Top clients with debt for quick access
  const topDebtors = useMemo(() => {
    return [...clientsWithDebt].sort((a, b) => b.computedBalance - a.computedBalance).slice(0, 3)
  }, [clientsWithDebt])

  return (
    <section className="mb-6 rounded-2xl bg-white p-6 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] ring-1 ring-slate-200" dir="rtl">
      <div className="mb-5 flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-extrabold text-slate-900 tracking-tight">הלוח הפיננסי המרכזי</h2>
          <p className="mt-1 text-sm text-slate-500">תמונת מצב עדכנית: כסף על הרצפה, חובות פתוחים וגביה</p>
        </div>
        <div className="flex shrink-0 gap-3">
          <button
            onClick={() => navigate('/finance')}
            className="rounded-lg bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100"
          >
            סקירה פיננסית מלאה
          </button>
          <button
            onClick={() => navigate('/collections')}
            className="rounded-lg bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100"
          >
            פאנל גבייה
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
        {/* Money on the floor */}
        <button 
          onClick={() => navigate('/collections')}
          className="flex w-full text-right flex-col justify-between rounded-xl border border-slate-100 bg-slate-50 p-5 transition hover:border-indigo-100 hover:shadow-md cursor-pointer active:scale-[0.98]"
        >
          <div>
            <h3 className="text-sm font-semibold text-slate-600">כסף על הרצפה (חשבוניות פתוחות)</h3>
            <p className="mt-2 text-3xl font-bold tracking-tight text-indigo-600 tabular-nums">
              {formatNis(totalMoneyOnFloor)}
            </p>
          </div>
          <div className="mt-4 flex flex-col gap-1">
            <span className="text-xs font-medium text-slate-500">
              <span className="font-bold text-slate-700">{pendingInvoices.length}</span> חשבוניות ממתינות
            </span>
            {totalOverdue > 0 && (
              <span className="text-xs font-semibold text-rose-600">
                מתוכן {formatNis(totalOverdue)} באיחור
              </span>
            )}
          </div>
        </button>

        {/* Client Debt */}
        <div className="flex flex-col justify-between rounded-xl border border-slate-100 bg-slate-50 p-5 transition hover:border-indigo-100 hover:shadow-sm">
          <div>
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-slate-600">חובות לקוחות</h3>
              <span className="inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-700">
                יתרות
              </span>
            </div>
            <p className="mt-2 text-3xl font-bold tracking-tight text-slate-800 tabular-nums">
              {formatNis(totalClientDebt)}
            </p>
          </div>
          <div className="mt-4 flex flex-col gap-2">
            <span className="text-xs font-medium text-slate-500">
              <span className="font-bold text-slate-700">{clientsWithDebt.length}</span> לקוחות עם יתרת חובה
            </span>
            {topDebtors.length > 0 && (
              <div className="mt-1 flex flex-col gap-1 border-t border-slate-200 pt-2">
                <span className="text-[10px] font-semibold uppercase text-slate-400">חייבים מרכזיים:</span>
                {topDebtors.map(d => (
                  <div key={d.id} className="flex justify-between text-xs">
                    <span className="truncate pr-1 font-medium text-slate-700">{d.name}</span>
                    <span className="font-bold tabular-nums text-rose-600">{formatNis(d.computedBalance)}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Morning & Collections */}
        <div className="flex flex-col justify-between rounded-xl border border-slate-100 bg-slate-50 p-5 transition hover:border-indigo-100 hover:shadow-sm">
          <div>
            <h3 className="text-sm font-semibold text-slate-600">סטטוס גבייה וסנכרון</h3>
            <div className="mt-3 flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div className="flex flex-col">
                <span className="text-xs font-medium text-slate-500">חיבור Morning</span>
                {morningLoading ? (
                  <span className="text-sm font-bold text-slate-400">טוען...</span>
                ) : status?.connected ? (
                  <span className="text-sm font-bold text-emerald-600">מחובר ומסונכרן</span>
                ) : (
                  <span className="text-sm font-bold text-rose-500">לא מחובר</span>
                )}
              </div>
            </div>
            {status?.syncState?.lastSyncAt && (
              <p className="mt-1 text-[10px] text-slate-400">
                סנכרון אחרון: {formatDateTimeIL(status.syncState.lastSyncAt)}
              </p>
            )}
          </div>
          <div className="mt-4 border-t border-slate-200 pt-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-500">מעקבי גבייה פתוחים</span>
              <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">
                {openCollections.length}
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
