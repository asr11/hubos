import { useState, useEffect } from 'react'
import { Badge } from '../components/shared/Badge'
import { EmptyState } from '../components/shared/EmptyState'
import { hubSectionClass, hubSectionElevatedClass } from '../lib/hubSectionBorder'
import { useAuth } from '../contexts/AuthContext'

interface CeoAction {
  action_id: string;
  action_type: string;
  status: 'PROPOSED' | 'APPROVED' | 'REJECTED' | 'EXECUTING' | 'EXECUTED' | 'VERIFIED' | 'FAILED';
  proposed_payload: string;
  created_at: string;
  updated_at: string;
}

export function DigitalCeo() {
  const { user } = useAuth()
  const [actions, setActions] = useState<CeoAction[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const fetchActions = async () => {
    try {
      if (!user) return;
      const token = "dummy-token"
      const res = await fetch('/api/ceo/actions', {
        headers: { Authorization: `Bearer ${token}` }
      })
      if (!res.ok) throw new Error('Failed to fetch actions')
      const data = await res.json()
      setActions(data.data || [])
    } catch (err: any) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchActions()
  }, [user])

  const handleApprove = async (id: string) => {
    try {
      const token = "dummy-token"
      await fetch(`/api/ceo/actions/${id}/approve`, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}` 
        },
        body: JSON.stringify({ approved_by: user?.uid })
      })
      fetchActions()
    } catch (err) {
      console.error(err)
      alert('Failed to approve')
    }
  }

  const handleReject = async (id: string) => {
    try {
      const token = "dummy-token"
      await fetch(`/api/ceo/actions/${id}/reject`, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}` 
        },
        body: JSON.stringify({ rejected_by: user?.uid, reason: 'Rejected by UI' })
      })
      fetchActions()
    } catch (err) {
      console.error(err)
      alert('Failed to reject')
    }
  }

  const statusColors: Record<string, string> = {
    PROPOSED: '#D97706',
    APPROVED: '#2563EB',
    EXECUTING: '#7C3AED',
    EXECUTED: '#0F766E',
    VERIFIED: '#16A34A',
    REJECTED: '#DC2626',
    FAILED: '#DC2626',
  }

  return (
    <div className="mx-auto max-w-[1440px] space-y-5 px-4 py-5 md:px-8 md:py-7">
      <header className={`${hubSectionElevatedClass()} rounded-xl p-5 md:p-6`}>
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-[var(--text-dark)] md:text-3xl">
            Digital CEO & Human Gate
          </h1>
          <p className="mt-2 max-w-xl text-sm leading-relaxed text-[var(--text-mid)]">
            Review and approve autonomous actions proposed by the Sovereign Core Engine.
          </p>
        </div>
      </header>

      <div className={`rounded-xl p-5 ${hubSectionClass()}`}>
        {loading ? (
          <p>טוען משימות מנכ״ל...</p>
        ) : error ? (
          <div className="text-red-600">{error}</div>
        ) : actions.length === 0 ? (
          <EmptyState title="אין פעולות כרגע" description="המנוע האוטונומי במעקב שוטף." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-[var(--surface-muted)] text-[var(--text-mid)]">
                <tr>
                  <th className="p-3 font-semibold">ID</th>
                  <th className="p-3 font-semibold">Type</th>
                  <th className="p-3 font-semibold">Payload</th>
                  <th className="p-3 font-semibold">Status</th>
                  <th className="p-3 font-semibold">Created</th>
                  <th className="p-3 font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[var(--border)]">
                {actions.map(a => (
                  <tr key={a.action_id} className="hover:bg-[var(--surface-hover)]">
                    <td className="p-3 font-mono text-xs">{a.action_id.split('_')[1]}</td>
                    <td className="p-3 font-semibold text-[var(--text-dark)]">{a.action_type}</td>
                    <td className="p-3 font-mono text-xs max-w-xs truncate" title={a.proposed_payload}>
                      {a.proposed_payload}
                    </td>
                    <td className="p-3">
                      <Badge label={a.status} color={statusColors[a.status] || '#64748B'} />
                    </td>
                    <td className="p-3 text-[var(--text-light)]">
                      {new Date(a.created_at).toLocaleString('he-IL')}
                    </td>
                    <td className="p-3 space-x-2 space-x-reverse">
                      {a.status === 'PROPOSED' && (
                        <>
                          <button 
                            onClick={() => handleApprove(a.action_id)}
                            className="rounded bg-emerald-600 px-3 py-1 text-white hover:bg-emerald-700"
                          >
                            Approve
                          </button>
                          <button 
                            onClick={() => handleReject(a.action_id)}
                            className="rounded bg-rose-600 px-3 py-1 text-white hover:bg-rose-700"
                          >
                            Reject
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
