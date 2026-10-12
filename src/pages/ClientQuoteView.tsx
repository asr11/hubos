import { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

export function ClientQuoteView() {
  const { id } = useParams();
  const [quote, setQuote] = useState<any>(null);
  const [signature, setSignature] = useState('');
  const [signing, setSigning] = useState(false);

  useEffect(() => {
    fetch(`/api/quotes/${id}`)
      .then(res => res.json())
      .then(data => {
        if (data.ok) setQuote(data.quote);
      });
  }, [id]);

  const handleSign = async () => {
    if (!signature) return;
    setSigning(true);
    try {
      const res = await fetch(`/api/quotes/${id}/sign`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ signatureData: signature })
      });
      const data = await res.json();
      if (data.ok) {
        setQuote({ ...quote, status: 'signed', signature_data: signature });
      }
    } finally {
      setSigning(false);
    }
  };

  if (!quote) return <div className="p-8 text-center text-gray-900">טוען...</div>;

  return (
    <div className="min-h-screen bg-gray-50 text-gray-900 p-6 rtl text-right flex justify-center">
      <div className="w-full max-w-2xl bg-white rounded-2xl border border-gray-200 p-8 shadow-xl">
        <div className="flex justify-between items-center mb-8 border-b border-gray-200 pb-4">
          <h1 className="text-3xl font-bold text-indigo-600">הצעת מחיר {quote.quote_number}</h1>
          <div className="text-gray-500">
            עבור: {quote.client_name || 'לקוח'}
          </div>
        </div>

        <div className="mb-8">
          <h2 className="text-xl mb-4 border-b border-gray-100 pb-2">פירוט</h2>
          <table className="w-full text-left bg-gray-50 rounded-lg overflow-hidden border border-gray-200">
            <thead className="bg-gray-100 text-right text-gray-600">
              <tr>
                <th className="p-3">תיאור</th>
                <th className="p-3">כמות</th>
                <th className="p-3">מחיר יחידה</th>
                <th className="p-3">סה"כ</th>
              </tr>
            </thead>
            <tbody className="text-right">
              {(quote.items || []).map((item: any, i: number) => (
                <tr key={i} className="border-t border-gray-200">
                  <td className="p-3">{item.description}</td>
                  <td className="p-3">{item.quantity}</td>
                  <td className="p-3">{item.unit_price} ₪</td>
                  <td className="p-3 font-bold">{item.total_price} ₪</td>
                </tr>
              ))}
              {(!quote.items || quote.items.length === 0) && (
                <tr><td colSpan={4} className="p-3 text-center text-gray-500">אין פריטים</td></tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="flex justify-between items-center mb-8 bg-indigo-50 p-4 rounded-xl border border-indigo-100">
          <span className="text-xl font-medium text-indigo-900">סה"כ לתשלום:</span>
          <span className="text-3xl font-bold text-indigo-600">{quote.total_amount} ₪</span>
        </div>

        {quote.status === 'signed' ? (
          <div className="bg-green-50 text-green-700 p-6 rounded-xl text-center border border-green-200">
            <h3 className="text-2xl font-bold mb-2">ההצעה חתומה ומאושרת</h3>
            <p>חתימה: {quote.signature_data}</p>
          </div>
        ) : (
          <div className="bg-gray-50 p-6 rounded-xl border border-gray-200">
            <h3 className="text-xl mb-4 font-medium">אישור וחתימה</h3>
            <input 
              type="text" 
              placeholder="הקלד שם מלא לחתימה" 
              className="w-full bg-white border border-gray-300 rounded-lg p-3 text-gray-900 mb-4 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none transition-shadow"
              value={signature}
              onChange={e => setSignature(e.target.value)}
            />
            <button 
              onClick={handleSign}
              disabled={!signature || signing}
              className="w-full bg-indigo-600 text-white p-3 rounded-lg font-bold hover:bg-indigo-700 disabled:opacity-50 transition-colors"
            >
              {signing ? 'חותם...' : 'אשר וחתום'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
