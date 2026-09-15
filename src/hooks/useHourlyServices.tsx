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
import {
  buildHourlyServiceSharePayload,
  generateShareToken,
  publishHourlyServiceShare,
  unpublishHourlyServiceShare,
} from '../lib/hourlyServiceShare'
import type { HourlyService, HourlyServiceEntry } from '../types'

type HourlyServicesContextValue = {
  services: HourlyService[]
  entries: HourlyServiceEntry[]
  loading: boolean
  error: Error | null
  addService: (
    data: Omit<HourlyService, 'id' | 'createdAt' | 'updatedAt'>,
  ) => Promise<string | null>
  updateService: (
    id: string,
    data: Partial<Omit<HourlyService, 'id' | 'createdAt'>>,
  ) => Promise<void>
  deleteService: (id: string) => Promise<void>
  addEntry: (
    serviceId: string,
    data: Omit<HourlyServiceEntry, 'id' | 'serviceId' | 'createdAt'>,
  ) => Promise<string | null>
  updateEntry: (
    serviceId: string,
    entryId: string,
    data: Partial<Omit<HourlyServiceEntry, 'id' | 'serviceId' | 'createdAt'>>,
  ) => Promise<void>
  deleteEntry: (serviceId: string, entryId: string) => Promise<void>
  publishServiceShare: (
    serviceId: string,
    clientName: string,
  ) => Promise<string | null>
  refreshServiceShare: (
    serviceId: string,
    clientName?: string,
    entriesOverride?: HourlyServiceEntry[],
  ) => Promise<void>
  revokeServiceShare: (serviceId: string) => Promise<void>
}

const HourlyServicesContext = createContext<HourlyServicesContextValue | null>(
  null,
)

export function HourlyServicesProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [services, setServices] = useState<HourlyService[]>([])
  const [entries, setEntries] = useState<HourlyServiceEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  useEffect(() => {
    if (!user) {
      setServices([])
      setEntries([])
      setLoading(false)
      return
    }
    let cancelled = false
    
    

    hubFetch('/api/hourlyServices')
      .then(res => res.ok ? res.json() : Promise.reject(new Error('Failed to fetch services')))
      .then(data => {
        if (cancelled) return
        setServices(Array.isArray(data) ? data : [])
        setLoading(false)
      })
      .catch(err => {
        if (cancelled) return
        setError(err)
        setLoading(false)
      })

    hubFetch('/api/entries')
      .then(res => res.ok ? res.json() : Promise.reject(new Error('Failed to fetch entries')))
      .then(data => {
        if (cancelled) return
        setEntries(Array.isArray(data) ? data : [])
      })
      .catch(err => {
        if (cancelled) return
        setError(err)
      })

    return () => {
      cancelled = true
    }
  }, [user])

  const refreshServiceShare = useCallback(
    async (
      serviceId: string,
      clientName?: string,
      entriesOverride?: HourlyServiceEntry[],
    ): Promise<void> => {
      const service = services.find((s) => s.id === serviceId)
      if (!service?.shareEnabled || !service.shareToken) return
      let name = clientName?.trim() ?? ''
      if (!name) {
        const clientRes = await hubFetch(`/api/clients/${service.clientId}`)
        if (clientRes.ok) {
          const clientData = await clientRes.json()
          name = clientData.name ?? ''
        } else {
          name = ''
        }
      }
      const serviceEntries =
        entriesOverride ?? entries.filter((e) => e.serviceId === serviceId)
      const payload = buildHourlyServiceSharePayload(
        service,
        serviceEntries,
        name,
      )
      await publishHourlyServiceShare(service.shareToken, payload)
    },
    [services, entries],
  )

  const publishServiceShare = useCallback(
    async (serviceId: string, clientName: string): Promise<string | null> => {
      try {
        const service = services.find((s) => s.id === serviceId)
        if (!service) return null
        const token = service.shareToken ?? generateShareToken()
        const serviceEntries = entries.filter((e) => e.serviceId === serviceId)
        const payload = buildHourlyServiceSharePayload(
          service,
          serviceEntries,
          clientName,
        )
        await publishHourlyServiceShare(token, payload)
        const res = await hubFetch('/api/hourlyServices/' + serviceId, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
          shareToken: token,
          shareEnabled: true,
          updatedAt: new Date().toISOString(),
        }) })
        if (!res.ok) throw new Error('Failed to update')
        toastFsSuccess('קישור שיתוף ללקוח מוכן')
        return token
      } catch (e) {
        toastFsError('יצירת קישור שיתוף נכשלה', e)
        return null
      }
    },
    [services, entries],
  )

  const revokeServiceShare = useCallback(
    async (serviceId: string): Promise<void> => {
      try {
        const service = services.find((s) => s.id === serviceId)
        if (!service?.shareToken) return
        await unpublishHourlyServiceShare(service.shareToken)
        const res = await hubFetch('/api/hourlyServices/' + serviceId, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
          shareEnabled: false,
          updatedAt: new Date().toISOString(),
        }) })
        if (!res.ok) throw new Error('Failed to update')
        toastFsSuccess('שיתוף עם הלקוח בוטל')
      } catch (e) {
        toastFsError('ביטול שיתוף נכשל', e)
      }
    },
    [services],
  )

  const addService = useCallback(
    async (
      data: Omit<HourlyService, 'id' | 'createdAt' | 'updatedAt'>,
    ): Promise<string | null> => {
      try {
        const payload = stripUndefined({
          clientId: data.clientId.trim(),
          title: data.title.trim(),
          status: data.status,
          serviceKind: data.serviceKind,
          hourlyRate: data.hourlyRate,
          hoursPackageTotal: data.hoursPackageTotal,
          projectId: data.projectId?.trim() || undefined,
          notes: data.notes?.trim() || undefined,
          createdAt: new Date().toISOString(),
          updatedAt: new Date().toISOString(),
        })
        const res = await hubFetch('/api/hourlyServices', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        if (!res.ok) throw new Error('Failed to add')
        const ref = await res.json()
        toastFsSuccess('חשבון שירות שעות נוצר')
        return ref.id
      } catch (e) {
        toastFsError('יצירת חשבון שירות נכשלה', e)
        return null
      }
    },
    [],
  )

  const updateService = useCallback(
    async (
      id: string,
      data: Partial<Omit<HourlyService, 'id' | 'createdAt'>>,
    ): Promise<void> => {
      try {
        const payload = stripUndefined({
          ...data,
          title: data.title?.trim(),
          clientId: data.clientId?.trim(),
          projectId: data.projectId?.trim() || undefined,
          notes: data.notes?.trim() || undefined,
          updatedAt: new Date().toISOString(),
        })
        const res = await hubFetch('/api/hourlyServices/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        if (!res.ok) throw new Error('Failed to update')
        toastFsSuccess('חשבון שירות עודכן')
      } catch (e) {
        toastFsError('עדכון חשבון שירות נכשל', e)
      }
    },
    [],
  )

  const deleteService = useCallback(
    async (id: string): Promise<void> => {
      try {
        const serviceEntries = entries.filter((e) => e.serviceId === id)
        await Promise.all(
          serviceEntries.map((e) =>
            hubFetch(`/api/entries/${e.id}`, { method: 'DELETE' })
          ),
        )
        const res = await hubFetch('/api/hourlyServices/' + id, { method: 'DELETE' })
        if (!res.ok) throw new Error('Failed to delete')
        toastFsSuccess('חשבון שירות נמחק')
      } catch (e) {
        toastFsError('מחיקת חשבון שירות נכשלה', e)
      }
    },
    [entries],
  )

  const addEntry = useCallback(
    async (
      serviceId: string,
      data: Omit<HourlyServiceEntry, 'id' | 'serviceId' | 'createdAt'>,
    ): Promise<string | null> => {
      try {
        const payload = stripUndefined({
          serviceId,
          clientId: data.clientId.trim(),
          performerId: data.performerId,
          performerName: data.performerName.trim(),
          workDate: data.workDate.slice(0, 10),
          startTime: data.startTime,
          endTime: data.endTime,
          hours: data.hours,
          taskTitle: data.taskTitle.trim(),
          description: data.description?.trim() || undefined,
          workCategory: data.workCategory,
          billable: data.billable,
          status: data.status,
          createdAt: new Date().toISOString(),
        })
        const res = await hubFetch(`/api/hourlyServices/${serviceId}/entries`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        if (!res.ok) throw new Error('Failed to add')
        const ref = await res.json()
        toastFsSuccess('רשומת שעות נוספה')
        const svc = services.find((s) => s.id === serviceId)
        if (svc?.shareEnabled && svc.shareToken) {
          void refreshServiceShare(serviceId, undefined, [
            ...entries.filter((e) => e.serviceId === serviceId),
            {
              id: ref.id,
              serviceId,
              ...data,
              createdAt: new Date().toISOString(),
            } as HourlyServiceEntry,
          ])
        }
        return ref.id
      } catch (e) {
        toastFsError('הוספת רשומת שעות נכשלה', e)
        return null
      }
    },
    [services, entries, refreshServiceShare],
  )

  const updateEntry = useCallback(
    async (
      serviceId: string,
      entryId: string,
      data: Partial<Omit<HourlyServiceEntry, 'id' | 'serviceId' | 'createdAt'>>,
    ): Promise<void> => {
      try {
        const payload = stripUndefined({
          ...data,
          taskTitle: data.taskTitle?.trim(),
          description: data.description?.trim() || undefined,
          workDate: data.workDate?.slice(0, 10),
        })
        const res = await hubFetch(`/api/entries/${entryId}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        if (!res.ok) throw new Error('Failed to update')
        toastFsSuccess('רשומת שעות עודכנה')
        const svc = services.find((s) => s.id === serviceId)
        if (svc?.shareEnabled && svc.shareToken) {
          void refreshServiceShare(
            serviceId,
            undefined,
            entries
              .filter((e) => e.serviceId === serviceId)
              .map((e) =>
                e.id === entryId
                  ? {
                      ...e,
                      ...data,
                      taskTitle: data.taskTitle?.trim() ?? e.taskTitle,
                      workDate: data.workDate?.slice(0, 10) ?? e.workDate,
                    }
                  : e,
              ),
          )
        }
      } catch (e) {
        toastFsError('עדכון רשומת שעות נכשל', e)
      }
    },
    [services, entries, refreshServiceShare],
  )

  const deleteEntry = useCallback(
    async (serviceId: string, entryId: string): Promise<void> => {
      try {
        const res = await hubFetch(`/api/entries/${entryId}`, { method: 'DELETE' })
        if (!res.ok) throw new Error('Failed to delete')
        toastFsSuccess('רשומת שעות נמחקה')
        const svc = services.find((s) => s.id === serviceId)
        if (svc?.shareEnabled && svc.shareToken) {
          void refreshServiceShare(
            serviceId,
            undefined,
            entries.filter(
              (e) => e.serviceId === serviceId && e.id !== entryId,
            ),
          )
        }
      } catch (e) {
        toastFsError('מחיקת רשומת שעות נכשלה', e)
      }
    },
    [services, entries, refreshServiceShare],
  )

  const value = useMemo(
    () => ({
      services,
      entries,
      loading,
      error,
      addService,
      updateService,
      deleteService,
      addEntry,
      updateEntry,
      deleteEntry,
      publishServiceShare,
      refreshServiceShare,
      revokeServiceShare,
    }),
    [
      services,
      entries,
      loading,
      error,
      addService,
      updateService,
      deleteService,
      addEntry,
      updateEntry,
      deleteEntry,
      publishServiceShare,
      refreshServiceShare,
      revokeServiceShare,
    ],
  )

  return (
    <HourlyServicesContext.Provider value={value}>
      {children}
    </HourlyServicesContext.Provider>
  )
}

export function useHourlyServices(): HourlyServicesContextValue {
  const ctx = useContext(HourlyServicesContext)
  if (!ctx) {
    throw new Error('useHourlyServices חייב להיות בתוך HourlyServicesProvider')
  }
  return ctx
}
