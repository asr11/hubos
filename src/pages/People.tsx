import { useState, useEffect } from 'react'
import { hubFetch } from '../lib/hubFetch'

export function People() {
  const [people, setPeople] = useState<any[]>([])
  const [candidatesCount, setCandidatesCount] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    fetchPeople()
    fetchCandidates()
  }, [])

  const fetchPeople = async () => {
    try {
      const res = await hubFetch('/api/people')
      const data = await res.json()
      if (data.ok) setPeople(data.people)
    } catch (err: any) {
      setError((err as any).message)
    } finally {
      setLoading(false)
    }
  }

  const fetchCandidates = async () => {
    try {
      const res = await hubFetch('/api/people/candidates')
      const data = await res.json()
      if (data.ok) setCandidatesCount(data.candidateCount)
    } catch (err) {}
  }

  const handleDelete = async (id: string) => {
    if (!confirm('למחוק איש קשר זה?')) return
    try {
      await hubFetch('/api/people/' + id, {
        method: 'DELETE'
      })
      setPeople(people.filter(p => p.id !== id))
    } catch (err) {}
  }

  const handleCreate = async () => {
    const fullName = prompt('שם מלא:')
    if (!fullName) return
    const phone = prompt('טלפון:')
    if (!phone) return
    try {
      const res = await hubFetch('/api/people', {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ full_name: fullName, phone })
      })
      const data = await res.json()
      if (data.ok) fetchPeople()
      else alert('שגיאה: ' + data.error)
    } catch (err) {}
  }

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">אנשים (Digital CEO Identity)</h1>
        <button onClick={handleCreate} className="bg-blue-600 text-white px-4 py-2 rounded">הוסף איש קשר</button>
      </div>

      <div className="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
        <p className="text-sm text-blue-700">ישנם {candidatesCount} מועמדים (לקוחות עם טלפון) שניתן לייבא לטבלת People.</p>
      </div>

      {error && <div className="text-red-500 mb-4">{error}</div>}
      {loading ? <div>טוען...</div> : (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">שם</th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">טלפון</th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">טלגרם</th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">תפקיד</th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">פעולות</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {people.map(p => (
                <tr key={p.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{p.full_name}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500" dir="ltr">{p.phone}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{p.telegram_user_id}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{p.role}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <button onClick={() => handleDelete(p.id)} className="text-red-600 hover:text-red-900">מחק</button>
                  </td>
                </tr>
              ))}
              {people.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-4 text-center text-sm text-gray-500">אין רשומות</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
