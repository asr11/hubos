import { db, logSystemEvent } from './db.js'

export function createQuoteRecord(clientId?: string | null, totalAmount: number = 0) {
  const id = `q_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`
  const quoteNumber = `QT-${Date.now().toString().slice(-6)}`

  let validClientId: string | null = null
  if (clientId && typeof clientId === 'string' && clientId.trim()) {
    const trimmed = clientId.trim()
    const found = db.prepare('SELECT id FROM clients WHERE id = ?').get(trimmed)
    if (found) {
      validClientId = trimmed
    } else {
      // Check if client exists in generic_documents and sync to relational table
      const genRow = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ? AND id = ?').get('clients', trimmed) as { id: string; data: string } | undefined
      if (genRow) {
        try {
          const d = JSON.parse(genRow.data)
          db.prepare(`
            INSERT OR IGNORE INTO clients (id, green_invoice_id, name, tax_id, email, phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
          `).run(genRow.id, d.greenInvoiceId || genRow.id, d.name || 'לקוח', d.taxId || null, d.email || null, d.phone || null, 'active')
          validClientId = genRow.id
        } catch {
          validClientId = null
        }
      }
    }
  }

  const stmt = db.prepare(`
    INSERT INTO quotes (id, quote_number, client_id, total_amount, status)
    VALUES (?, ?, ?, ?, 'draft')
  `)
  stmt.run(id, quoteNumber, validClientId, totalAmount)

  logSystemEvent('QUOTE_CREATE', quoteNumber, `Created quote ${quoteNumber} for amount ${totalAmount}`)
  return { ok: true, id, quoteNumber, totalAmount, status: 'draft' }
}

export function signQuoteRecord(quoteId: string, signatureData: string) {
  const stmt = db.prepare(`
    UPDATE quotes
    SET status = 'signed',
        signed_at = CURRENT_TIMESTAMP,
        signature_data = ?
    WHERE id = ? OR quote_number = ?
  `)
  const result = stmt.run(signatureData, quoteId, quoteId)

  if (result.changes === 0) {
    throw new Error(`Quote ${quoteId} not found`)
  }

  logSystemEvent('QUOTE_SIGNED', quoteId, `Quote ${quoteId} digitally signed successfully`)
  return { ok: true, quoteId, status: 'signed' }
}

export function getQuoteRecord(quoteId: string) {
  const stmt = db.prepare(`
    SELECT q.*, c.name as client_name, c.email as client_email
    FROM quotes q
    LEFT JOIN clients c ON q.client_id = c.id
    WHERE q.id = ? OR q.quote_number = ?
  `)
  return stmt.get(quoteId, quoteId)
}
