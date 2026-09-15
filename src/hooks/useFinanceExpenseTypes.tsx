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
import {
  defaultPnlForFieldMode,
  mergeExpenseTypes,
} from '../lib/financeExpenseTypes'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type {
  FinanceExpenseFieldMode,
  FinanceExpensePnlCategory,
  FinanceExpenseTypeDef,
} from '../types'

export type NewFinanceExpenseTypeInput = {
  label: string
  color: string
  fieldMode: FinanceExpenseFieldMode
  autoRecurring: boolean
  pnlCategory: FinanceExpensePnlCategory
}

type FinanceExpenseTypesContextValue = {
  types: FinanceExpenseTypeDef[]
  loading: boolean
  addExpenseType: (data: NewFinanceExpenseTypeInput) => Promise<string | null>
  deleteExpenseType: (id: string) => Promise<void>
}

const FinanceExpenseTypesContext =
  createContext<FinanceExpenseTypesContextValue | null>(null)

export function FinanceExpenseTypesProvider({
  children,
}: {
  children: ReactNode
}) {
  const { user } = useAuth()
  const [customTypes, setCustomTypes] = useState<FinanceExpenseTypeDef[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!user) {
      setCustomTypes([])
      setLoading(false)
      return
    }
    setLoading(true)
    let isMounted = true
    hubFetch('/api/financeExpenseTypes')
      .then(r => r.json())
      .then(data => {
        if (!isMounted) return
        setCustomTypes(Array.isArray(data) ? data : [])
        setLoading(false)
      })
      .catch(err => {
        if (!isMounted) return
        toastFsError('שגיאה בטעינת סוגי הוצאות', err, 'financeExpenseTypes')
        setLoading(false)
      })
    return () => { isMounted = false }
  }, [user])

  const types = useMemo(() => mergeExpenseTypes(customTypes), [customTypes])

  const addExpenseType = useCallback(async (data: NewFinanceExpenseTypeInput) => {
    try {
      const label = data.label.trim()
      if (!label) return null
      const payload = {
        label,
        color: data.color,
        fieldMode: data.fieldMode,
        autoRecurring: data.autoRecurring,
        pnlCategory: data.pnlCategory,
      }
      const res = await hubFetch('/api/financeExpenseTypes', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      if (!res.ok) throw new Error('Failed')
      const created = await res.json()
      setCustomTypes(prev => [...prev, created])
      const ref = { id: created.id }
      toastFsSuccess('סוג ההוצאה נוסף')
      return ref.id
    } catch (e) {
      toastFsError('שגיאה בהוספת סוג הוצאה', e, 'addExpenseType')
      return null
    }
  }, [])

  const deleteExpenseType = useCallback(async (id: string) => {
    try {
      const res = await hubFetch('/api/financeExpenseTypes/' + id, { method: 'DELETE' })
      if (!res.ok) throw new Error('Failed')
      setCustomTypes(prev => prev.filter(t => t.id !== id))
      toastFsSuccess('סוג ההוצאה נמחק')
    } catch (e) {
      toastFsError('שגיאה במחיקת סוג הוצאה', e, 'deleteExpenseType')
    }
  }, [])

  const value = useMemo(
    () => ({
      types,
      loading,
      addExpenseType,
      deleteExpenseType,
    }),
    [types, loading, addExpenseType, deleteExpenseType],
  )

  return (
    <FinanceExpenseTypesContext.Provider value={value}>
      {children}
    </FinanceExpenseTypesContext.Provider>
  )
}

export function useFinanceExpenseTypes(): FinanceExpenseTypesContextValue {
  const ctx = useContext(FinanceExpenseTypesContext)
  if (!ctx) {
    throw new Error(
      'useFinanceExpenseTypes חייב להיות בתוך FinanceExpenseTypesProvider',
    )
  }
  return ctx
}

export function newExpenseTypeDefaults(
  fieldMode: FinanceExpenseFieldMode = 'none',
): NewFinanceExpenseTypeInput {
  return {
    label: '',
    color: '#F97316',
    fieldMode,
    autoRecurring: false,
    pnlCategory: defaultPnlForFieldMode(fieldMode),
  }
}
