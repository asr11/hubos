const fs = require('fs');
const path = require('path');

const stubCode = `
// Firebase eradicated
const db = {} as any;
const auth = {} as any;
const functionsClient = {} as any;
const storage = {} as any;
const httpsCallable = (() => async (data?: any) => ({ data: {} })) as any;
const doc = (() => ({})) as any;
const getDoc = (async () => ({})) as any;
const updateDoc = (async () => {}) as any;
const deleteField = (() => null) as any;
const collection = (() => ({})) as any;
const addDoc = (async () => ({})) as any;
const setDoc = (async () => {}) as any;
const getDocs = (async () => ({ docs: [] })) as any;
const deleteDoc = (async () => {}) as any;
const writeBatch = (() => ({ commit: async () => {}, set: () => {}, update: () => {}, delete: () => {} })) as any;
const serverTimestamp = (() => new Date().toISOString()) as any;
const query = (() => ({})) as any;
const limit = (() => ({})) as any;
const where = (() => ({})) as any;
const getDownloadURL = (async () => '') as any;
const uploadBytesResumable = (() => ({ on: (a: any, b: any, c: any, d: any) => { if(d) d(); }, snapshot: { ref: {} } })) as any;
const ref = (() => ({})) as any;
type UploadTask = any;
type Firestore = any;
class FirebaseError extends Error { code: string = ''; }
const Timestamp = { fromDate: (d: Date) => ({ toDate: () => d }), now: () => ({ toDate: () => new Date() }) } as any;
class FieldValue {}
`;

function processFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf-8');
  const original = content;

  let hasFirebase = false;

  // Remove import statements matching firebase
  content = content.replace(/import\s+type\s+[^;]*?from\s+['"]firebase\/[^'"]+['"];?/gs, () => { hasFirebase = true; return ''; });
  content = content.replace(/import\s+[^;]*?from\s+['"]firebase\/[^'"]+['"];?/gs, () => { hasFirebase = true; return ''; });
  content = content.replace(/import\s+[^;]*?from\s+['"]\.\.?\/(?:lib\/)?firebase['"];?/gs, () => { hasFirebase = true; return ''; });

  if (hasFirebase) {
    // If the file uses hubFetch we don't need to inject stubs for everything, but doing so is safe.
    // However, wait, if the file already has some of these variables, it might conflict.
    // We will just inject it below the imports.
    const importEnd = content.lastIndexOf('import ');
    let insertPos = 0;
    if (importEnd !== -1) {
      insertPos = content.indexOf('\n', importEnd) + 1;
    }
    content = content.slice(0, insertPos) + '\n' + stubCode + '\n' + content.slice(insertPos);

    fs.writeFileSync(filePath, content);
    console.log('Fixed', filePath);
  }
}

function walk(dir) {
  for (const f of fs.readdirSync(dir)) {
    const full = path.join(dir, f);
    if (fs.statSync(full).isDirectory()) walk(full);
    else if (full.endsWith('.ts') || full.endsWith('.tsx')) processFile(full);
  }
}

walk('/home/asr11/ASHER/os.hub.co.il/src');
