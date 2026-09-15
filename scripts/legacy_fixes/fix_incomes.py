import re

with open('src/hooks/useFinanceIncomes.tsx', 'r') as f:
    content = f.read()

# Fix convertToTaxReceipt syntax errors
content = re.sub(
    r"const res_upd = await fetch\(`/api/financeIncomes/\$\{id\}`,\s*{\s*method:\s*'PATCH',\s*headers:\s*{\s*'Content-Type':\s*'application/json'\s*},\s*body:\s*JSON\.stringify\(stripUndefined\({(.*?)}\),\s*\)",
    r"const res_upd = await fetch(`/api/financeIncomes/${id}`, {\n        method: 'PATCH',\n        headers: { 'Content-Type': 'application/json' },\n        body: JSON.stringify(stripUndefined({\1}))\n      })\n      if (!res_upd.ok) throw new Error('API Error')",
    content,
    flags=re.DOTALL
)

# Fix the duplicate if (!res_upd.ok) later
content = re.sub(r"if \(!res_upd\.ok\) throw new Error\('API Error'\)\s*const remainingInc", r"const remainingInc", content)

# Fix addIncomePayment syntax error
content = re.sub(
    r"const oldSnap = await getDoc\(doc\(db, 'financeIncomes', id\)\),\s*}\)\s*if \(!res_upd\.ok\) throw new Error\('API Error'\)\s*if \(!oldSnap\.exists\(\)\) return\s*const oldInc = docToFinanceIncome\(\s*oldSnap as QueryDocumentSnapshot<DocumentData>,\s*\)",
    r"const oldRes = await fetch(`/api/financeIncomes/${id}`)\n        if (!oldRes.ok) return\n        const oldInc = await oldRes.json()",
    content,
    flags=re.DOTALL
)

# Fix remaining updateDoc in convertToTaxReceipt
content = re.sub(
    r"await updateDoc\(\s*doc\(db, 'financeIncomes', id\),\s*stripUndefined\({(.*?)}\),\s*\)",
    r"const res_sync = await fetch(`/api/financeIncomes/${id}`, {\n          method: 'PATCH',\n          headers: { 'Content-Type': 'application/json' },\n          body: JSON.stringify(stripUndefined({\1}))\n        })\n        if (!res_sync.ok) throw new Error('API Error')",
    content,
    flags=re.DOTALL
)

# Fix serverTimestamp
content = content.replace("createdAt: serverTimestamp(),", "createdAt: new Date().toISOString(),")

with open('src/hooks/useFinanceIncomes.tsx', 'w') as f:
    f.write(content)
