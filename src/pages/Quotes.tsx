import { useState, useEffect } from 'react';


export function Quotes() {
  const [quotes, setQuotes] = useState<any[]>([]);

  useEffect(() => {
    fetch('/api/quotes')
      .then(res => res.json())
      .then(data => setQuotes(data.quotes || []));
  }, []);

  return (
    <div className="p-6 rtl text-right text-gray-900">
      <h1 className="text-2xl font-bold mb-6 text-gray-800">ניהול הצעות מחיר</h1>
      
      <div className="grid grid-cols-2 gap-6">
        <div className="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
          <h2 className="text-xl mb-4 font-semibold text-gray-800">הצעות קיימות</h2>
          <div className="space-y-2">
            {quotes.map(q => (
              <div key={q.id} className="p-3 bg-gray-50 rounded-lg flex justify-between items-center border border-gray-100 hover:border-indigo-100 transition-colors">
                <div>
                  <div className="font-bold text-gray-900">{q.quote_number}</div>
                  <div className="text-sm text-gray-500">{q.client_name} - {q.total_amount} ₪</div>
                </div>
                <div>
                  <span className="px-2 py-1 text-xs rounded bg-indigo-50 text-indigo-700 ml-3 font-medium border border-indigo-100">{q.status}</span>
                  <a href={`/quotes/${q.id}/view`} target="_blank" className="text-indigo-600 hover:text-indigo-800 hover:underline text-sm font-medium">
                    תצוגה מקדימה (Live Preview)
                  </a>
                </div>
              </div>
            ))}
            {quotes.length === 0 && <div className="text-gray-500 text-center py-4">אין הצעות מחיר.</div>}
          </div>
        </div>
        
        <div className="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
          <h2 className="text-xl mb-4 font-semibold text-gray-800">Live Preview - תצוגה מקדימה</h2>
          <div className="border border-dashed border-gray-300 rounded-lg p-4 h-[400px] flex items-center justify-center bg-gray-50 text-gray-500">
            בחר הצעת מחיר לתצוגה מקדימה חיה
          </div>
        </div>
      </div>
    </div>
  );
}
