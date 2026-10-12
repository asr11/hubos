// @ts-nocheck
import net from 'node:net'
import { db, logSystemEvent } from './db.js'

export async function checkSshConnectivity(domainName: string, host: string, port = 22): Promise<{ ok: boolean; message: string }> {
  return new Promise((resolve) => {
    const socket = new net.Socket()
    socket.setTimeout(4000)

    socket.on('connect', () => {
      socket.destroy()
      const stmt = db.prepare(`
        INSERT INTO ssh_checks (id, domain_name, host, port, status, error_log)
        VALUES (?, ?, ?, ?, 'ok', NULL)
      `)
      const id = `ssh_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`
      stmt.run(id, domainName, host, port)
      logSystemEvent('SSH_CHECK', domainName, `SSH connection successful to ${host}:${port}`)
      resolve({ ok: true, message: 'SSH Connection OK' })
    })

    const handleFail = (errMessage: string) => {
      socket.destroy()
      const stmt = db.prepare(`
        INSERT INTO ssh_checks (id, domain_name, host, port, status, error_log)
        VALUES (?, ?, ?, ?, 'error', ?)
      `)
      const id = `ssh_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`
      stmt.run(id, domainName, host, port, errMessage)
      logSystemEvent('SSH_CHECK_FAIL', domainName, errMessage)
      resolve({ ok: false, message: errMessage })
    }

    socket.on('timeout', () => handleFail('SSH connection timed out (4s)'))
    socket.on('error', (err) => handleFail(err.message))
    socket.connect(port, host)
  })
}

export function registerDomainRecord(domainName: string, registrar?: string, expiryDate?: string) {
  const id = `dom_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`
  const stmt = db.prepare(`
    INSERT INTO domains (id, domain_name, registrar, expiry_date, is_active, status)
    VALUES (?, ?, ?, ?, 1, 'ok')
    ON CONFLICT(domain_name) DO UPDATE SET
      registrar = excluded.registrar,
      expiry_date = excluded.expiry_date,
      updated_at = CURRENT_TIMESTAMP
  `)
  stmt.run(id, domainName, registrar ?? null, expiryDate ?? null)
  logSystemEvent('DOMAIN_REGISTER', domainName, `Registered domain ${domainName}`)
  return { ok: true, id, domainName }
}
