import { useMemo, useCallback } from 'react'
import { formatNis } from '../../lib/financeCalc'
import { incomeOutstandingAmount } from '../../lib/financeIncomePayment'
import { sortByKey, useTableSort } from '../../lib/financeTableSort'
import type { FinanceIncome } from '../../types'
import { FinanceClickable } from './FinanceClickable'
import { SortableTh } from './SortableTh'
import { todayYmd, formatDateIL } from '../../lib/dates'
import { Phone } from 'lucide-react'

type ClientDebtProfile = {
  clientId: string
  clientName: string
  totalDebt: number
  maxDaysOverdue: number
  invoices: FinanceIncome[]
}

type ClientDebtSortKey = 'client' | 'totalDebt' | 'maxDays' | 'invoiceCount'

function daysOpen(invoiceDate: string, today = todayYmd()): number {
  const due = invoiceDate.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(due)) return 0
  const ms = new Date(today.slice(0, 10)).getTime() - new Date(due).getTime()
  return Math.max(0, Math.floor(ms / 86400000))
}

export function ClientDebtBoard({
  rows,
  clientNameById,
  onClientClick,
}: {
  rows: FinanceIncome[]
  clientNameById: Map<string, string>
  onClientClick?: (target: { clientId?: string; clientName: string; guestName?: string }) => void
}) {
  const { sort, toggle } = useTableSort<ClientDebtSortKey>('totalDebt', 'desc')

  // Group by client
  const clientProfiles = useMemo(() => {
    const profiles = new Map<string, ClientDebtProfile>()

    rows.forEach((row) => {
      // Only consider open/unpaid invoices (transaction_account / 300)
      if (row.documentKind && row.documentKind !== 'transaction_account' && row.documentKind !== 'transaction_invoice') {
        return
      }
      
      const outstanding = incomeOutstandingAmount(row)
      if (outstanding <= 0) return

      const cId = row.clientId?.trim() || ''
      const cName = clientNameById.get(cId) ?? row.greenInvoiceClientName ?? 'לקוח לא ידוע'
      
      // Use clientName as fallback key if no ID
      const mapKey = cId || cName

      if (!profiles.has(mapKey)) {
        profiles.set(mapKey, {
          clientId: cId,
          clientName: cName,
          totalDebt: 0,
          maxDaysOverdue: 0,
          invoices: [],
        })
      }

      const profile = profiles.get(mapKey)!
      profile.totalDebt += outstanding
      profile.invoices.push(row)
      
      const currentOpenDays = daysOpen(row.invoiceDate)
      if (currentOpenDays > profile.maxDaysOverdue) {
        profile.maxDaysOverdue = currentOpenDays
      }
    })

    return Array.from(profiles.values())
  }, [rows, clientNameById])

  const getSortValue = useCallback(
    (profile: ClientDebtProfile, key: ClientDebtSortKey): string | number => {
      if (key === 'client') return profile.clientName
      if (key === 'totalDebt') return profile.totalDebt
      if (key === 'maxDays') return profile.maxDaysOverdue
      if (key === 'invoiceCount') return profile.invoices.length
      return 0
    },
    []
  )

  const sortedProfiles = useMemo(
    () => sortByKey(clientProfiles, sort, getSortValue),
    [clientProfiles, sort, getSortValue]
  )

  if (sortedProfiles.length === 0) {
    return (
      <p className="py-8 text-center text-sm font-medium text-[var(--text-mid)]">
        אין חובות פתוחים (או שלא נמצאו חשבונות עסקה עם יתרה) בטווח הנבחר
      </p>
    )
  }

  const totals = sortedProfiles.reduce(
    (acc, p) => {
      acc.debt += p.totalDebt
      return acc
    },
    { debt: 0 }
  )

  return (
    <div className="overflow-x-auto rounded-2xl border border-[var(--border)] shadow-sm">
      <table className="w-full min-w-[800px] border-collapse text-sm">
        <thead className="sticky top-0 z-[1]">
          <tr className="border-b border-[var(--border)] bg-[var(--surface-muted)]/90 text-[11px] font-extrabold text-[var(--text-mid)] backdrop-blur-sm">
            <SortableTh
              label="לקוח"
              columnKey="client"
              sort={sort}
              onSort={toggle}
              defaultDir="asc"
            />
            <SortableTh
              label="סה״כ חוב פתוח"
              columnKey="totalDebt"
              sort={sort}
              onSort={toggle}
            />
            <SortableTh
              label="איחור מקסימלי"
              columnKey="maxDays"
              sort={sort}
              onSort={toggle}
            />
            <SortableTh
              label="כמות עסקאות"
              columnKey="invoiceCount"
              sort={sort}
              onSort={toggle}
            />
            <th className="px-3 py-3 text-center">פעולת גבייה מהירה (WhatsApp)</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-[var(--border)]/70">
          {sortedProfiles.map((profile) => {
            const isCritical = profile.maxDaysOverdue > 30
            const isWarning = profile.maxDaysOverdue > 14

            // Generate WhatsApp message
            const sortedInvoices = [...profile.invoices].sort((a, b) => a.invoiceDate.localeCompare(b.invoiceDate))
            const invoiceListText = sortedInvoices.map(inv => {
              const num = inv.invoiceNumber || inv.greenInvoiceNumber || 'ללא מספר'
              const date = formatDateIL(inv.invoiceDate)
              const amount = formatNis(incomeOutstandingAmount(inv))
              return `- חשבון עסקה #${num} מתאריך ${date} על סך ${amount}`
            }).join('\n')

            const safeClientName = profile.clientName.replace(/ בע"מ| בע״מ/g, '')
            const whatsappText = `היי ${safeClientName},
תזכורת ידידותית מגזרת הנהלת החשבונות 🌸

סה״כ לתשלום: *${formatNis(profile.totalDebt)}*

פירוט חשבונות עסקה פתוחים:
${invoiceListText}

נודה להסדרת התשלום בהקדם,
תודה רבה ויום מקסים! 🙏`

            return (
              <tr
                key={profile.clientId || profile.clientName}
                className={`transition ${
                  isCritical
                    ? 'bg-[#FEF2F2] hover:bg-[#FEE2E2]'
                    : isWarning
                    ? 'bg-[#FFFBEB] hover:bg-[#FEF3C7]'
                    : 'hover:bg-[var(--surface-muted)]/50'
                }`}
              >
                <td className="px-3 py-4">
                  <div className="flex items-center gap-2">
                    <FinanceClickable
                      title="כרטיס פיננסי ללקוח"
                      onClick={
                        onClientClick
                          ? () =>
                              onClientClick({
                                clientId: profile.clientId || undefined,
                                clientName: profile.clientName,
                                guestName: !profile.clientId ? profile.clientName : undefined,
                              })
                          : undefined
                      }
                    >
                      <span className="text-[13px] font-extrabold">{profile.clientName}</span>
                    </FinanceClickable>
                  </div>
                </td>
                <td className="px-3 py-4 tabular-nums">
                  <span className={`text-[15px] font-black ${isCritical ? 'text-red-700' : 'text-[#D97706]'}`}>
                    {formatNis(profile.totalDebt)}
                  </span>
                </td>
                <td className="px-3 py-4 tabular-nums">
                  <span
                    className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-bold ${
                      isCritical
                        ? 'bg-red-100 text-red-800'
                        : isWarning
                        ? 'bg-amber-100 text-amber-800'
                        : 'bg-emerald-100 text-emerald-800'
                    }`}
                  >
                    {profile.maxDaysOverdue} ימים
                  </span>
                </td>
                <td className="px-3 py-4 tabular-nums">
                  <span className="text-[13px] font-semibold text-[var(--text-mid)]">
                    {profile.invoices.length} {profile.invoices.length === 1 ? 'חשבון עסקה' : 'חשבונות עסקה'}
                  </span>
                </td>
                <td className="px-3 py-4 text-center">
                  <a
                    href={`https://wa.me/?text=${encodeURIComponent(whatsappText)}`}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#25D366] px-4 py-2 text-[12px] font-bold text-white shadow-sm transition hover:scale-105 active:scale-95"
                    title="שליחת הודעת גבייה מרוכזת בוואטסאפ"
                  >
                    <Phone className="h-3.5 w-3.5" />
                    גבה חוב ({formatNis(profile.totalDebt)})
                  </a>
                </td>
              </tr>
            )
          })}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-[var(--border)] bg-[var(--dash-panel-bg)] text-[14px] font-black text-[var(--text-dark)] shadow-[0_-4px_10px_rgba(0,0,0,0.02)]">
            <td className="px-3 py-4 text-left">
              סה״כ חוב פתוח ({sortedProfiles.length} לקוחות)
            </td>
            <td className="px-3 py-4 tabular-nums text-[#D97706] text-[16px]">
              {formatNis(totals.debt)}
            </td>
            <td className="px-3 py-4" colSpan={3} />
          </tr>
        </tfoot>
      </table>
    </div>
  )
}
