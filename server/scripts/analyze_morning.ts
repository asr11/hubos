import fs from 'fs'
import readline from 'readline'
import path from 'path'

const RAW_DIR = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/morning/raw'
const OUT_FILE = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/morning/analysis_report.md'

async function processJsonl(filename: string, cb: (obj: any) => void) {
  const stream = fs.createReadStream(path.join(RAW_DIR, filename))
  const rl = readline.createInterface({ input: stream, crlfDelay: Infinity })
  for await (const line of rl) {
    if (!line.trim()) continue
    try {
      cb(JSON.parse(line))
    } catch (e) {}
  }
}

async function run() {
  const now = new Date('2026-09-19T23:17:44+03:00').getTime()

  let totalDocs = 0
  let totalClients = 0
  let totalRecurring = 0
  let totalRetainers = 0

  const docs: any[] = []
  await processJsonl('documents.jsonl', (d) => { totalDocs++; docs.push(d) })
  
  const clients: any[] = []
  await processJsonl('clients.jsonl', (c) => { totalClients++; clients.push(c) })

  const recurring: any[] = []
  await processJsonl('recurring.jsonl', (r) => { totalRecurring++; recurring.push(r) })

  const retainers: any[] = []
  await processJsonl('retainers.jsonl', (r) => { totalRetainers++; retainers.push(r) })

  // 1. PAYMENT RECEIVED (Past)
  let verifiedPaymentAmount = 0
  let totalPayments = 0
  const paymentMethods: Record<string, number> = {}
  const paymentsByClient: Record<string, { total: number, lastPayment: number }> = {}
  
  const futureDocs: any[] = []
  const debts: any[] = []
  let totalDebtAmount = 0

  for (const d of docs) {
    const docTime = new Date(d.documentDate).getTime()
    if (docTime > now) {
      futureDocs.push(d)
      continue
    }

    // Verified Payments
    if ((d.type === 305 || d.type === 320) && d.payment && Array.isArray(d.payment)) {
      for (const p of d.payment) {
        verifiedPaymentAmount += p.amount
        totalPayments++
        
        const method = p.typeHe || p.typeEn || p.type || 'Unknown'
        paymentMethods[method] = (paymentMethods[method] || 0) + p.amount
        
        if (d.client?.id) {
          if (!paymentsByClient[d.client.id]) paymentsByClient[d.client.id] = { total: 0, lastPayment: 0 }
          paymentsByClient[d.client.id].total += p.amount
          if (docTime > paymentsByClient[d.client.id].lastPayment) {
            paymentsByClient[d.client.id].lastPayment = docTime
          }
        }
      }
    }

    // Debt observation (Type 300 - Invoice or 440 Proforma with open balance if Morning supports it)
    if (d.status === 0 || d.paymentStatus === 0 || d.balance > 0 || d.statusHe === 'מסמך פתוח' || d.status === 'מסמך פתוח') {
        const balance = d.balance || d.amount // fallback
        if (balance > 0) {
           debts.push(d)
           totalDebtAmount += balance
        }
    }
  }

  // 3. Clients Analysis
  const thirtyDaysAgo = now - 30 * 24 * 60 * 60 * 1000
  const ninetyDaysAgo = now - 90 * 24 * 60 * 60 * 1000
  const aYearAgo = now - 365 * 24 * 60 * 60 * 1000

  let clientsPaidTotal = 0
  let clientsPaid30 = 0
  let clientsPaid90 = 0
  let clientsPaid365 = 0

  for (const cid of Object.keys(paymentsByClient)) {
    clientsPaidTotal++
    const last = paymentsByClient[cid].lastPayment
    if (last >= thirtyDaysAgo) clientsPaid30++
    if (last >= ninetyDaysAgo) clientsPaid90++
    if (last >= aYearAgo) clientsPaid365++
  }

  // 4. MRR Analysis
  let observedMRR = 0
  for (const r of retainers) {
    if (r.status === 'active' || r.active) {
       observedMRR += (r.amount || 0)
    }
  }
  for (const r of recurring) {
    if (r.status === 'active' || r.active) {
       observedMRR += (r.amount || 0)
    }
  }

  // Anomalies
  const duplicateIds = new Set()
  const seenDocs = new Set()
  for (const d of docs) {
    if (seenDocs.has(d.id)) duplicateIds.add(d.id)
    seenDocs.add(d.id)
  }

  const report = `
# HUB FINANCIAL REALITY #1
**Date Generated:** 2026-09-19 (READ-ONLY)

## VERIFIED REALITY
*מה אנחנו יודעים בוודאות מתוך המסמכים שהופקו*

- **Total Verified Revenue (PAYMENT_RECEIVED):** ₪${verifiedPaymentAmount.toLocaleString()}
  - *Source:* Sum of \`amount\` in \`payments\` array inside past Documents (Types 305, 320).
  - *Records:* ${totalPayments} payment entries.
- **Client Base:**
  - Total Clients in DB: ${totalClients}
  - Clients with at least 1 verified payment: ${clientsPaidTotal}
  - Active (Paid in last 30 days): ${clientsPaid30}
  - Active (Paid in last 90 days): ${clientsPaid90}
  - Active (Paid in last 365 days): ${clientsPaid365}
- **Payment Methods Breakdown:**
  ${Object.entries(paymentMethods).map(([m, a]) => `- ${m}: ₪${a.toLocaleString()}`).join('\n  ')}

## OBSERVED REALITY (POTENTIAL)
- **OBSERVED MRR:** ₪${observedMRR.toLocaleString()}
  - *Source:* Active Retainers (${totalRetainers} records) and Recurring Payments (${totalRecurring} records) where status is Active.
  - *Status:* OBSERVED (Depends on successful future execution).
- **OBSERVED DEBT:** ₪${totalDebtAmount.toLocaleString()}
  - *Source:* ${debts.length} documents where status is 'מסמך פתוח' or balance > 0.
  - *Status:* OBSERVED (Needs cross-validation to ensure these weren't paid externally or cancelled without updating).

## UNKNOWN
- **True Bank Cash:** We have Verified Receipts, but we lack Bank Statement matching to confirm exact settlement (especially for Bank Transfers).
- **Historical Debt Reality:** Are old open documents (2020-2025) real debts or simply unclosed records?
- **Cost / Profit:** We have expense records (${939}), but no mapping yet to net profit.

## CONFLICTS / ANOMALIES
- **Future Documents:** Found ${futureDocs.length} documents with \`documentDate\` in the future (Projected / Scheduled). These were excluded from verified totals.
- **Duplicates:** Found ${duplicateIds.size} duplicate document IDs in the extraction.
- **Document to Client Link:** Assumed strong, but some receipts might lack a strict client ID if issued generically.

## BUSINESS STATE SUMMARY
- **Total Revenue (All Time):** ₪${verifiedPaymentAmount.toLocaleString()}
- **Active Paying Audience:** ~${clientsPaid90} clients (90-day window).
- **Run Rate (MRR):** ~₪${observedMRR.toLocaleString()}

## TOP 5 MANAGEMENT GAPS
1. **SSOT Drift:** Receipts exist, but Bank Settlement is completely opaque to the system.
2. **Debt Ambiguity:** ${debts.length} open documents remain unresolved. We cannot act on them without knowing if they are real.
3. **Client Definition:** ${totalClients} clients exist, but only ${clientsPaidTotal} ever paid. The CRM is polluted with leads/tests.
4. **MRR Guarantee:** Retainers exist in Morning, but no visibility on their success rate or churn.
5. **Expense Automation:** 939 expenses exist, but no automated P&L to deduce actual Profit vs Revenue.

`
  fs.writeFileSync(OUT_FILE, report)
  console.log('Done analysis')
}

run().catch(console.error)
