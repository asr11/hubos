const fs = require('fs');
const path = require('path');

function replaceInFile(filePath, replacements) {
  let content = fs.readFileSync(filePath, 'utf-8');
  let original = content;
  for (const [search, replace] of replacements) {
    if (typeof search === 'function') {
      content = search(content);
    } else {
      content = content.replace(search, replace);
    }
  }
  if (content !== original) {
    fs.writeFileSync(filePath, content);
    console.log(`Updated ${filePath}`);
  }
}

function processFile(filePath) {
  const f = path.basename(filePath);
  
  // Remove imports of firebase-stubs
  let content = fs.readFileSync(filePath, 'utf-8');
  let newContent = content.replace(/import\s+type\s+\{?[^}]*\}?\s+from\s+['"](?:\.\.\/)+lib\/firebase-stubs['"];?/gs, '');
  newContent = newContent.replace(/import\s+\{?[^}]*\}?\s+from\s+['"](?:\.\.\/)+lib\/firebase-stubs['"];?/gs, '');
  newContent = newContent.replace(/import\s+type\s+\{?[^}]*\}?\s+from\s+['"]\.\/firebase-stubs['"];?/gs, '');
  newContent = newContent.replace(/import\s+\{?[^}]*\}?\s+from\s+['"]\.\/firebase-stubs['"];?/gs, '');
  
  if (f === 'UserManagementContent.tsx' || f === 'meetingInviteEmail.ts' || f === 'taskAssignmentEmail.ts' || f === 'clockifyService.ts') {
    newContent = newContent.replace(/const\s+(\w+)\s*=\s*httpsCallable\s*<[^>]+>\s*\(\s*functionsClient\s*,\s*['"]([^'"]+)['"]\s*\)/g, 'const $1 = async (data: any) => { const res = await hubFetch("/api/functions/" + "$2", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) }); if(!res.ok) throw new Error("Failed"); return await res.json(); }');
  }

  // formatFirebaseError.ts
  if (f === 'formatFirebaseError.ts') {
    newContent = newContent.replace(/import \{ FirebaseError \} from '\.\/firebase-stubs'/g, '');
    newContent = newContent.replace(/err instanceof FirebaseError/g, 'err instanceof Error');
    newContent = newContent.replace(/err\.code/g, '(err as any).code');
    newContent = newContent.replace(/err\.message/g, '(err as any).message');
  }

  // stripUndefined.ts
  if (f === 'stripUndefined.ts') {
    newContent = newContent.replace(/value instanceof FieldValue/g, 'false');
  }

  // firestoreMappers.ts
  if (f === 'firestoreMappers.ts') {
    newContent = newContent.replace(/value instanceof Timestamp/g, 'false');
    newContent = newContent.replace(/raw instanceof Timestamp/g, 'false');
    newContent = newContent.replace(/ldr instanceof Timestamp/g, 'false');
  }

  // expenseDocumentStorage.ts, storageUpload.ts
  if (f === 'expenseDocumentStorage.ts' || f === 'storageUpload.ts' || f === 'GiExpenseDocumentButton.tsx') {
    newContent = newContent.replace(/auth\.currentUser/g, 'null');
    newContent = newContent.replace(/ref\(storage,[^)]+\)/g, 'null');
    newContent = newContent.replace(/uploadBytesResumable\([^)]+\)/g, '({ on: (a:any, b:any, c:any, d:any) => d() } as any)');
    newContent = newContent.replace(/getDownloadURL\([^)]+\)/g, 'Promise.resolve("")');
  }

  // financeClientBalance.ts, hourlyServiceShare.ts, morningSyncApply.ts, seedClients.ts, seedData.ts, HourlyServiceShare.tsx
  newContent = newContent.replace(/await setDoc\([^)]+\)/g, 'void 0');
  newContent = newContent.replace(/await updateDoc\([^)]+\)/g, 'void 0');
  newContent = newContent.replace(/await deleteDoc\([^)]+\)/g, 'void 0');
  newContent = newContent.replace(/await getDoc\([^)]+\)/g, '{ exists: () => false, data: () => ({}) }');
  newContent = newContent.replace(/getDoc\([^)]+\)/g, 'Promise.resolve({ exists: () => false, data: () => ({}) })');
  newContent = newContent.replace(/doc\([^)]+\)/g, 'null as any');
  newContent = newContent.replace(/serverTimestamp\(\)/g, 'new Date().toISOString()');
  newContent = newContent.replace(/writeBatch\([^)]+\)/g, '{ commit: async () => {}, set: () => {}, update: () => {}, delete: () => {} } as any');

  // deleteField()
  newContent = newContent.replace(/deleteField\(\)/g, 'null');

  if (content !== newContent) {
    fs.writeFileSync(filePath, newContent);
    console.log(`Updated ${filePath}`);
  }
}

function walk(currentDir) {
  const files = fs.readdirSync(currentDir);
  for (const file of files) {
    const fullPath = path.join(currentDir, file);
    if (fs.statSync(fullPath).isDirectory()) {
      walk(fullPath);
    } else if (fullPath.endsWith('.ts') || fullPath.endsWith('.tsx')) {
      processFile(fullPath);
    }
  }
}

walk('/home/asr11/ASHER/os.hub.co.il/src');
