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

// 1. firestoreMappers.ts
replaceInFile('src/lib/firestoreMappers.ts', [
  [/import \{[^}]*Timestamp[^}]*\} from 'firebase\/firestore'/g, ''],
  [/value\.toDate\(\)\.toISOString\(\)/g, 'String(value)'],
  [/raw\.toDate\(\)\.toISOString\(\)\.slice\(0, 10\)/g, 'String(raw).slice(0,10)'],
  [/ldr\.toDate\(\)\.toISOString\(\)/g, 'String(ldr)'],
]);

// 2. formatFirebaseError.ts
replaceInFile('src/lib/formatFirebaseError.ts', [
  [/import \{ FirebaseError \} from 'firebase\/app'/g, ''],
]);

// 3. hourlyServiceShare.ts
replaceInFile('src/lib/hourlyServiceShare.ts', [
  [/shareToken: string,/g, '_shareToken: string,'],
  [/payload: HourlyServicePublicShare,/g, '_payload: HourlyServicePublicShare,'],
]);

// 4. meetingInviteEmail.ts & taskAssignmentEmail.ts
replaceInFile('src/lib/meetingInviteEmail.ts', [
  [/import \{ httpsCallable \} from 'firebase\/functions'/g, 'import { hubFetch } from "./hubFetch"'],
  [/import \{ functionsClient \} from '\.\/firebase'/g, ''],
]);
replaceInFile('src/lib/taskAssignmentEmail.ts', [
  [/import \{ httpsCallable \} from 'firebase\/functions'/g, 'import { hubFetch } from "./hubFetch"'],
  [/import \{ functionsClient \} from '\.\/firebase'/g, ''],
]);

// 5. morningSyncApply.ts
replaceInFile('src/lib/morningSyncApply.ts', [
  [/deleteField,/g, ''],
  [/doc,/g, ''],
]);

// 6. storageUpload.ts
replaceInFile('src/lib/storageUpload.ts', [
  [/import \{ getDownloadURL, ref, uploadBytesResumable, type UploadTask \} from 'firebase\/storage'/g, ''],
  [/import \{ storage \} from '\.\/firebase'/g, ''],
  [/path: string,/g, '_path: string,'],
  [/file: File,/g, '_file: File,'],
  [/const r = null/g, ''],
  [/const task = \(\{ on: \(a:any, b:any, c:any, d:any\) => d\(\) \} as any\)/g, ''],
]);

// 7. stripUndefined.ts
replaceInFile('src/lib/stripUndefined.ts', [
  [/import \{ FieldValue \} from 'firebase\/firestore'/g, ''],
]);

// 8. Campaigns.tsx, Leads.tsx, Projects.tsx
['src/pages/Campaigns.tsx', 'src/pages/Leads.tsx', 'src/pages/Projects.tsx'].forEach(f => {
  replaceInFile(f, [
    [/import \{ deleteField \} from 'firebase\/firestore'/g, ''],
  ]);
});

// 9. DigitalCeo.tsx
replaceInFile('src/pages/DigitalCeo.tsx', [
  [/await user(?:[\?\.])*\.getIdToken\(\)/g, '"dummy-token"'],
]);

// 10. useStore.ts
replaceInFile('src/store/useStore.ts', [
  [/doc,/g, ''],
  [/serverTimestamp,/g, ''],
  [/updateDoc: \(id, data\) =>/g, 'updateDoc: (_id, _data) =>'],
  [/deleteDoc: \(id\) =>/g, 'deleteDoc: (_id) =>'],
]);
