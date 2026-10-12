const db = require('better-sqlite3')('../data/os_hub.db')
const bcrypt = require('bcryptjs')
const crypto = require('crypto')

const email = process.argv[2]
const password = process.argv[3]
const role = process.argv[4] || 'employee'
const name = process.argv[5] || email.split('@')[0]

if (!email || !password) {
  console.error('Usage: node create_user.js <email> <password> [role] [name]')
  process.exit(1)
}

const hash = bcrypt.hashSync(password, 10)
const id = 'user_' + crypto.randomBytes(4).toString('hex')

try {
  db.prepare('INSERT INTO users (id, email, password_hash, role) VALUES (?, ?, ?, ?)').run(id, email, hash, role)
  console.log(`User created successfully. ID: ${id}, Email: ${email}, Role: ${role}`)
} catch (err) {
  if (err.message.includes('UNIQUE constraint failed')) {
    console.log('User already exists, updating password and role...')
    db.prepare('UPDATE users SET password_hash = ?, role = ? WHERE email = ?').run(hash, role, email)
    console.log('User updated successfully.')
  } else {
    console.error('Error creating user:', err.message)
  }
}
