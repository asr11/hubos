import { hubFetch } from '../lib/hubFetch'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../contexts/AuthContext'
import { stripUndefined } from '../lib/stripUndefined'
import { toastFsError } from '../lib/fsToast'
import type { ImportRule } from '../lib/bankImport/matching'



/** מזהה מסמך בטוח מתוך merchantKey (Firestore לא מאפשר '/' ועוד) */
function ruleDocId(merchantKey: string): string {
  return encodeURIComponent(merchantKey).replace(/[.#$/[\]]/g, '_')
}

export function useFinanceImportRules() {
  const { user } = useAuth()
  const [rules, setRules] = useState<ImportRule[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!user) {
      setRules([])
      setLoading(false)
      return
    }
    setLoading(true)
    let active = true
    hubFetch('/api/financeImportRules')
      .then((res) => res.json())
      .then((data) => {
        if (!active) return
        setRules(Array.isArray(data) ? data : [])
        setLoading(false)
      })
      .catch((err) => {
        if (!active) return
        toastFsError('שגיאה בטעינת כללי ייבוא', err, 'importRules fetch')
        setRules([])
        setLoading(false)
      })
    return () => {
      active = false
    }
  }, [user])

  const rulesByKey = useMemo(() => {
    const m = new Map<string, ImportRule>()
    rules.forEach((r) => {
      if (r.merchantKey) m.set(r.merchantKey, r)
    })
    return m
  }, [rules])

  /** שומר/מעדכן כלל מיפוי לבית עסק — נלמד מבחירת המשתמש */
  const saveRule = useCallback(async (rule: ImportRule) => {
    if (!rule.merchantKey) return
    try {
      const res = await hubFetch(`/api/financeImportRules/${ruleDocId(rule.merchantKey)}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(stripUndefined({
          merchantKey: rule.merchantKey,
          type: rule.type,
          vatClass: rule.vatClass,
          projectId: rule.projectId,
          clientId: rule.clientId,
          name: rule.name,
          recurring: rule.recurring,
        })),
      })
      if (!res.ok) throw new Error('API Error')
    } catch (e) {
      const code =
        e && typeof e === 'object' && 'code' in e
          ? String((e as { code?: string }).code)
          : ''
      if (code !== 'permission-denied') {
        toastFsError('שגיאה בשמירת כלל ייבוא', e, 'saveRule')
      }
    }
  }, [])

  const saveRulesBatch = useCallback(
    async (list: ImportRule[]) => {
      await Promise.all(list.map((r) => saveRule(r)))
    },
    [saveRule],
  )

  return { rules, rulesByKey, loading, saveRule, saveRulesBatch }
}
