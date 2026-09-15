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
import type { Campaign, CampaignStatus } from '../types'

type CampaignsContextValue = {
  campaigns: Campaign[]
  loading: boolean
  error: Error | null
  addCampaign: (data: Omit<Campaign, 'id' | 'createdAt'>) => Promise<void>
  updateCampaign: (id: string, data: Partial<Campaign>) => Promise<void>
  updateCampaignStatus: (id: string, status: CampaignStatus) => Promise<void>
  deleteCampaign: (id: string) => Promise<void>
}

const CampaignsContext = createContext<CampaignsContextValue | null>(null)

export function CampaignsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [campaigns, setCampaigns] = useState<Campaign[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  const displayName = useCallback(() => {
    if (!user) return 'משתמש'
    return user.displayName?.trim() || user.email?.split('@')[0] || 'משתמש'
  }, [user])

  useEffect(() => {
    if (!user) {
      setCampaigns([])
      setLoading(false)
      setError(null)
      return
    }
    setLoading(true)
    hubFetch('/api/campaigns')
      .then(res => {
        if (!res.ok) throw new Error('Failed to fetch')
        return res.json()
      })
      .then((data: Campaign[]) => {
        setCampaigns(Array.isArray(data) ? data : [])
        setLoading(false)
        setError(null)
      })
      .catch(err => {
        console.error('campaigns fetch', err)
        toastFsError('שגיאה בטעינת קמפיינים', err, 'campaigns fetch')
        setError(err instanceof Error ? err : new Error(String(err)))
        setLoading(false)
      })
  }, [user])

  const addCampaign = useCallback(
    async (data: Omit<Campaign, 'id' | 'createdAt'>) => {
      if (!user) {
        toastFsError('יש להתחבר להוספת קמפיין', new Error('no user'), 'addCampaign')
        return
      }
      try {
        const uname = displayName()
        const res = await hubFetch('/api/campaigns', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(stripUndefined({
            ...data,
            name: data.name.trim(),
            createdByUserId: user.uid,
            createdByUserName: uname,
            lastActorUserId: user.uid,
            lastActorUserName: uname,
          } as Record<string, unknown>))
        })
        if (!res.ok) throw new Error('Failed to add campaign')
        toastFsSuccess('הקמפיין נוסף')
      } catch (e) {
        toastFsError('שגיאה בהוספת קמפיין', e, 'addCampaign')
      }
    },
    [user, displayName],
  )

  const updateCampaign = useCallback(
    async (id: string, data: Partial<Campaign>) => {
      if (!user) {
        toastFsError('יש להתחבר לעדכון קמפיין', new Error('no user'), 'updateCampaign')
        return
      }
      try {
        const raw = data as Record<string, unknown>
        const uname = displayName()
        const res = await hubFetch('/api/campaigns/' + id, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ...Object.fromEntries(Object.entries(raw).filter(([, v]) => v !== undefined)),
            lastActorUserId: user.uid,
            lastActorUserName: uname,
          })
        })
        if (!res.ok) throw new Error('Failed to update campaign')
        toastFsSuccess('הקמפיין עודכן')
      } catch (e) {
        toastFsError('שגיאה בעדכון קמפיין', e, 'updateCampaign')
      }
    },
    [user, displayName],
  )

  const updateCampaignStatus = useCallback(
    async (id: string, status: CampaignStatus) => {
      if (!user) {
        toastFsError(
          'יש להתחבר לעדכון סטטוס',
          new Error('no user'),
          'updateCampaignStatus',
        )
        return
      }
      try {
        const res = await hubFetch('/api/campaigns/' + id, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            status,
            lastActorUserId: user.uid,
            lastActorUserName: displayName(),
          })
        })
        if (!res.ok) throw new Error('Failed to update status')
        toastFsSuccess('סטטוס קמפיין עודכן')
      } catch (e) {
        toastFsError('שגיאה בעדכון סטטוס קמפיין', e, 'updateCampaignStatus')
      }
    },
    [user, displayName],
  )

  const deleteCampaign = useCallback(async (id: string) => {
    try {
      const res = await hubFetch('/api/campaigns/' + id, { method: 'DELETE' })
      if (!res.ok) throw new Error('Failed to delete campaign')
      toastFsSuccess('הקמפיין נמחק')
    } catch (e) {
      toastFsError('שגיאה במחיקת קמפיין', e, 'deleteCampaign')
    }
  }, [])

  const value = useMemo(
    () => ({
      campaigns,
      loading,
      error,
      addCampaign,
      updateCampaign,
      updateCampaignStatus,
      deleteCampaign,
    }),
    [campaigns, loading, error, addCampaign, updateCampaign, updateCampaignStatus, deleteCampaign],
  )

  return (
    <CampaignsContext.Provider value={value}>{children}</CampaignsContext.Provider>
  )
}

export function useCampaigns(): CampaignsContextValue {
  const ctx = useContext(CampaignsContext)
  if (!ctx) throw new Error('useCampaigns חייב להיות בתוך CampaignsProvider')
  return ctx
}
