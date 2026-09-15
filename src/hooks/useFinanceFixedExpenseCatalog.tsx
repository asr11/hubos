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
import type { FinanceFixedExpenseCatalogItem } from '../types'



type FinanceFixedExpenseCatalogContextValue = {
  items: FinanceFixedExpenseCatalogItem[]
  loading: boolean
  updatedAt: string | null
  saveCatalog: (items: FinanceFixedExpenseCatalogItem[]) => Promise<boolean>
}

const FinanceFixedExpenseCatalogContext =
  createContext<FinanceFixedExpenseCatalogContextValue | null>(null)

export function FinanceFixedExpenseCatalogProvider({
  children,
}: {
  children: ReactNode
}) {
  const { user } = useAuth()
  const [items, setItems] = useState<FinanceFixedExpenseCatalogItem[]>([])
  const [updatedAt, setUpdatedAt] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!user) {
      setItems([])
      setUpdatedAt(null)
      setLoading(false)
      return
    }

    setLoading(true)
    let active = true
    hubFetch('/api/financeFixedExpenseCatalog')
      .then((res) => res.json())
      .then((data) => {
        if (!active) return
        if (data && Array.isArray(data.items)) {
          setItems(data.items)
          setUpdatedAt(data.updatedAt || null)
        } else {
          setItems([])
          setUpdatedAt(null)
        }
        setLoading(false)
      })
      .catch((err) => {
        if (!active) return
        console.error('financeFixedExpenseCatalog', err)
        setLoading(false)
      })

    return () => {
      active = false
    }
  }, [user])

  const saveCatalog = useCallback(
    async (nextItems: FinanceFixedExpenseCatalogItem[]): Promise<boolean> => {
      if (!user) return false
      try {
        const normalized = nextItems
          .map((item) => ({
            id: item.id,
            type: item.type.trim() || 'fixed',
            name: item.name.trim(),
            amount: Math.round(item.amount * 100) / 100,
            paymentMethod: item.paymentMethod,
            dayOfMonth:
              item.paymentMethod === 'credit_card'
                ? 1
                : Math.min(31, Math.max(1, item.dayOfMonth || 1)),
            amountIncludesVat: item.amountIncludesVat !== false,
          }))
          .filter((item) => item.name && item.amount > 0)

        const res = await hubFetch('/api/financeFixedExpenseCatalog/current', {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ items: normalized }),
        })
        if (!res.ok) throw new Error('API Error')
        toastFsSuccess('הוצאות קבועות נשמרו')
        return true
      } catch (err) {
        toastFsError('שגיאה בשמירת הוצאות קבועות', err)
        return false
      }
    },
    [user],
  )

  const value = useMemo(
    () => ({
      items,
      loading,
      updatedAt,
      saveCatalog,
    }),
    [items, loading, updatedAt, saveCatalog],
  )

  return (
    <FinanceFixedExpenseCatalogContext.Provider value={value}>
      {children}
    </FinanceFixedExpenseCatalogContext.Provider>
  )
}

export function useFinanceFixedExpenseCatalog(): FinanceFixedExpenseCatalogContextValue {
  const ctx = useContext(FinanceFixedExpenseCatalogContext)
  if (!ctx) {
    throw new Error(
      'useFinanceFixedExpenseCatalog חייב להיות בתוך FinanceFixedExpenseCatalogProvider',
    )
  }
  return ctx
}
