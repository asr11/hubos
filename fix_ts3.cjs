const fs = require('fs');
const path = require('path');

function replaceInFile(filePath, replacements) {
  if (!fs.existsSync(filePath)) return;
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

// 1. App.tsx
replaceInFile('src/App.tsx', [
  [/const AiEfficiencyDashboard = lazy\(\(\) => import\('\.\/pages\/AiEfficiencyDashboard'\)\.then\(m => \(\{ default: m\.AiEfficiencyDashboard \}\)\)\)\n/g, ''],
  [/<Route\s*path="\/admin\/ai-efficiency"[\s\S]*?<\/Route>\n/g, ''],
]);

// 2. clockifyService.ts
replaceInFile('src/lib/clockifyService.ts', [
  [/import \{ db, functionsClient \} from '\.\/firebase'/g, 'import { functionsClient } from "./firebase"'],
]);

// 3. firestoreCascades.ts
replaceInFile('src/lib/firestoreCascades.ts', [
  [/export async function deleteCustomerDoc[\s\S]*/g, 'export async function deleteCustomerDoc(_id: string) {}\nexport async function deleteProjectDoc(_id: string) {}'],
]);

// 4. storageUpload.ts
replaceInFile('src/lib/storageUpload.ts', [
  [/import \{ getDownloadURL, ref, uploadBytesResumable, type UploadTask \} from 'firebase\/storage'/g, ''],
  [/import \{ storage \} from '\.\/firebase'/g, ''],
  [/export interface StorageUploadState[\s\S]*/g, 'export interface StorageUploadState {progress:number; error:Error|null; url:string|null; file:File|null; isUploading:boolean;} export async function uploadFileToStorage(_path: string, _file: File, _onProgress?: (p: number) => void): Promise<string> { return ""; }'],
]);
