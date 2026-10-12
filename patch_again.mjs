import fs from 'fs';
let code = fs.readFileSync('server/src/lib/db.ts', 'utf8');

// replace demo users seeded
code = code.replace(/console\.log\('Super Admin and demo users seeded\.'\)\s+seedMockUsers\(\)/g, "console.log('Database initialized.')");
code = code.replace(/export function seedMockUsers\(\) \{[\s\S]+$/, "");

fs.writeFileSync('server/src/lib/db.ts', code);
