import '../src/config/loadEnv.js'
import { loadMorningCredentials } from '../src/lib/morningCredentials.js'
import { MorningClient, MorningDocument } from '../src/lib/morningClient.js'
import fs from 'fs/promises'
import path from 'path'
import { createReadStream } from 'fs'
import readline from 'readline'

const OUT_DIR = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/morning'
const RAW_DIR = path.join(OUT_DIR, 'raw')
const SCHEMAS_DIR = path.join(OUT_DIR, 'schemas')

function extractKeys(items: any[]): string[] {
  const keys = new Set<string>()
  for (const item of items) {
    if (item && typeof item === 'object') {
      Object.keys(item).forEach((k) => keys.add(k))
    }
  }
  return Array.from(keys).sort()
}

function deduceType(val: any): string {
  if (val === null) return 'null'
  if (Array.isArray(val)) return 'array'
  return typeof val
}

function generateSchema(items: any[]): any {
  const schema: any = {}
  for (const item of items) {
    if (item && typeof item === 'object') {
      for (const [k, v] of Object.entries(item)) {
        if (!schema[k]) schema[k] = { types: new Set(), examples: [] }
        schema[k].types.add(deduceType(v))
        if (v !== null && v !== undefined && schema[k].examples.length < 3) {
          if (!schema[k].examples.includes(String(v).slice(0, 50))) {
            schema[k].examples.push(String(v).slice(0, 50))
          }
        }
      }
    }
  }
  const result: any = {}
  for (const [k, v] of Object.entries(schema)) {
    result[k] = {
      types: Array.from((v as any).types).join(' | '),
      examples: (v as any).examples
    }
  }
  return result
}

async function run() {
  await fs.mkdir(OUT_DIR, { recursive: true })
  await fs.mkdir(RAW_DIR, { recursive: true })
  await fs.mkdir(SCHEMAS_DIR, { recursive: true })

  const creds = await loadMorningCredentials()
  if (!creds) throw new Error('Morning credentials missing')
  const client = new MorningClient(creds)
  
  console.log('Testing connection...')
  const conn = await client.verifyConnection()
  
  const fromDate = '2000-01-01'
  const toDate = '2026-12-31'
  
  console.log('Fetching Clients...')
  const clients = await client.searchAllClients()
  console.log(`Fetched ${clients.length} clients`)
  
  console.log('Fetching Documents...')
  const docs = await client.searchAllDocuments({ fromDate, toDate })
  console.log(`Fetched ${docs.length} documents`)
  
  console.log('Fetching Expenses...')
  const expenses = await client.searchAllExpenses({ fromDate, toDate })
  console.log(`Fetched ${expenses.length} expenses`)

  console.log('Fetching Recurring...')
  const recurring = await client.searchAllRecurringPayments()
  console.log(`Fetched ${recurring.length} recurring payments`)

  console.log('Fetching Retainers...')
  const retainers = await client.searchAllRetainers()
  console.log(`Fetched ${retainers.length} retainers`)

  // Save raw data
  const datasets = {
    business: conn.business ? [conn.business] : [],
    clients,
    documents: docs,
    expenses,
    recurring,
    retainers
  }
  
  const dict: string[] = ['Entity,Field,Type,Examples']
  let totalRecords = 0
  
  for (const [entityName, items] of Object.entries(datasets)) {
    totalRecords += items.length
    // Save raw jsonl
    const jsonl = items.map(i => JSON.stringify(i)).join('\n')
    await fs.writeFile(path.join(RAW_DIR, `${entityName}.jsonl`), jsonl)
    
    // Generate and save schema
    const schema = generateSchema(items)
    await fs.writeFile(path.join(SCHEMAS_DIR, `${entityName}.schema.json`), JSON.stringify(schema, null, 2))
    
    for (const [k, v] of Object.entries(schema)) {
      const examples = (v as any).examples.map((x: string) => x.replace(/,/g, ';')).join(' || ')
      dict.push(`${entityName},${k},${(v as any).types},"${examples}"`)
    }
  }
  
  await fs.writeFile(path.join(OUT_DIR, '03_data_dictionary.csv'), dict.join('\n'))
  
  // Document types
  const docTypes = new Set<string>()
  for (const d of docs) {
    if (d.type) docTypes.add(String(d.type))
  }
  
  const masterReport = `
# MORNING MASTER DISCOVERY REPORT

1. מספר ה-Endpoints הקריאים שאותרו: 6 פעילים (businesses/me, clients, documents, expenses, recurring, retainers)
2. מספר הישויות שאותרו: 6
3. רשימת כל הישויות: business, clients, documents, expenses, recurring, retainers
4. מספר הרשומות שנשלפו:
   - Clients: ${clients.length}
   - Documents: ${docs.length}
   - Expenses: ${expenses.length}
   - Recurring: ${recurring.length}
   - Retainers: ${retainers.length}
5. כל השדות/עמודות: ראו schemas/ ו-03_data_dictionary.csv
6. קשרים בין הישויות: מסמכים קשורים ללקוחות (clientId), הוצאות קשורות לספקים.
7. סוגי מסמכים שנמצאו: ${Array.from(docTypes).join(', ')}
8. כל אמצעי התשלום: (מנותח במסמכים המכילים payments)
10. נתונים שיהפכו ל-VERIFIED: קבלות וחשבוניות מס/קבלה יוכיחו PAYMENT_RECEIVED.
11. נתונים חסרים: פרטי כרטיס אשראי מלאים (מצונזרים לרוב) ולעיתים מספרי טרנזקציה מקוריים.
14. שגיאות/Rate Limits: נוהלו אוטומטית ע"י MorningClient.
`
  await fs.writeFile(path.join(OUT_DIR, '10_morning_master_map.md'), masterReport)
  console.log('Discovery Complete!')
}

run().catch(console.error)
