import { useEffect, useState } from 'react'
import { hubFetch } from '../lib/hubFetch'
import { useHubStore } from '../store/useStore'
import { Modal } from '../components/shared/Modal'

export function SuperAdmin() {
  const [tenants, setTenants] = useState<{ id: string; name: string; created_at: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [newTenantName, setNewTenantName] = useState('')
  const pushToast = useHubStore((s) => s.pushToast)

  useEffect(() => {
    fetchTenants()
  }, [])

  const fetchTenants = async () => {
    try {
      const res = await hubFetch('/api/admin/tenants')
      if (res.ok) {
        const data = await res.json()
        setTenants(data)
      } else {
        pushToast('שגיאה בטעינת שוכרים', 'error')
      }
    } catch (e) {
      pushToast('שגיאה בטעינת שוכרים', 'error')
    } finally {
      setLoading(false)
    }
  }

  const handleCreate = async () => {
    if (!newTenantName.trim()) return pushToast('יש להזין שם', 'error')
    try {
      const res = await hubFetch('/api/admin/tenants', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: newTenantName.trim() })
      })
      if (res.ok) {
        pushToast('השוכר נוצר בהצלחה', 'success')
        setIsModalOpen(false)
        setNewTenantName('')
        fetchTenants()
      } else {
        pushToast('שגיאה ביצירת השוכר', 'error')
      }
    } catch (e) {
      pushToast('שגיאה ביצירת השוכר', 'error')
    }
  }

  return (
    <div className="mx-auto max-w-[1400px] space-y-6 px-8 py-8" dir="rtl">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-[26px] font-extrabold text-[var(--text-dark)]">Super Admin</h1>
          <p className="mt-1 text-[13px] text-[var(--text-mid)]">ניהול שוכרים במערכת</p>
        </div>
        <button
          onClick={() => setIsModalOpen(true)}
          className="inline-flex h-9 items-center justify-center rounded-[10px] bg-[var(--orange)] px-4 text-xs font-semibold text-white transition hover:bg-[#ea6c0a]"
        >
          + שוכר חדש
        </button>
      </header>

      {loading ? (
        <div className="text-[var(--text-mid)]">טוען...</div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-[var(--border-orange-soft)] bg-[var(--dash-panel-bg)] shadow-sm">
          <table className="w-full text-right text-[13px]">
            <thead className="bg-[var(--surface-muted)] text-[12px] font-semibold text-[var(--text-light)]">
              <tr>
                <th className="px-4 py-3">ID</th>
                <th className="px-4 py-3">שם השוכר</th>
                <th className="px-4 py-3">תאריך יצירה</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--border-orange-soft)] text-[var(--text-dark)]">
              {tenants.map(t => (
                <tr key={t.id} className="transition hover:bg-[var(--surface-muted)]">
                  <td className="px-4 py-3 font-mono text-xs">{t.id}</td>
                  <td className="px-4 py-3 font-bold">{t.name}</td>
                  <td className="px-4 py-3 text-[var(--text-mid)]">
                    {t.created_at ? new Date(t.created_at).toLocaleDateString('he-IL') : ''}
                  </td>
                </tr>
              ))}
              {tenants.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-[var(--text-mid)]">
                    לא נמצאו שוכרים במערכת.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {isModalOpen && (
        <Modal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          title="הוספת שוכר חדש"
        >
          <div className="space-y-4">
            <div>
              <label className="mb-1 block text-sm font-semibold text-[var(--text-dark)]">
                שם השוכר
              </label>
              <input
                type="text"
                value={newTenantName}
                onChange={(e) => setNewTenantName(e.target.value)}
                className="h-10 w-full rounded-[10px] border border-[var(--border-orange-soft)] bg-[var(--surface-muted)] px-3 text-sm text-[var(--text-dark)] focus:border-[var(--orange)] focus:bg-[var(--dash-panel-bg)] focus:outline-none"
                placeholder="הכנס שם..."
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <button
                onClick={() => setIsModalOpen(false)}
                className="h-9 rounded-[10px] px-4 text-sm font-semibold text-[var(--text-mid)] transition hover:bg-[var(--surface-muted)] hover:text-[var(--text-dark)]"
              >
                ביטול
              </button>
              <button
                onClick={handleCreate}
                className="h-9 rounded-[10px] bg-[var(--orange)] px-4 text-sm font-semibold text-white transition hover:bg-[#ea6c0a]"
              >
                שמור
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  )
}
