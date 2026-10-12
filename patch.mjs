import fs from 'fs';
let code = fs.readFileSync('server/src/lib/db.ts', 'utf8');

// 1. Add people table
code = code.replace(
`    );
  \`)`,
`    );
    -- 17. People
    CREATE TABLE IF NOT EXISTS people (
      id TEXT PRIMARY KEY,
      full_name TEXT NOT NULL,
      phone TEXT NOT NULL,
      created_at TEXT NOT NULL,
      updated_at TEXT,
      telegram_user_id TEXT UNIQUE,
      primary_email TEXT,
      role TEXT CHECK(role IN ('lead','partner','client_contact','admin')),
      referred_by_partner_id TEXT REFERENCES people(id),
      morning_client_id TEXT,
      client_id TEXT,
      user_id TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_people_phone ON people(phone);
    CREATE INDEX IF NOT EXISTS idx_people_morning_client_id ON people(morning_client_id);
    CREATE INDEX IF NOT EXISTS idx_people_client_id ON people(client_id);
  \`)`
);

// 2. Remove seedMockUsers call and its definition
code = code.replace(/  console\.log\('Super Admin and demo users seeded\.'\)\n  seedMockUsers\(\)/, "  console.log('Database initialized.')");
code = code.replace(/export function seedMockUsers\(\) \{[\s\S]+$/, "");

fs.writeFileSync('server/src/lib/db.ts', code);
