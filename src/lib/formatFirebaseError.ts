

/** הסבר קצר בעברית לפי קוד — ליד המקור מ-Firebase */
const FIRESTORE_HINT_HE: Record<string, string> = {
  'already-exists':
    'כפילות: אימייל כבר רשום ב-Firebase Authentication, או כפילות זמנית ב-Firestore (ריענון לעיתים פותר).',
  'permission-denied':
    'אין הרשאה: התחברו לחשבון ובדקו את חוקי האבטחה של Firestore לפרויקט.',
  'failed-precondition':
    'תנאי לא מתקיים — לדוגמה חסר API Key / Workspace ID (Clockify) או אינדקס Firestore.',
  unavailable: 'השירות לא זמין זמנית — נסו שוב בעוד רגע.',
  unauthenticated: 'ההתחברות פגה — התחברו מחדש.',
  'invalid-argument': 'נתונים לא חוקיים ל-Firestore.',
  'not-found': 'המסמך או האוסף לא נמצאו.',
  'functions/not-found':
    'הפונקציה בערוץ ה-Cloud Functions לא נמצאה — בדקו אזור (region) או שם פונקציה.',
  'functions/failed-precondition':
    'תנאי לא מתקיים בשרת — ל-Clockify: ודאו API Key ו-Workspace ID ב-attendanceConfig.',
  'functions/already-exists':
    'כבר קיים משתמש עם אימייל זה ב-Firebase Authentication.',
  'functions/internal':
    'שגיאה פנימית בשרת (Cloud Function). נפוצות: הפונקציה לא פורסמה/אזור שגוי, אימייל כבר קיים ב-Auth, או בעיית הרשאות. ההסבר על SMTP כאן רלוונטי בעיקר לפונקציות מייל — בדקו לוגים ב-Firebase Console.',
}

function messageSuggestsPrematureSend(msg: string): boolean {
  return /send was called before connect/i.test(msg)
}

/**
 * טקסט מפורט להצגה למשתמש (שורה שנייה בטוסט / לוג).
 */
export function formatFirebaseErrorDetail(err: unknown): string {
  if (err instanceof Error) {
    let hint = FIRESTORE_HINT_HE[(err as any).code] ?? ''
    if (!hint && messageSuggestsPrematureSend((err as any).message)) {
      hint =
        'Firestore נקרא לפני שהחיבור היה מוכן — רעננו את הדף או נסו שוב אחרי שנייה.'
    }
    const technical = `${(err as any).code}: ${(err as any).message}`
    return hint ? `${hint}\n${technical}` : technical
  }
  if (err instanceof Error && (err as any).message) {
    if (messageSuggestsPrematureSend((err as any).message)) {
      return `${'Firestore נקרא לפני שהחיבור היה מוכן — רעננו את הדף או נסו שוב אחרי שנייה.'}\n${(err as any).message}`
    }
    return (err as any).message
  }
  return typeof err === 'string' ? err : JSON.stringify(err)
}
