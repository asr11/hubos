export interface StorageUploadState {
  progress: number
  error: Error | null
  url: string | null
  file: File | null
  isUploading: boolean
}

export async function uploadFileToStorage(
  _path: string,
  _file: File,
  _onProgress?: (p: number) => void,
): Promise<string> {
  return ""
}
export function safeFileName(n: string) { return n }
export async function uploadToStorage(..._args: any[]): Promise<string> { return "" }
