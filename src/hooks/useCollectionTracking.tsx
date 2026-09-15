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
import { todayYmd } from '../lib/dates'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type { CollectionTracking } from '../types'

export type CollectionTrackingInput = {
  clientId: string
  lastRequestDate: string
  dueDate: string
  paidDate?: string
  note?: string
}

export type RecordPaymentDemandInput = {
  method: string
  clientReply: string
}

type CollectionTrackingContextValue = {
  entries: CollectionTracking[]
  loading: boolean
  error: Error | null
  upsertTracking: (data: CollectionTrackingInput) => Promise<void>
  removeFromTracking: (clientId: string) => Promise<void>
  markPaid: (clientId: string) => Promise<void>
  clearPaid: (clientId: string) => Promise<void>
  recordPaymentDemand: (
    clientId: string,
    payload: RecordPaymentDemandInput,
  ) => Promise<boolean>
}

const CollectionTrackingContext =
  createContext<CollectionTrackingContextValue | null>(null)

export function CollectionTrackingProvider({
  children,
}: {
  children: ReactNode
}) {
  const { user } = useAuth()
  const [entries, setEntries] = useState<CollectionTracking[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  const displayName = useCallback(() => {
    if (!user) return 'משתמש'
    return user.displayName?.trim() || user.email?.split('@')[0] || 'משתמש'
  }, [user])

  useEffect(() => {
    if (!user) {
      setEntries([])
      setLoading(false)
      setError(null)
      return
    }
    setLoading(true)
    hubFetch('/api/collectionTracking')
      .then(res => {
        if (!res.ok) throw new Error('Failed to fetch')
        return res.json()
      })
      .then(data => {
        setEntries(Array.isArray(data) ? data : [])
        setLoading(false)
        setError(null)
      })
      .catch(err => {
        console.error('collectionTracking fetch', err)
        toastFsError('שגיאה בטעינת מעקב גבייה', err, 'collectionTracking fetch')
        setError(err instanceof Error ? err : new Error(String(err)))
        setLoading(false)
      })
  }, [user])

  const upsertTracking = useCallback(async (data: CollectionTrackingInput) => {
    try {
      const paidPatch =
        data.paidDate?.trim()
          ? { paidDate: data.paidDate.trim().slice(0, 10) }
          : { paidDate: null }
      const notePatch =
        data.note?.trim()
          ? { note: data.note.trim() }
          : { note: null }
      const res = await hubFetch('/api/collectionTracking/' + data.clientId, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          clientId: data.clientId,
          lastRequestDate: data.lastRequestDate.slice(0, 10),
          dueDate: data.dueDate.slice(0, 10),
          ...paidPatch,
          ...notePatch,
          ...(user
            ? {
                lastActorUserId: user.uid,
                lastActorUserName: displayName(),
              }
            : {}),
        })
      })
      if (!res.ok) throw new Error('Failed to upsert')
      toastFsSuccess('המעקב נשמר')
    } catch (e) {
      toastFsError('שגיאה בשמירת מעקב גבייה', e, 'upsertTracking')
    }
  }, [user, displayName])

  const removeFromTracking = useCallback(async (clientId: string) => {
    try {
      const res = await hubFetch('/api/collectionTracking/' + clientId, { method: 'DELETE' })
      if (!res.ok) throw new Error('Failed to delete')
      toastFsSuccess('הוסר ממעקב הגבייה')
    } catch (e) {
      toastFsError('שגיאה בהסרה ממעקב', e, 'removeFromTracking')
    }
  }, [])

  const markPaid = useCallback(async (clientId: string) => {
    try {
      const res = await hubFetch('/api/collectionTracking/' + clientId, { method: 'DELETE' })
      if (!res.ok) throw new Error('Failed to delete')
      toastFsSuccess('הגבייה סומנה כשולמה — הרשומה נסגרה. ניתן לפתוח מעקב חדש לאחר מכן')
    } catch (e) {
      toastFsError('שגיאה בסגירת המעקב', e, 'markPaid tracking')
    }
  }, [])

  const clearPaid = useCallback(async (clientId: string) => {
    try {
      const res = await hubFetch('/api/collectionTracking/' + clientId, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          paidDate: null,
        })
      })
      if (!res.ok) throw new Error('Failed to update')
      toastFsSuccess('תאריך תשלום הוסר — המעקב נשאר פתוח')
    } catch (e) {
      toastFsError('שגיאה בעדכון', e, 'clearPaid tracking')
    }
  }, [])

  const recordPaymentDemand = useCallback(
    async (clientId: string, payload: RecordPaymentDemandInput) => {
      const method = payload.method.trim()
      const clientReply = payload.clientReply.trim()
      if (!method && !clientReply) {
        toastFsError(
          'נא למלא לפחות אחד מהשדות: אופן הדרישה או מענה הלקוח',
          new Error('empty demand'),
          'recordPaymentDemand',
        )
        return false
      }
      try {
        const res = await hubFetch('/api/collectionTracking/' + clientId, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            lastDemandMethod: method ? method : null,
            lastDemandClientReply: clientReply ? clientReply : null,
            lastRequestDate: todayYmd(),
            ...(user
              ? {
                  lastActorUserId: user.uid,
                  lastActorUserName: displayName(),
                }
              : {}),
          })
        })
        if (!res.ok) throw new Error('Failed to update')
        toastFsSuccess('פעולת הדרישה נרשמה במעקב')
        return true
      } catch (e) {
        toastFsError('שגיאה בשמירת פעולת הדרישה', e, 'recordPaymentDemand')
        return false
      }
    },
    [user, displayName],
  )

  const value = useMemo(
    () => ({
      entries,
      loading,
      error,
      upsertTracking,
      removeFromTracking,
      markPaid,
      clearPaid,
      recordPaymentDemand,
    }),
    [
      entries,
      loading,
      error,
      upsertTracking,
      removeFromTracking,
      markPaid,
      clearPaid,
      recordPaymentDemand,
    ],
  )

  return (
    <CollectionTrackingContext.Provider value={value}>
      {children}
    </CollectionTrackingContext.Provider>
  )
}

export function useCollectionTracking(): CollectionTrackingContextValue {
  const ctx = useContext(CollectionTrackingContext)
  if (!ctx) {
    throw new Error('useCollectionTracking חייב להיות בתוך CollectionTrackingProvider')
  }
  return ctx
}
