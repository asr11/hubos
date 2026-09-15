const fs = require('fs')
const path = require('path')

const file = path.join(__dirname, 'morningSync.ts')
let content = fs.readFileSync(file, 'utf8')

// 1. replace imports
content = content.replace(
  "import { env } from '../config/env.js'\nconst getAdminDb = (): any => ({} as any)",
  "import { env } from '../config/env.js'\nimport { db } from './db.js'"
)

// 2. buildClientIndex
content = content.replace(
  /async function buildClientIndex[\s\S]*?return \{ byGreenInvoiceId, byTaxId, byEmail, byName \}\n\}/,
  `async function buildClientIndex(): Promise<ClientIndex> {
  const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
  const rows = stmt.all(CLIENTS_COLLECTION) as { id: string; data: string }[]
  const byGreenInvoiceId = new Map<string, string>()
  const byTaxId = new Map<string, string>()
  const byEmail = new Map<string, string>()
  const byName = new Map<string, string>()
  for (const row of rows) {
    const docId = row.id
    const d = JSON.parse(row.data)
    const giId = String(d.greenInvoiceId ?? '').trim()
    if (giId) byGreenInvoiceId.set(giId, docId)
    const taxId = normalizeTaxId(
      String(d.taxId ?? d.companyId ?? d.businessId ?? d.vatId ?? ''),
    )
    if (taxId) byTaxId.set(taxId, docId)
    const email = String(d.email ?? '').trim().toLowerCase()
    if (email) byEmail.set(email, docId)
    const name = normalizeName(String(d.name ?? d.companyName ?? ''))
    if (name) byName.set(name, docId)
  }
  return { byGreenInvoiceId, byTaxId, byEmail, byName }
}`
)

// 3. syncClients
content = content.replace(
  /const db = getAdminDb\(\)\n  if \(\!db\) throw new Error\('Firebase Admin not configured'\)\n/,
  ""
)
content = content.replace(
  /const index = await buildClientIndex\(db\)/,
  "const index = await buildClientIndex()"
)
// 3.b batch logic in syncClients
content = content.replace(
  /const batch = db\.batch\(\)\n    for \(const m of slice\) {/g,
  `const insertStmt = db.prepare(\`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    \`)
    const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    
    db.transaction(() => {
      for (const m of slice) {`
)
content = content.replace(
  /batch\.set\(db\.collection\(CLIENTS_COLLECTION\)\.doc\(existingId\), patch, \{[\s\S]*?merge: true,[\s\S]*?\}\)/g,
  `const rowData = getStmt.get(CLIENTS_COLLECTION, existingId) as { data: string } | undefined
        const existingData = rowData ? JSON.parse(rowData.data) : {}
        const mergedData = { ...existingData, ...patch }
        insertStmt.run(existingId, CLIENTS_COLLECTION, JSON.stringify(mergedData))`
)
content = content.replace(
  /const ref = db[\s\S]*?\.collection\(CLIENTS_COLLECTION\)[\s\S]*?\.doc\(GI_DOC_PREFIX \+ m\.greenInvoiceId\)[\s\S]*?claimed\.add\(ref\.id\)/g,
  `const newId = GI_DOC_PREFIX + m.greenInvoiceId
        claimed.add(newId)`
)
content = content.replace(
  /batch\.set\(ref, row, \{ merge: true \}\)/g,
  `const rowData2 = getStmt.get(CLIENTS_COLLECTION, newId) as { data: string } | undefined
        const existingData2 = rowData2 ? JSON.parse(rowData2.data) : {}
        const mergedData2 = { ...existingData2, ...row }
        insertStmt.run(newId, CLIENTS_COLLECTION, JSON.stringify(mergedData2))`
)
content = content.replace(
  /await batch\.commit\(\)/g,
  `})();`
)

// 4. runMorningSync
content = content.replace(
  /const index = await buildClientIndex\(db\)/g,
  "const index = await buildClientIndex()"
)
content = content.replace(
  /const clientIndex = await buildClientIndex\(db\)/g,
  "const clientIndex = await buildClientIndex()"
)

// In runMorningSync, rewrite the batch logic:
content = content.replace(
  /const batch = db\.batch\(\)\n    for \(const m of slice\) {/g,
  `const insertStmt = db.prepare(\`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    \`)
    const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    const deleteStmt = db.prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?')
    
    db.transaction(() => {
      for (const m of slice) {`
)

content = content.replace(
  /const ref = db\.collection\(INCOMES_COLLECTION\)\.doc\(GI_DOC_PREFIX \+ m\.greenInvoiceId\)\n      if \(m\.cancelled\) {\n        batch\.delete\(ref\)\n        removed \+\= 1\n        continue\n      }/g,
  `const newId = GI_DOC_PREFIX + m.greenInvoiceId
      if (m.cancelled) {
        deleteStmt.run(INCOMES_COLLECTION, newId)
        removed += 1
        continue
      }`
)

content = content.replace(
  /row\.paidAmount = FieldValue\.delete\(\)/g,
  `row.paidAmount = null`
)

content = content.replace(
  /\/\/ merge: שומר createdAt מקורי אם כבר קיים\n      batch\.set\(ref, row, \{ merge: true \}\)/g,
  `// merge: שומר createdAt מקורי אם כבר קיים
      const rowData = getStmt.get(INCOMES_COLLECTION, newId) as { data: string } | undefined
      const existingData = rowData ? JSON.parse(rowData.data) : {}
      const mergedData = { ...existingData, ...row }
      // remove deleted fields
      for (const k of Object.keys(mergedData)) {
        if (mergedData[k] === null && row[k] === null) delete mergedData[k]
      }
      insertStmt.run(newId, INCOMES_COLLECTION, JSON.stringify(mergedData))`
)


// 5. resetAllIncomes
content = content.replace(
  /async function resetAllIncomes\(\): Promise<number> {[\s\S]*?return refs\.length\n}/,
  `export async function resetAllIncomes(): Promise<number> {
  const getStmt = db.prepare('SELECT id FROM generic_documents WHERE collection = ?')
  const ids = getStmt.all(INCOMES_COLLECTION) as { id: string }[]
  const delStmt = db.prepare('DELETE FROM generic_documents WHERE collection = ?')
  delStmt.run(INCOMES_COLLECTION)
  return ids.length
}`
)

fs.writeFileSync(file, content)
