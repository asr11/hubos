import fs from 'fs/promises'
import { createReadStream } from 'fs'
import readline from 'readline'
import path from 'path'
import sqlite3 from 'sqlite3'
import { promisify } from 'util'

const OUT_DIR = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/golden_map'
const DB_PATH = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db'
const RAW_MORNING_DIR = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/morning/raw'

const db = new sqlite3.Database(DB_PATH)
const dbAll = promisify(db.all.bind(db))

interface GoldenEntity {
  hub_id: string
  name: string
  emails: Set<string>
  phones: Set<string>
  tax_id: string
  sources: { source: string; id: string; type: string }[]
  domains: Set<string>
  projects: Set<string>
  payments: number
  totalRevenue: number
}

function normalizePhone(p: string | null | undefined): string {
  if (!p) return ''
  return p.replace(/[\-\s\+\(\)]/g, '').replace(/^972/, '0')
}

function normalizeName(n: string | null | undefined): string {
  if (!n) return ''
  return n.trim().toLowerCase().replace(/בע"מ|בע״מ|שותפות|מוגבלת/g, '').trim()
}

async function processJsonl(filename: string, cb: (obj: any) => void) {
  try {
    const stream = createReadStream(path.join(RAW_MORNING_DIR, filename))
    const rl = readline.createInterface({ input: stream, crlfDelay: Infinity })
    for await (const line of rl) {
      if (!line.trim()) continue
      try {
        cb(JSON.parse(line))
      } catch (e) {}
    }
  } catch(e) {
    console.log(`Could not load ${filename}`)
  }
}

async function run() {
  await fs.mkdir(OUT_DIR, { recursive: true })
  
  // 1. Gather all data
  const osHubClients: any[] = await dbAll('SELECT * FROM clients')
  const osHubDomains: any[] = await dbAll('SELECT * FROM domains')
  const osHubProjects: any[] = await dbAll('SELECT * FROM projects')
  const osHubLeads: any[] = await dbAll('SELECT * FROM leads')
  
  const mClients: any[] = []
  await processJsonl('clients.jsonl', (c) => mClients.push(c))
  const mDocs: any[] = []
  await processJsonl('documents.jsonl', (d) => mDocs.push(d))

  // 2. Identity Resolution Engine
  const goldenMap = new Map<string, GoldenEntity>()
  let entityCounter = 1
  
  function getOrCreateEntity(matchFields: { email?: string, phone?: string, taxId?: string, name?: string, sourceId?: string }): GoldenEntity {
    // Try to find match
    for (const [id, entity] of goldenMap.entries()) {
      if (matchFields.email && entity.emails.has(matchFields.email)) return entity
      if (matchFields.phone && entity.phones.has(matchFields.phone)) return entity
      if (matchFields.taxId && entity.tax_id === matchFields.taxId) return entity
      if (matchFields.sourceId && entity.sources.some(s => s.id === matchFields.sourceId)) return entity
    }
    
    // Fuzzy match on name if nothing else exists
    if (matchFields.name) {
       const normName = normalizeName(matchFields.name)
       for (const [id, entity] of goldenMap.entries()) {
         if (normalizeName(entity.name) === normName) return entity
       }
    }
    
    // Create new
    const newId = `HUB_ENT_${entityCounter++}`
    const newEntity: GoldenEntity = {
      hub_id: newId,
      name: matchFields.name || 'Unknown',
      emails: new Set(matchFields.email ? [matchFields.email] : []),
      phones: new Set(matchFields.phone ? [matchFields.phone] : []),
      tax_id: matchFields.taxId || '',
      sources: [],
      domains: new Set(),
      projects: new Set(),
      payments: 0,
      totalRevenue: 0
    }
    goldenMap.set(newId, newEntity)
    return newEntity
  }
  
  // Ingest os_hub clients
  for (const c of osHubClients) {
    const e = getOrCreateEntity({ email: c.email, phone: normalizePhone(c.phone), name: c.name })
    e.sources.push({ source: 'os_hub', type: 'client', id: c.id })
    if (c.green_invoice_id) e.sources.push({ source: 'morning_linked', type: 'client', id: c.green_invoice_id })
  }
  
  // Ingest morning clients
  for (const c of mClients) {
    const emails = c.emails || []
    const phone = normalizePhone(c.phone)
    const e = getOrCreateEntity({ email: emails[0], phone, taxId: c.taxId, name: c.name, sourceId: c.id })
    e.sources.push({ source: 'morning', type: 'client', id: c.id })
    for (const em of emails) e.emails.add(em)
    if (phone) e.phones.add(phone)
    if (c.taxId) e.tax_id = c.taxId
  }
  
  // Ingest Leads
  for (const l of osHubLeads) {
    const e = getOrCreateEntity({ email: l.email, phone: normalizePhone(l.phone), name: l.name })
    e.sources.push({ source: 'os_hub', type: 'lead', id: l.id })
  }

  // Cross Link Domains (Guessing owner by domain name prefix / client name matching? We'll just link if they share exact names, but typically domains need a project link)
  // Since the DB lacks a foreign key for domain->client, we will list them separately or try fuzzy match
  let matchedDomains = 0
  for (const d of osHubDomains) {
     let matched = false
     for (const [id, e] of goldenMap.entries()) {
         if (e.emails.has(d.domain_name)) { e.domains.add(d.domain_name); matched = true; break }
     }
     // Link from morning documents descriptions
     for (const doc of mDocs) {
        if (doc.description && doc.description.includes(d.domain_name) && doc.client?.id) {
            const e = getOrCreateEntity({ sourceId: doc.client.id })
            e.domains.add(d.domain_name)
            matched = true
        }
     }
     if (matched) matchedDomains++
  }

  // Morning Payments -> Entities
  let matchedPayments = 0
  for (const doc of mDocs) {
    if ((doc.type === 305 || doc.type === 320) && doc.payment) {
        if (doc.client?.id) {
           const e = getOrCreateEntity({ sourceId: doc.client.id })
           for (const p of doc.payment) {
               e.payments++
               e.totalRevenue += p.amount
               matchedPayments++
           }
        }
    }
  }

  // Write Reports
  const entityArr = Array.from(goldenMap.values())
  const payingClients = entityArr.filter(e => e.totalRevenue > 0)
  
  const masterReport = `
# HUB GOLDEN MAP - MASTER DISCOVERY REPORT
**Date:** 2026-09-19

## 1. SOURCES DISCOVERED
- SQLite: os_hub.db (Tables: clients, leads, domains, projects, quotes, invoices, tasks, campaigns)
- Morning API: Extracted JSONL (clients, documents, expenses, recurring, retainers)

## 2. ENTITY COUNTS
- Total Unified Entities (Golden IDs): ${goldenMap.size}
- Total os_hub Clients: ${osHubClients.length}
- Total Morning Clients: ${mClients.length}
- Total Leads: ${osHubLeads.length}
- Total Domains: ${osHubDomains.length}
- Total Projects: ${osHubProjects.length}

## 3. RELATIONSHIP COUNTS
- Entities with multiple sources matched (e.g. Lead -> Client -> Morning): ${entityArr.filter(e => e.sources.length > 1).length}
- Entities with domains linked: ${entityArr.filter(e => e.domains.size > 0).length}

## 4. DOMAIN ↔ CUSTOMER MATCH RATE
- Matched Domains: ${matchedDomains} / ${osHubDomains.length}
- *Method:* Text search inside Morning document descriptions for the domain name.

## 5. CUSTOMER ↔ PROJECT MATCH RATE
- SQLite schema lacks explicit foreign key \`client_id\` on \`projects\`. Unmatched structurally.

## 6. PROJECT/ASSET ↔ REVENUE MATCH RATE
- Payments Successfully Linked to a Client: ${matchedPayments} payments.
- Total Unified Entities with Revenue: ${payingClients.length}

## 7. UNMATCHED
- ${osHubDomains.length - matchedDomains} domains are floating without a clear paying entity.
- ${osHubProjects.length} projects lack a strict DB foreign key to a client.

## 8. CONFLICTS
- Naming inconsistencies: Morning names vs os_hub names.
- Duplicate entries: Multiple Morning clients sharing the same email/phone but different IDs.

## 9. REUSABLE LOGIC FOUND
- 'os_hub' contains tables for \`ssh_checks\`, \`generic_documents\`, \`ai_agent_logs\`, \`ceo_actions\` which represent valuable structural ideas for the Sovereign Core.
- The linkage between a Domain and a Revenue stream exists purely in free-text (document descriptions in Morning).

## 10. TOP KNOWLEDGE GAPS
1. **No Hard Link between Tech and Finance:** The POS/Domain system does not store the \`Morning Client ID\`.
2. **Lead to Client Pipeline:** Leads are in \`os_hub\` but have no status machine connecting them to a won Morning client.
3. **Project Ownership:** Projects table is isolated.

---
*Generated by Digital CEO Identity Engine.*
`
  
  await fs.writeFile(path.join(OUT_DIR, 'HUB_MASTER_REPORT.md'), masterReport)
  await fs.writeFile(path.join(OUT_DIR, 'HUB_ENTITY_MAP.json'), JSON.stringify(entityArr, (key, value) => value instanceof Set ? Array.from(value) : value, 2))
  
  console.log('Golden Map Generated.')
}

run().catch(console.error)
