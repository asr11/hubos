import { hubFetch } from '../lib/hubFetch'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type { PasswordVaultEntry, PasswordVaultWrite } from '../types'


/** מזהה המשתמש ב-Firebase Auth הוא תמיד UID (מחרוזת ייחודית), לא כתובת האימייל להתחברות */


export type UsePasswordVaultOpts = {
  /** uid מההקשר (חייב להתאים ל-auth.currentUser.uid כשמחוברים) */
  uid: string | undefined
  usersReady: boolean
  isVaultAdmin: boolean
}

export function usePasswordVault({ uid, usersReady, isVaultAdmin }: UsePasswordVaultOpts) {
  const [entries, setEntries] = useState<PasswordVaultEntry[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!uid) {
      setEntries([])
      setLoading(false)
      return
    }
    if (!usersReady) {
      setLoading(true)
      return
    }

    let cancelled = false
    let errReported = false

    const reportErr = (err: unknown) => {
      if (errReported) return
      errReported = true
      toastFsError('שגיאה בטעינת כספת הסיסמאות', err, 'passwordVault')
      setLoading(false)
    }

    const fetchVault = async () => {
      try {
        const res = await hubFetch('/api/passwords_vault')
        if (!res.ok) throw new Error('Failed to fetch')
        const data = await res.json()
        if (cancelled) return
        setEntries(Array.isArray(data) ? data : [])
        setLoading(false)
      } catch (err) {
        if (cancelled) return
        reportErr(err)
      }
    }

    fetchVault()

    return () => {
      cancelled = true
    }
  }, [uid, usersReady, isVaultAdmin])

  const addEntry = useCallback(
    async (payload: PasswordVaultWrite) => {
      if (!uid) return
      try {
        let visibleUserIds = [...new Set(payload.visibleUserIds.filter(Boolean))]
        if (!isVaultAdmin && !visibleUserIds.includes(uid)) {
          visibleUserIds = [...visibleUserIds, uid]
        }
        await hubFetch('/api/passwords_vault', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            title: payload.title.trim(),
            category: payload.category.trim(),
            username: payload.username.trim(),
            password: payload.loginType === 'google' ? '' : payload.password,
            loginType: payload.loginType,
            url: payload.url.trim(),
            notes: payload.notes.trim(),
            visibleUserIds,
            clientId: payload.clientId.trim(),
            updatedBy: uid,
          })
        })
        toastFsSuccess('הפריט נשמר בכספת')
      } catch (e) {
        toastFsError('לא ניתן לשמור', e, 'vault add')
      }
    },
    [uid, isVaultAdmin],
  )

  const updateEntry = useCallback(
    async (id: string, payload: PasswordVaultWrite) => {
      if (!uid) return
      try {
        const visibleUserIds = [...new Set(payload.visibleUserIds.filter(Boolean))]
        await hubFetch('/api/passwords_vault/' + id, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            title: payload.title.trim(),
            category: payload.category.trim(),
            username: payload.username.trim(),
            password: payload.loginType === 'google' ? '' : payload.password,
            loginType: payload.loginType,
            url: payload.url.trim(),
            notes: payload.notes.trim(),
            visibleUserIds,
            clientId: payload.clientId.trim(),
            updatedBy: uid,
          })
        })
        toastFsSuccess('הפריט עודכן')
      } catch (e) {
        toastFsError('לא ניתן לעדכן', e, 'vault update')
      }
    },
    [uid],
  )

  const removeEntry = useCallback(async (id: string) => {
    try {
      await hubFetch('/api/passwords_vault/' + id, { method: 'DELETE' })
      toastFsSuccess('הפריט נמחק')
    } catch (e) {
      toastFsError('לא ניתן למחוק', e, 'vault delete')
    }
  }, [])

  const byId = useMemo(() => {
    const m = new Map<string, PasswordVaultEntry>()
    for (const e of entries) m.set(e.id, e)
    return m
  }, [entries])

  return {
    entries,
    loading,
    addEntry,
    updateEntry,
    removeEntry,
    byId,
  }
}
