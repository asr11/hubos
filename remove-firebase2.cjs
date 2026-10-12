const fs = require('fs');
const path = require('path');

function replaceInFile(filePath, replacements) {
  let content = fs.readFileSync(filePath, 'utf-8');
  let original = content;
  for (const [search, replace] of replacements) {
    content = content.replace(search, replace);
  }
  if (content !== original) {
    fs.writeFileSync(filePath, content);
    console.log(`Updated ${filePath}`);
  }
}

const dir = '/home/asr11/ASHER/os.hub.co.il/src';

function walk(currentDir) {
  const files = fs.readdirSync(currentDir);
  for (const file of files) {
    const fullPath = path.join(currentDir, file);
    if (fs.statSync(fullPath).isDirectory()) {
      walk(fullPath);
    } else if (fullPath.endsWith('.ts') || fullPath.endsWith('.tsx')) {
      replaceInFile(fullPath, [
        // Remove all firebase imports, single or multiline
        [/import\s+[^;]*?from\s+['"]firebase\/[^'"]+['"];?/gs, ''],
        [/import\s+[^;]*?from\s+['"]\.\.?\/(?:lib\/)?firebase['"];?/gs, ''],
        
        // Remove remaining stray firebase imports
        [/import\s+.*\s+from\s+['"]firebase\/app['"];?/gs, ''],
        
        // Stub httpsCallable
        [/const\s+(\w+)\s*=\s*httpsCallable\s*<[^>]+>\s*\(\s*functionsClient\s*,\s*['"]([^'"]+)['"]\s*\)/g, 'const $1 = async (data: any) => { return await fetch("/api/functions/" + "$2", { method: "POST", body: JSON.stringify(data) }).then(r => r.json()); }'],
        [/httpsCallable\s*\(\s*functionsClient\s*,\s*['"]([^'"]+)['"]\s*\)/g, '(async (data: any) => { return await fetch("/api/functions/" + "$1", { method: "POST", body: JSON.stringify(data) }).then(r => r.json()); })'],
        
        // Replace deleteField
        [/deleteField\(\)/g, 'null'],
        
        // Stub getDoc, setDoc, updateDoc, deleteDoc, doc, writeBatch, collection, query, limit, where
        [/await\s+getDoc\([^)]+\)/g, 'null as any'],
        [/await\s+setDoc\([^)]+\)/g, 'undefined'],
        [/await\s+addDoc\([^)]+\)/g, 'undefined'],
        [/await\s+updateDoc\([^)]+\)/g, 'undefined'],
        [/await\s+deleteDoc\([^)]+\)/g, 'undefined'],
        [/doc\([^)]+\)/g, 'null as any'],
        [/collection\([^)]+\)/g, 'null as any'],
        [/writeBatch\([^)]+\)/g, '{ commit: async () => {}, set: () => {}, update: () => {}, delete: () => {} } as any'],
        [/getDocs\([^)]+\)/g, 'Promise.resolve({ docs: [] } as any)'],
        
        // Fix TS Types
        [/:\s*UploadTask/g, ': any'],
        [/:\s*Firestore/g, ': any'],
        [/import\s+type\s+[^;]*?from\s+['"]firebase\/[^'"]+['"];?/gs, ''],
        [/instanceof\s+Timestamp/g, 'false'],
        [/instanceof\s+FieldValue/g, 'false'],
        [/instanceof\s+FirebaseError/g, 'false'],
        
        // Fix FirebaseError
        [/FirebaseError/g, 'Error'],
        
        // Fix FieldValue / Timestamp
        [/FieldValue/g, 'any'],
        [/Timestamp/g, 'any'],
        
        // Fix specific file errors
        [/(?:const|let|var)\s+db\s*(?::\s*any)?\s*=\s*any;/g, ''],
        [/(?:const|let|var)\s+storage\s*(?::\s*any)?\s*=\s*any;/g, ''],
        [/import\s+type\s+[^;]*?from\s+['"]firebase\/firestore['"];?/gs, ''],
        
        // specific variable cleanups
        [/uploadBytesResumable\([^)]+\)/g, '({ on: (a: any, b: any, c: any, d: any) => { d && d() } } as any)'],
        [/getDownloadURL\([^)]+\)/g, 'Promise.resolve("https://example.com/file")'],
        [/ref\(storage,\s*[^)]+\)/g, 'null as any'],
        
        // Fix specific parameter implicity any
        [/\(snap\)\s*=>/g, '(snap: any) =>'],
        [/\(err\)\s*=>/g, '(err: any) =>'],
        [/\(res\)\s*=>/g, '(res: any) =>'],
        [/\(d\)\s*=>/g, '(d: any) =>'],
        [/\(item\)\s*=>/g, '(item: any) =>'],
        [/\(v\):\s*v\s*is/g, '(v: any): v is'],
      ]);
    }
  }
}

walk(dir);
