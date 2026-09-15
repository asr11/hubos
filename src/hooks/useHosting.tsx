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
import { stripUndefined } from '../lib/stripUndefined'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type { HostingRecord } from '../types'

type HostingContextValue = {
  records: HostingRecord[]
  loading: boolean
  error: Error | null
  addRecord: (
    data: Omit<HostingRecord, 'id' | 'createdAt' | 'updatedAt'>,
  ) => Promise<string | null>
  updateRecord: (
    id: string,
    data: Partial<Omit<HostingRecord, 'id' | 'createdAt' | 'updatedAt'>>,
  ) => Promise<void>
  deleteRecord: (id: string) => Promise<void>
  /** מחיקת כל הרשומות מהמאגר (התחלה נקייה) — מחזיר כמות שנמחקה */
  deleteAllRecords: () => Promise<number>
}

const HostingContext = createContext<HostingContextValue | null>(null)

function buildFirestorePayload(
  data: Partial<Omit<HostingRecord, 'id' | 'createdAt' | 'updatedAt'>>,
): Record<string, unknown> {
  const o: Record<string, unknown> = {}
  if (data.clientId !== undefined) o.clientId = data.clientId
  if (data.domain !== undefined) o.domain = data.domain
  if (data.siteName !== undefined) o.siteName = data.siteName

  if (data.serverType !== undefined) o.serverType = data.serverType
  if (data.serverProvider !== undefined) o.serverProvider = data.serverProvider
  if (data.serverLocation !== undefined) o.serverLocation = data.serverLocation
  if (data.hostingRenewalDate !== undefined)
    o.hostingRenewalDate = data.hostingRenewalDate
  if (data.hostingCycle !== undefined) o.hostingCycle = data.hostingCycle

  if (data.registrar !== undefined) o.registrar = data.registrar
  if (data.domainManager !== undefined) o.domainManager = data.domainManager
  if (data.domainManagerNote !== undefined)
    o.domainManagerNote = data.domainManagerNote
  if (data.domainRenewalDate !== undefined)
    o.domainRenewalDate = data.domainRenewalDate

  if (data.greenInvoiceRetainer !== undefined)
    o.greenInvoiceRetainer = data.greenInvoiceRetainer
  if (data.retainerAmount !== undefined) o.retainerAmount = data.retainerAmount
  if (data.retainerCycle !== undefined) o.retainerCycle = data.retainerCycle

  if (data.sslExpiry !== undefined) o.sslExpiry = data.sslExpiry
  if (data.ipAddress !== undefined) o.ipAddress = data.ipAddress
  if (data.controlPanel !== undefined) o.controlPanel = data.controlPanel
  if (data.username !== undefined) o.username = data.username
  if (data.platform !== undefined) o.platform = data.platform
  if (data.phpVersion !== undefined) o.phpVersion = data.phpVersion

  if (data.notes !== undefined) o.notes = data.notes
  return o
}

export function HostingProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [records, setRecords] = useState<HostingRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  useEffect(() => {
    if (!user) {
      setRecords([])
      setLoading(false)
      setError(null)
      return
    }
    setLoading(true)
    setLoading(true)
    let active = true
    hubFetch('/api/hostingRecords')
      .then((res) => res.json())
      .then((data) => {
        if (!active) return
        setRecords(Array.isArray(data) ? data : [])
        setLoading(false)
        setError(null)
      })
      .catch((err) => {
        if (!active) return
        console.error('hostingRecords fetch', err)
        toastFsError('שגיאה בטעינת מאגר אחסון', err, 'hostingRecords fetch')
        setError(err instanceof Error ? err : new Error(String(err)))
        setLoading(false)
      })

    return () => {
      active = false
    }
  }, [user])

  const addRecord = useCallback(
    async (data: Omit<HostingRecord, 'id' | 'createdAt' | 'updatedAt'>) => {
      try {
        const res = await hubFetch('/api/hostingRecords', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(buildFirestorePayload(data)),
        })
        if (!res.ok) throw new Error('API Error')
        const result = await res.json()
        toastFsSuccess('האתר נוסף למאגר')
        return result.id || null
      } catch (e) {
        toastFsError('שגיאה בשמירה', e, 'addHostingRecord')
        return null
      }
    },
    [],
  )

  const updateRecord = useCallback(
    async (
      id: string,
      data: Partial<Omit<HostingRecord, 'id' | 'createdAt' | 'updatedAt'>>,
    ) => {
      try {
        const res = await hubFetch(`/api/hostingRecords/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(stripUndefined(buildFirestorePayload(data))),
        })
        if (!res.ok) throw new Error('API Error')
        toastFsSuccess('הרשומה עודכנה')
      } catch (e) {
        toastFsError('שגיאה בעדכון', e, 'updateHostingRecord')
      }
    },
    [],
  )

  const deleteRecord = useCallback(async (id: string) => {
    try {
      const res = await hubFetch(`/api/hostingRecords/${id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error('API Error')
      toastFsSuccess('הרשומה נמחקה')
    } catch (e) {
      toastFsError('שגיאה במחיקה', e, 'deleteHostingRecord')
    }
  }, [])

  const deleteAllRecords = useCallback(async () => {
    try {
      const dataRes = await hubFetch('/api/hostingRecords')
      const data = await dataRes.json()
      const ids = Array.isArray(data) ? data.map((d: any) => d.id) : []
      if (!ids.length) return 0
      for (const id of ids) {
        await hubFetch(`/api/hostingRecords/${id}`, { method: 'DELETE' })
      }
      toastFsSuccess(`נמחקו ${ids.length} רשומות — המאגר נקי`)
      return ids.length
    } catch (e) {
      toastFsError('שגיאה במחיקת המאגר', e, 'deleteAllHostingRecords')
      return 0
    }
  }, [])

  const value = useMemo(
    () => ({
      records,
      loading,
      error,
      addRecord,
      updateRecord,
      deleteRecord,
      deleteAllRecords,
    }),
    [
      records,
      loading,
      error,
      addRecord,
      updateRecord,
      deleteRecord,
      deleteAllRecords,
    ],
  )

  return (
    <HostingContext.Provider value={value}>{children}</HostingContext.Provider>
  )
}

export function useHosting(): HostingContextValue {
  const ctx = useContext(HostingContext)
  if (!ctx) throw new Error('useHosting חייב להיות בתוך HostingProvider')
  return ctx
}
