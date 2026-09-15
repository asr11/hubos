import { db } from './db.js'
import { serializeDoc } from './hubApiHttpUtil.js'

/**
 * גישת קריאה בלבד של הסוכן לעולם הפיננסי.
 *
 * הרישום כולו נעשה במערכת בידי בעל העסק. לסוכן אין ולא תהיה דרך לכתוב:
 * זו ההחלטה שמונעת שני מקורות שיוצרים את אותה רשומה, ואיתה את ההתאמות
 * והכפילויות שנבעו מכך.
 */

const EXPENSES = 'financeExpenses'
const INCOMES = 'financeIncomes'

export class FinanceAgentDbNotReadyError extends Error {}

export type MonthFilter = {
  /** YYYY-MM — חודש בודד */
  month?: string
  /** YYYY-MM — תחילת טווח (כולל) */
  from?: string
  /** YYYY-MM — סוף טווח (כולל) */
  to?: string
  limit: number
}

function withinRange(ym: string, filter: MonthFilter): boolean {
  if (filter.month) return ym === filter.month
  if (filter.from && ym < filter.from) return false
  if (filter.to && ym > filter.to) return false
  return true
}

function loadCollectionDocs(collection: string): { id: string; data: Record<string, unknown> }[] {
  const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
  let rows = stmt.all(collection) as { id: string; data: string }[]
  if (rows.length === 0 && collection === INCOMES) {
    rows = stmt.all('invoices') as { id: string; data: string }[]
  }
  return rows.map((r) => {
    try {
      return { id: r.id, data: JSON.parse(r.data) as Record<string, unknown> }
    } catch {
      return { id: r.id, data: {} }
    }
  })
}

async function listByMonth(
  collection: string,
  monthOf: (d: Record<string, unknown>) => string,
  filter: MonthFilter,
): Promise<Record<string, unknown>[]> {
  const docs = loadCollectionDocs(collection)
  const rows: Record<string, unknown>[] = []
  for (const doc of docs) {
    if (!withinRange(monthOf(doc.data), filter)) continue
    rows.push(serializeDoc(doc.id, doc.data))
    if (rows.length >= filter.limit) break
  }
  return rows
}

function expenseMonth(d: Record<string, unknown>): string {
  return String(d.pnlMonth ?? d.billingMonth ?? '').slice(0, 7)
}

function incomeMonth(d: Record<string, unknown>): string {
  return String(d.pnlMonth ?? d.billingMonth ?? d.documentDate ?? d.invoiceDate ?? '').slice(0, 7)
}

export async function listExpenses(filter: MonthFilter): Promise<Record<string, unknown>[]> {
  return listByMonth(EXPENSES, expenseMonth, filter)
}

export async function listIncomes(filter: MonthFilter): Promise<Record<string, unknown>[]> {
  return listByMonth(INCOMES, incomeMonth, filter)
}

export type MonthInventory = {
  month: string
  expenseCount: number
  expenseTotal: number
  incomeCount: number
  incomeTotal: number
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

/**
 * מצאי גולמי לכל חודש — כמה רשומות יש וכמה הן מסתכמות.
 *
 * זו במכוון אינה תמונת רווח והפסד: הרווח מחושב בכללי הייחוס, המע״מ
 * והפריסה של המערכת, ולכן הוא נמסר מנקודה אחת בלבד. מספר מקביל שהיה
 * מחושב כאן היה יכול לסטות מהדשבורד, וזה בדיוק מה שאסור שיקרה.
 */
export async function monthInventory(): Promise<MonthInventory[]> {
  const expenseDocs = loadCollectionDocs(EXPENSES)
  const incomeDocs = loadCollectionDocs(INCOMES)

  const months = new Map<string, MonthInventory>()
  const slot = (ym: string): MonthInventory => {
    const found = months.get(ym)
    if (found) return found
    const created: MonthInventory = {
      month: ym,
      expenseCount: 0,
      expenseTotal: 0,
      incomeCount: 0,
      incomeTotal: 0,
    }
    months.set(ym, created)
    return created
  }

  for (const doc of expenseDocs) {
    const d = doc.data
    const s = slot(expenseMonth(d) || 'unknown')
    s.expenseCount += 1
    s.expenseTotal = round2(s.expenseTotal + Number(d.amount ?? 0))
  }
  for (const doc of incomeDocs) {
    const d = doc.data
    const s = slot(incomeMonth(d) || 'unknown')
    s.incomeCount += 1
    s.incomeTotal = round2(s.incomeTotal + Number(d.amount ?? 0))
  }

  return [...months.values()].sort((a, b) => a.month.localeCompare(b.month))
}
