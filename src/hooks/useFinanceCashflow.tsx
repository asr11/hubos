import { hubFetch } from '../lib/hubFetch'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useAuth } from '../contexts/AuthContext'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type {
  FinanceCashflowBalanceSnapshot,
  FinanceCashflowExpectedExpense,
  FinanceCashflowState,
  FinanceCashflowVatEstimate,
  FinanceGiRecurringPayment,
  FinanceGiRetainer,
  FinancePaymentMethod,
} from '../types'



export type VatEstimatePatchInput = {
  amount?: number
  notes?: string | null
}

export type NewExpectedExpenseInput = {
  name: string
  amount: number
  expectedDate: string
  paymentMethod: FinancePaymentMethod
  notes?: string
}

export type ExpectedExpensePatchInput = {
  name?: string
  amount?: number
  expectedDate?: string
  paymentMethod?: FinancePaymentMethod
  notes?: string | null
}

type FinanceCashflowContextValue = {
  state: FinanceCashflowState | null
  snapshots: FinanceCashflowBalanceSnapshot[]
  giRecurring: FinanceGiRecurringPayment[]
  giRetainers: FinanceGiRetainer[]
  expectedExpenses: FinanceCashflowExpectedExpense[]
  vatEstimates: FinanceCashflowVatEstimate[]
  loading: boolean
  error: Error | null
  saveBalance: (balance: number, asOfDate: string) => Promise<void>
  saveSnapshot: (balance: number, asOfDate: string) => Promise<void>
  addExpectedExpense: (data: NewExpectedExpenseInput) => Promise<boolean>
  updateExpectedExpense: (
    id: string,
    patch: ExpectedExpensePatchInput,
  ) => Promise<void>
  deleteExpectedExpense: (id: string) => Promise<void>
  updateVatEstimate: (vatYm: string, patch: VatEstimatePatchInput) => Promise<void>
}

const FinanceCashflowContext =
  createContext<FinanceCashflowContextValue | null>(null)

export function FinanceCashflowProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [state, setState] = useState<FinanceCashflowState | null>(null)
  const [snapshots, setSnapshots] = useState<FinanceCashflowBalanceSnapshot[]>([])
  const [giRecurring, setGiRecurring] = useState<FinanceGiRecurringPayment[]>([])
  const [giRetainers, setGiRetainers] = useState<FinanceGiRetainer[]>([])
  const [expectedExpenses, setExpectedExpenses] = useState<
    FinanceCashflowExpectedExpense[]
  >([])
  const [vatEstimates, setVatEstimates] = useState<FinanceCashflowVatEstimate[]>(
    [],
  )
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  useEffect(() => {
    if (!user) {
      setState(null)
      setSnapshots([])
      setGiRecurring([])
      setGiRetainers([])
      setExpectedExpenses([])
      setVatEstimates([])
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    let isMounted = true
    Promise.all([
      hubFetch('/api/financeCashflowState').then(r => r.json()).catch(() => null),
      hubFetch('/api/financeCashflowBalanceSnapshots').then(r => r.json()).catch(() => []),
      hubFetch('/api/financeGiRecurringPayments').then(r => r.json()).catch(() => []),
      hubFetch('/api/financeGiRetainers').then(r => r.json()).catch(() => []),
      hubFetch('/api/financeCashflowExpectedExpenses').then(r => r.json()).catch(() => []),
      hubFetch('/api/financeCashflowVatEstimates').then(r => r.json()).catch(() => [])
    ]).then(([st, sn, rec, ret, exp, vat]) => {
      if (!isMounted) return
      setState(st)
      setSnapshots(Array.isArray(sn) ? sn : [])
      setGiRecurring(Array.isArray(rec) ? rec : [])
      setGiRetainers(Array.isArray(ret) ? ret : [])
      setExpectedExpenses(Array.isArray(exp) ? exp : [])
      setVatEstimates(Array.isArray(vat) ? vat : [])
      setLoading(false)
    }).catch(err => {
      if (!isMounted) return
      console.error('cashflow fetch', err)
      setError(err instanceof Error ? err : new Error(String(err)))
      setLoading(false)
    })
    return () => { isMounted = false }
  }, [user])

  const updateVatEstimate = useCallback(
    async (vatYm: string, patch: VatEstimatePatchInput) => {
      if (!user) return
      const ym = vatYm.slice(0, 7)
      if (typeof patch.amount !== 'number' || !Number.isFinite(patch.amount)) return
      const rounded = Math.round(patch.amount * 100) / 100

      setVatEstimates((prev) => {
        const idx = prev.findIndex((row) => row.vatYm === ym)
        const nextRow: FinanceCashflowVatEstimate = {
          id: ym,
          vatYm: ym,
          amount: rounded,
          notes: idx >= 0 ? prev[idx]?.notes : undefined,
          updatedAt: new Date().toISOString(),
          updatedBy: user.uid,
        }
        if (idx >= 0) {
          const next = [...prev]
          next[idx] = { ...prev[idx]!, ...nextRow }
          return next
        }
        return [...prev, nextRow]
      })

      try {
        const next: Record<string, unknown> = {
          vatYm: ym,
          amount: rounded,
          updatedBy: user.uid,
        }
        if (patch.notes === null || patch.notes === '') {
          next.notes = null
        } else if (typeof patch.notes === 'string') {
          next.notes = patch.notes.trim()
        }
        const res = await hubFetch('/api/financeCashflowVatEstimates/' + ym, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(next) })
        if (!res.ok) throw new Error('Failed')
        toastFsSuccess('אומדן מע״מ עודכן')
      } catch (err) {
        toastFsError('שגיאה בעדכון אומדן מע״מ', err)
        hubFetch('/api/financeCashflowVatEstimates').then(r => r.json()).then(setVatEstimates).catch(() => {})
        throw err
      }
    },
    [user],
  )

  const saveBalance = useCallback(
    async (balance: number, asOfDate: string) => {
      if (!user) return
      try {
        const res = await hubFetch('/api/financeCashflowState/current', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            balance: Math.round(balance * 100) / 100,
            asOfDate: asOfDate.slice(0, 10),
            updatedBy: user.uid,
          }) })
        if (!res.ok) throw new Error('Failed')
        setState(prev => prev ? { ...prev, balance: Math.round(balance * 100) / 100, asOfDate: asOfDate.slice(0, 10) } : null)
        toastFsSuccess('יתרה עודכנה')
      } catch (err) {
        toastFsError('שגיאה בעדכון יתרה', err)
        throw err
      }
    },
    [user],
  )

  const saveSnapshot = useCallback(
    async (balance: number, asOfDate: string) => {
      if (!user) return
      const ymd = asOfDate.slice(0, 10)
      try {
        const snapPayload = {
          balance: Math.round(balance * 100) / 100,
          asOfDate: ymd,
          createdBy: user.uid,
        }
        const resSnap = await hubFetch('/api/financeCashflowBalanceSnapshots', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(snapPayload) })
        if (!resSnap.ok) throw new Error('Failed')
        const createdSnap = await resSnap.json()
        setSnapshots(prev => [createdSnap, ...prev])
        await saveBalance(balance, ymd)
        toastFsSuccess('Snapshot נשמר')
      } catch (err) {
        toastFsError('שגיאה בשמירת snapshot', err)
        throw err
      }
    },
    [user, saveBalance],
  )

  const addExpectedExpense = useCallback(
    async (data: NewExpectedExpenseInput): Promise<boolean> => {
      if (!user) return false
      try {
        const expPayload = {
          name: data.name.trim(),
          amount: Math.round(data.amount * 100) / 100,
          expectedDate: data.expectedDate.slice(0, 10),
          paymentMethod: data.paymentMethod,
          ...(data.notes?.trim() ? { notes: data.notes.trim() } : {}),
          createdBy: user.uid,
        }
        const res = await hubFetch('/api/financeCashflowExpectedExpenses', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(expPayload) })
        if (!res.ok) throw new Error('Failed')
        const created = await res.json()
        setExpectedExpenses(prev => [...prev, created])
        toastFsSuccess('הוצאה צפויה נוספה')
        return true
      } catch (err) {
        toastFsError('שגיאה בהוספת הוצאה צפויה', err)
        return false
      }
    },
    [user],
  )

  const updateExpectedExpense = useCallback(
    async (id: string, patch: ExpectedExpensePatchInput) => {
      if (!user) return
      try {
        const next: Record<string, unknown> = {}
        if (typeof patch.name === 'string') next.name = patch.name.trim()
        if (typeof patch.amount === 'number' && Number.isFinite(patch.amount)) {
          next.amount = Math.round(patch.amount * 100) / 100
        }
        if (typeof patch.expectedDate === 'string') {
          next.expectedDate = patch.expectedDate.slice(0, 10)
        }
        if (
          patch.paymentMethod === 'bank_transfer' ||
          patch.paymentMethod === 'credit_card'
        ) {
          next.paymentMethod = patch.paymentMethod
        }
        if (patch.notes === null || patch.notes === '') {
          next.notes = null
        } else if (typeof patch.notes === 'string') {
          next.notes = patch.notes.trim()
        }
        if (Object.keys(next).length === 0) return
        const res = await hubFetch('/api/financeCashflowExpectedExpenses/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(next) })
        if (!res.ok) throw new Error('Failed')
        setExpectedExpenses(prev => prev.map(e => e.id === id ? { ...e, ...next } : e))
        toastFsSuccess('הוצאה צפויה עודכנה')
      } catch (err) {
        toastFsError('שגיאה בעדכון הוצאה צפויה', err)
      }
    },
    [user],
  )

  const deleteExpectedExpense = useCallback(async (id: string) => {
    if (!user) return
    try {
      const res = await hubFetch('/api/financeCashflowExpectedExpenses/' + id, { method: 'DELETE' })
      if (!res.ok) throw new Error('Failed')
      setExpectedExpenses(prev => prev.filter(e => e.id !== id))
      toastFsSuccess('הוצאה צפויה נמחקה')
    } catch (err) {
      toastFsError('שגיאה במחיקת הוצאה צפויה', err)
    }
  }, [user])

  const value = useMemo(
    () => ({
      state,
      snapshots,
      giRecurring,
      giRetainers,
      expectedExpenses,
      vatEstimates,
      loading,
      error,
      saveBalance,
      saveSnapshot,
      addExpectedExpense,
      updateExpectedExpense,
      deleteExpectedExpense,
      updateVatEstimate,
    }),
    [
      state,
      snapshots,
      giRecurring,
      giRetainers,
      expectedExpenses,
      vatEstimates,
      loading,
      error,
      saveBalance,
      saveSnapshot,
      addExpectedExpense,
      updateExpectedExpense,
      deleteExpectedExpense,
      updateVatEstimate,
    ],
  )

  return (
    <FinanceCashflowContext.Provider value={value}>
      {children}
    </FinanceCashflowContext.Provider>
  )
}

export function useFinanceCashflow(): FinanceCashflowContextValue {
  const ctx = useContext(FinanceCashflowContext)
  if (!ctx) {
    throw new Error('useFinanceCashflow חייב להיות בתוך FinanceCashflowProvider')
  }
  return ctx
}
