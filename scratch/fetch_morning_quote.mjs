import fs from 'fs';
const envText = fs.readFileSync('/home/asr11/ASHER/os.hub.co.il/.env', 'utf8');
const env = Object.fromEntries(envText.split(/\r?\n/).filter(l => l && !l.startsWith('#')).map(l => {
  const [k, ...v] = l.split('=');
  return [k ? k.trim() : '', v ? v.join('=').trim().replace(/^"|"$/g, '') : ''];
}));

const authRes = await fetch('https://api.morning.co/idp/v1/oauth/token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    grant_type: 'client_credentials',
    client_id: env.MORNING_KEY_ID,
    client_secret: env.MORNING_KEY_SECRET,
  }),
});
const { accessToken } = await authRes.json();

const res = await fetch('https://api.greeninvoice.co.il/api/v1/documents/search', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${accessToken}`,
  },
  body: JSON.stringify({
    fromDate: '2025-01-01',
    toDate: '2026-10-06',
    type: [10],
    pageSize: 100,
  }),
});
const data = await res.json();
const quotes = (data.items || []).filter(d => 
  JSON.stringify(d).includes('אוונגרד') || 
  JSON.stringify(d).includes('av-re') || 
  JSON.stringify(d).includes('מנו') || 
  JSON.stringify(d).includes('כפיר') ||
  d.client?.id === 'c32e6cf6-4344-477f-a1f6-e852de8bbfc9'
);
console.log(JSON.stringify(quotes, null, 2));
