// @ts-nocheck
import crypto from 'crypto'
import 'dotenv/config'

const ALGORITHM = 'aes-256-cbc'
const IV_LENGTH = 16

const keyString = process.env.VAULT_ENCRYPTION_KEY

function getKey(): Buffer {
  if (!keyString || keyString.length !== 64) {
    throw new Error('VAULT_ENCRYPTION_KEY is not set or invalid length (expected 64 hex chars)')
  }
  return Buffer.from(keyString, 'hex')
}

export function encrypt(text: string): string {
  if (!text) return ''
  const iv = crypto.randomBytes(IV_LENGTH)
  const cipher = crypto.createCipheriv(ALGORITHM, getKey(), iv)
  let encrypted = cipher.update(text)
  encrypted = Buffer.concat([encrypted, cipher.final()])
  return iv.toString('hex') + ':' + encrypted.toString('hex')
}

export function decrypt(text: string): string {
  if (!text) return ''
  const textParts = text.split(':')
  if (textParts.length !== 2) return text // If not encrypted format, return as is (legacy support or plain text)
  const iv = Buffer.from(textParts[0], 'hex')
  const encryptedText = Buffer.from(textParts[1], 'hex')
  const decipher = crypto.createDecipheriv(ALGORITHM, getKey(), iv)
  let decrypted = decipher.update(encryptedText)
  decrypted = Buffer.concat([decrypted, decipher.final()])
  return decrypted.toString()
}
