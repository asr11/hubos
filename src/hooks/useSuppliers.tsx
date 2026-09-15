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
import { ensureHubSupplierExists } from '../lib/hubSupplier'
import { normalizeSupplierTypesForSave } from '../lib/supplierTypes'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type { Supplier } from '../types'

type SuppliersContextValue = {
  suppliers: Supplier[]
  loading: boolean
  error: Error | null
  addSupplier: (data: Omit<Supplier, 'id' | 'createdAt'>) => Promise<string | null>
  updateSupplier: (id: string, data: Partial<Supplier>) => Promise<void>
  deleteSupplier: (id: string) => Promise<void>
}

const SuppliersContext = createContext<SuppliersContextValue | null>(null)

export function SuppliersProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [suppliers, setSuppliers] = useState<Supplier[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  const displayName = useCallback(() => {
    if (!user) return 'משתמש'
    return user.displayName?.trim() || user.email?.split('@')[0] || 'משתמש'
  }, [user])

  useEffect(() => {
    if (!user) {
      setSuppliers([])
      setLoading(false)
      setError(null)
      return
    }
    void ensureHubSupplierExists().catch((e) => {
      console.error('ensureHubSupplierExists', e)
    })
    setLoading(true)
    const fetchSuppliers = async () => {
      try {
        const res = await hubFetch('/api/suppliers')
        if (!res.ok) throw new Error('Failed to fetch')
        const data = await res.json()
        setSuppliers(Array.isArray(data) ? data : [])
        setLoading(false)
        setError(null)
      } catch (err) {
        console.error('suppliers fetch', err)
        toastFsError('שגיאה בטעינת ספקים', err, 'suppliers fetch')
        setError(err instanceof Error ? err : new Error(String(err)))
        setLoading(false)
      }
    }
    fetchSuppliers()
  }, [user])

  const addSupplier = useCallback(
    async (data: Omit<Supplier, 'id' | 'createdAt'>) => {
      if (!user) {
        toastFsError('יש להתחבר להוספת ספק', new Error('no user'), 'addSupplier')
        return null
      }
      try {
        const types = normalizeSupplierTypesForSave(data.supplierTypes)
        const uname = displayName()
        const res = await hubFetch('/api/suppliers', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: data.name,
            phone: data.phone,
            email: data.email,
            ...(types.length ? { supplierTypes: types } : {}),
            active: data.active !== false,
            createdByUserId: user.uid,
            createdByUserName: uname,
            lastActorUserId: user.uid,
            lastActorUserName: uname,
          })
        })
        if (!res.ok) throw new Error('Failed to add')
        const created = await res.json()
        const refId = created.id || 'temp-id'
        const optimistic: Supplier = {
          id: refId,
          name: data.name,
          phone: data.phone,
          email: data.email,
          ...(types.length ? { supplierTypes: types } : {}),
          ...(data.active === false ? { active: false } : {}),
          createdAt: new Date().toISOString(),
        }
        setSuppliers((prev) => {
          if (prev.some((s) => s.id === optimistic.id)) return prev
          return [...prev, optimistic]
        })
        toastFsSuccess('הספק נוסף')
        return refId
      } catch (e) {
        toastFsError('שגיאה בשמירת ספק', e, 'addSupplier')
        return null
      }
    },
    [user, displayName],
  )

  const updateSupplier = useCallback(
    async (id: string, data: Partial<Supplier>) => {
      if (!user) {
        toastFsError('יש להתחבר לעדכון ספק', new Error('no user'), 'updateSupplier')
        return
      }
      try {
        const patch: Record<string, unknown> = {}
        if (data.name !== undefined) patch.name = data.name
        if (data.phone !== undefined) patch.phone = data.phone
        if (data.email !== undefined) patch.email = data.email
        if (data.supplierTypes !== undefined) {
          const types = normalizeSupplierTypesForSave(data.supplierTypes)
          patch.supplierTypes = types.length ? types : null
          patch.supplierType = null
        }
        if (data.active !== undefined) patch.active = data.active
        const uname = displayName()
        patch.lastActorUserId = user.uid
        patch.lastActorUserName = uname
        await hubFetch('/api/suppliers/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(patch) })
        setSuppliers((prev) =>
          prev.map((s) => {
            if (s.id !== id) return s
            const next: Supplier = { ...s }
            if (data.name !== undefined) next.name = data.name
            if (data.phone !== undefined) next.phone = data.phone
            if (data.email !== undefined) next.email = data.email
            if (data.supplierTypes !== undefined) {
              const types = normalizeSupplierTypesForSave(data.supplierTypes)
              next.supplierTypes = types.length ? types : undefined
            }
            if (data.active !== undefined) next.active = data.active
            return next
          }),
        )
        toastFsSuccess('הספק עודכן')
      } catch (e) {
        toastFsError('שגיאה בעדכון ספק', e, 'updateSupplier')
      }
    },
    [user, displayName],
  )

  const deleteSupplier = useCallback(async (id: string) => {
    try {
      await hubFetch('/api/suppliers/' + id, { method: 'DELETE' })
      setSuppliers((prev) => prev.filter((s) => s.id !== id))
      toastFsSuccess('הספק נמחק')
    } catch (e) {
      toastFsError('שגיאה במחיקת ספק', e, 'deleteSupplier')
    }
  }, [])

  const value = useMemo(
    () => ({
      suppliers,
      loading,
      error,
      addSupplier,
      updateSupplier,
      deleteSupplier,
    }),
    [suppliers, loading, error, addSupplier, updateSupplier, deleteSupplier],
  )

  return (
    <SuppliersContext.Provider value={value}>
      {children}
    </SuppliersContext.Provider>
  )
}

export function useSuppliers(): SuppliersContextValue {
  const ctx = useContext(SuppliersContext)
  if (!ctx) throw new Error('useSuppliers חייב להיות בתוך SuppliersProvider')
  return ctx
}
