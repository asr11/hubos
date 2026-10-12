import { useEffect, useState } from 'react'
import { hubFetch } from '../lib/hubFetch'

export function TenantDashboard() {
  const [data, setData] = useState({
    moneyCollected: 0,
    moneyPending: 0,
    sovereigntyStatus: 'Active/Green',
  })
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    hubFetch('/api/dashboard')
      .then(r => r.json())
      .then(d => {
        if (d.ok && d.data) {
          setData(d.data)
        }
      })
      .catch(console.error)
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return (
      <div className="min-h-screen bg-black text-white p-8 font-mono flex items-center justify-center text-4xl" dir="rtl">
        טוען...
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-black text-white p-8 md:p-16 font-mono" dir="rtl">
      <h1 className="text-5xl font-bold mb-12 border-b-4 border-white pb-4 inline-block">לוח בקרה מרכזי</h1>
      
      <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
        
        <div className="border-4 border-white p-8 flex flex-col justify-between aspect-square hover:bg-white hover:text-black transition-colors duration-200">
          <h2 className="text-3xl font-bold uppercase tracking-widest">כסף שניצלנו</h2>
          <p className="text-6xl font-black mt-auto text-left">
            ₪{data.moneyCollected.toLocaleString()}
          </p>
        </div>

        <div className="border-4 border-white p-8 flex flex-col justify-between aspect-square hover:bg-white hover:text-black transition-colors duration-200">
          <h2 className="text-3xl font-bold uppercase tracking-widest">כסף על הרצפה</h2>
          <p className="text-6xl font-black mt-auto text-left">
            ₪{data.moneyPending.toLocaleString()}
          </p>
        </div>

        <div className="border-4 border-[#00ff00] text-[#00ff00] p-8 flex flex-col justify-between aspect-square hover:bg-[#00ff00] hover:text-black transition-colors duration-200 shadow-[0_0_20px_rgba(0,255,0,0.2)]">
          <h2 className="text-3xl font-bold uppercase tracking-widest">סטטוס ריבונות</h2>
          <p className="text-5xl font-black mt-auto text-left">
            {data.sovereigntyStatus}
          </p>
        </div>

      </div>
    </div>
  )
}
