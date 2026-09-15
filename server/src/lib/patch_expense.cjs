const fs = require('fs')
const path = require('path')

const file = path.join(__dirname, 'morningExpenseSync.ts')
let content = fs.readFileSync(file, 'utf8')

// 1. replace imports
content = content.replace(
  "const getAdminDb = (): any => ({} as any)",
  "import { db } from './db.js'"
)

// 2. mapStoredExpenseDoc
content = content.replace(
  /function mapStoredExpenseDoc\(d: QueryDocumentSnapshot\): StoredExpense {/g,
  `function mapStoredExpenseDoc(d: { id: string, data: () => any }): StoredExpense {`
)

// 3. loadStoredExpenses
content = content.replace(
  /async function loadStoredExpenses\(db: Firestore\): Promise<StoredExpense\[\]> {[\s\S]*?return all\n}/,
  `async function loadStoredExpenses(): Promise<StoredExpense[]> {
  const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
  const rows = stmt.all(EXPENSES_COLLECTION) as { id: string; data: string }[]
  return rows.map(r => {
    const doc = { id: r.id, data: () => JSON.parse(r.data) }
    return mapStoredExpenseDoc(doc)
  })
}`
)

// 4. persistExpenseReconciliation
content = content.replace(
  /async function persistExpenseReconciliation\([\s\S]*?\): Promise<\{ matched: number; bankMissingDocument: number; giUnpaid: number \}> {/g,
  `async function persistExpenseReconciliation(expenses: StoredExpense[]): Promise<{ matched: number; bankMissingDocument: number; giUnpaid: number }> {`
)
// remove FieldValue.delete() logic and replace with null
content = content.replace(/FieldValue\.delete\(\)/g, "null")

// rewrite the batch logic
content = content.replace(
  /for \(let i = 0; i < updates\.length; i \+\= 400\) {[\s\S]*?await batch\.commit\(\)\n  }/,
  `const insertStmt = db.prepare(\`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    \`)
    const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    
    db.transaction(() => {
      for (const { id, patch } of updates) {
        const rowData = getStmt.get(EXPENSES_COLLECTION, id) as { data: string } | undefined
        const existingData = rowData ? JSON.parse(rowData.data) : {}
        const mergedData = { ...existingData, ...patch }
        // remove null fields
        for (const k of Object.keys(mergedData)) {
          if (mergedData[k] === null && patch[k] === null) delete mergedData[k]
        }
        insertStmt.run(id, EXPENSES_COLLECTION, JSON.stringify(mergedData))
      }
    })();`
)


// 5. runExpenseReconcile
content = content.replace(
  /const db = getAdminDb\(\)\n  if \(\!db\) throw new Error\('Firebase Admin not configured'\)\n  const expenses = await loadStoredExpenses\(db\)\n  const result = await persistExpenseReconciliation\(db, expenses\)/,
  `const expenses = await loadStoredExpenses()
  const result = await persistExpenseReconciliation(expenses)`
)

fs.writeFileSync(file, content)
