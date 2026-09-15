// נעילת תקופה — עותק השרת.
//
// חודש הרו״ה = תאריך החשבונית תמיד. נעילת המע״מ מסמנת מאחרים ומונעת
// עריכה למפרע, אך אינה מעבירה את ההוצאה לחודש אחר.
//
// הלוגיקה זהה ל-src/lib/financePeriodLock.ts. בדיקת הזהות
// src/lib/__tests__/financePeriodLockParity.test.ts משווה בין השתיים
// ותיכשל אם אחת מהן תשתנה בלי השנייה.
export const VAT_REPORT_DAY = 19;
function parseYm(ym) {
    const [ys, ms] = ym.split('-');
    const y = Number(ys);
    const m = Number(ms);
    if (!y || !m || m < 1 || m > 12)
        return null;
    return { y, m };
}
export function addMonths(ym, delta) {
    const p = parseYm(ym);
    if (!p)
        return ym;
    let { y, m } = p;
    m += delta;
    while (m > 12) {
        m -= 12;
        y += 1;
    }
    while (m < 1) {
        m += 12;
        y -= 1;
    }
    return `${y}-${String(m).padStart(2, '0')}`;
}
export function monthLockDate(ym) {
    return `${addMonths(ym.slice(0, 7), 1)}-${String(VAT_REPORT_DAY).padStart(2, '0')}`;
}
export function isMonthLocked(ym, today) {
    return today > monthLockDate(ym);
}
export function openMonth(today) {
    const currentYm = today.slice(0, 7);
    const day = Number(today.slice(8, 10));
    return day > VAT_REPORT_DAY ? currentYm : addMonths(currentYm, -1);
}
export function resolveAttributionMonth(documentDateYmd, today) {
    const documentMonth = documentDateYmd.slice(0, 7);
    const late = isMonthLocked(documentMonth, today);
    return { month: documentMonth, late, documentMonth };
}
//# sourceMappingURL=financePeriodLock.js.map