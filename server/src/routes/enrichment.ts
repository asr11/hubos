// @ts-nocheck
import { Hono } from 'hono'
import * as net from 'net'
import * as dns from 'dns/promises'

export const enrichmentRoutes = new Hono()

// --- Helper: ISOC-IL WHOIS ---
function queryWhoisIL(domain: string): Promise<string> {
  return new Promise((resolve, reject) => {
    let data = ''
    const client = net.createConnection(43, 'whois.isoc.org.il', () => {
      client.write(`${domain}\r\n`)
    })
    client.on('data', (chunk) => {
      data += chunk.toString()
    })
    client.on('end', () => {
      resolve(data)
    })
    client.on('error', (err) => {
      reject(err)
    })
    // 5 seconds timeout
    client.setTimeout(5000, () => {
      client.destroy()
      reject(new Error('ISOC WHOIS timeout'))
    })
  })
}

// --- Helper: RDAP (Global Domains) ---
async function queryRDAP(domain: string): Promise<any> {
  try {
    const res = await fetch(`https://rdap.org/domain/${domain}`)
    if (!res.ok) return null
    return await res.json()
  } catch (e) {
    return c.json(null)
  }
}

// ----------------------------------------------------
// 1. Company Enrichment (data.gov.il)
// ----------------------------------------------------
enrichmentRoutes.get('/company', async (c: any) => {
  try {
    const q = c.c.req.query()('q')
    if (!q || q.length < 5) {
      return c.json({ error: 'Missing or short query (q)' }, 400)
    }

    // Try finding exact match by ID first, then by name
    const resourceId = 'f004176c-b85f-4542-8901-7b3176f9a054'
    const limit = 5
    const url = `https://data.gov.il/api/3/action/datastore_search?resource_id=${resourceId}&q=${encodeURIComponent(q)}&limit=${limit}`
    
    const res = await fetch(url)
    if (!res.ok) {
      return c.json({ error: 'Failed to fetch from data.gov.il' }, 502)
    }
    
    const json = (await res.json()) as any
    const records = json?.result?.records || []
    
    if (records.length === 0) {
      return c.json({ error: 'No company found' }, 404)
    }
    
    // Pick the first result
    const rec = records[0]
    
    return c.json({
      taxId: rec['מספר תאגיד'] || rec['Company_Number'],
      legalNameHe: rec['שם חברה'] || rec['Company_Name'],
      legalNameEn: rec['שם חברה באנגלית'] || rec['Company_Name_Eng'],
      type: rec['סוג תאגיד'] || rec['Company_Type'],
      status: rec['סטטוס חברה'] || rec['Company_Status'],
      isViolating: rec['חברה מפרה'] === 'כן' || rec['Company_Violating'] === 'כן',
      city: rec['עיר'] || rec['City'],
      addressLine: `${rec['שם רחוב'] || ''} ${rec['מספר בית'] || ''}`.trim(),
      zipCode: rec['מיקוד'] || rec['Zip_Code'],
      registrationDate: rec['תאריך התאגדות'] || rec['Registration_Date']
    })
  } catch (err: any) {
    console.error('Company Enrichment Error:', err)
    return c.json({ error: 'Internal Server Error' }, 500)
  }
})

// ----------------------------------------------------
// 2. Domain Enrichment (WHOIS, RDAP, DNS)
// ----------------------------------------------------
enrichmentRoutes.get('/domain', async (c: any) => {
  try {
    const domain = c.c.req.query()('domain')?.toLowerCase()
    if (!domain) {
      return c.json({ error: 'Missing domain' }, 400)
    }

    let registrar = ''
    let domainRenewalDate = ''
    
    // Check if it's an .il domain
    if (domain.endsWith('.il') || domain.endsWith('.ישראל')) {
      try {
        const whoisData = await queryWhoisIL(domain)
        // Parse "registrar name: ..."
        const regMatch = whoisData.match(/registrar name:\s*(.*)/i)
        if (regMatch) registrar = regMatch[1].trim()
        
        // Parse "validity: ..." -> DD-MM-YYYY
        const expMatch = whoisData.match(/validity:\s*(\d{2}-\d{2}-\d{4})/i)
        if (expMatch) {
          const [d, m, y] = expMatch[1].split('-')
          domainRenewalDate = `${y}-${m}-${d}` // YYYY-MM-DD
        }
      } catch (e) {
        console.error('ISOC IL WHOIS Error:', e)
      }
    } else {
      // Global domain via RDAP
      const rdap = await queryRDAP(domain)
      if (rdap) {
        // Find expiration
        const expEvent = rdap.events?.find((e: any) => e.eventAction === 'expiration')
        if (expEvent?.eventDate) {
          domainRenewalDate = expEvent.eventDate.substring(0, 10) // YYYY-MM-DD
        }
        // Find registrar (entities with role 'registrar')
        const regEntity = rdap.entities?.find((e: any) => e.roles?.includes('registrar'))
        if (regEntity?.vcardArray) {
          // vcardArray format: ["vcard", [["fn", {}, "text", "GoDaddy.com, LLC"], ...]]
          const fnField = regEntity.vcardArray[1]?.find((arr: any) => arr[0] === 'fn')
          if (fnField) registrar = fnField[3]
        }
      }
    }

    // DNS Lookup (A records for IP, NS for DNS Manager, MX for Email)
    let ipAddress = ''
    let dnsManager = ''
    
    try {
      const aRecords = await dns.resolve4(domain)
      if (aRecords.length > 0) ipAddress = aRecords[0]
      
      const nsRecords = await dns.resolveNs(domain)
      if (nsRecords.length > 0) {
        const primaryNS = nsRecords[0].toLowerCase()
        if (primaryNS.includes('cloudflare')) dnsManager = 'Cloudflare'
        else if (primaryNS.includes('awsdns')) dnsManager = 'AWS Route53'
        else if (primaryNS.includes('domaincontrol')) dnsManager = 'GoDaddy'
        else if (primaryNS.includes('googledomains')) dnsManager = 'Google Cloud DNS'
        else dnsManager = primaryNS
      }
    } catch (e) {
      console.error('DNS Lookup Error:', e)
    }

    return c.json({
      domain,
      registrar,
      domainRenewalDate,
      ipAddress,
      dnsManager,
    })
  } catch (err: any) {
    console.error('Domain Enrichment Error:', err)
    return c.json({ error: 'Internal Server Error' }, 500)
  }
})
