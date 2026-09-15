import { hubFetch } from '../lib/hubFetch'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../contexts/AuthContext'
import { isAdminRole } from '../components/RequireAdmin'
import {
  parseClockifyConfig,
  parseClockifyPublicSettings,
  saveClockifyConfigToFirestore,
} from '../lib/clockifyService'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type { ClockifyIntegrationConfig, ClockifyPublicSettings } from '../types'
import { useUsers } from './useUsers'

export function useClockify() {
  const { user } = useAuth()
  const { users } = useUsers()
  const role = users.find((m) => m.id === user?.uid)?.role ?? ''
  const isAdmin = isAdminRole(role)

  const [config, setConfig] = useState<ClockifyIntegrationConfig | null>(null)
  const [configLoading, setConfigLoading] = useState(true)

  const [publicSettings, setPublicSettings] = useState<ClockifyPublicSettings | null>(null)
  const [settingsLoading, setSettingsLoading] = useState(true)

  useEffect(() => {
    if (!user || !isAdmin) {
      setConfig(null)
      setConfigLoading(false)
      return
    }
    setConfigLoading(true)
    hubFetch('/api/attendanceConfig/clockify')
      .then(res => {
        if (!res.ok && res.status !== 404) throw new Error('Failed to fetch')
        return res.status === 404 ? {} : res.json()
      })
      .then(data => {
        setConfig(parseClockifyConfig(data))
        setConfigLoading(false)
      })
      .catch(err => {
        console.error('clockify config fetch', err)
        toastFsError('שגיאה בטעינת הגדרות Clockify', err, 'clockifyConfig')
        setConfigLoading(false)
      })
  }, [user, isAdmin])

  useEffect(() => {
    if (!user) {
      setPublicSettings(null)
      setSettingsLoading(false)
      return
    }
    setSettingsLoading(true)
    hubFetch('/api/attendanceConfig/settings')
      .then(res => {
        if (!res.ok && res.status !== 404) throw new Error('Failed to fetch')
        return res.status === 404 ? {} : res.json()
      })
      .then(data => {
        setPublicSettings(parseClockifyPublicSettings(data))
        setSettingsLoading(false)
      })
      .catch(err => {
        console.error('clockify settings fetch', err)
        toastFsError('שגיאה בטעינת הגדרות נוכחות', err, 'clockifySettings')
        setSettingsLoading(false)
      })
  }, [user])

  const saveClockifyConfig = useCallback(
    async (payload: { apiKey: string; workspaceId: string; apiBaseUrl?: string }) => {
      if (!isAdmin || !user) return
      try {
        await saveClockifyConfigToFirestore(payload)
        toastFsSuccess('הגדרות Clockify נשמרו')
      } catch (e) {
        toastFsError('שמירת הגדרות נכשלה', e, 'clockify save')
      }
    },
    [isAdmin, user],
  )

  const workspaceId = useMemo(() => {
    const fromSettings = publicSettings?.workspaceId?.trim() ?? ''
    if (fromSettings) return fromSettings
    if (isAdmin) return config?.workspaceId?.trim() ?? ''
    return ''
  }, [publicSettings?.workspaceId, isAdmin, config?.workspaceId])

  /** אדמין: נדרש גם מפתח בשרת; עובד: מספיק workspace ב-settings */
  const integrationReady = Boolean(
    workspaceId && (isAdmin ? Boolean(config?.apiKey?.trim()) : true),
  )

  return {
    isAdmin,
    userEmail: user?.email?.trim() ?? '',
    config,
    configLoading,
    settingsLoading,
    workspaceId,
    saveClockifyConfig,
    integrationReady,
  }
}
