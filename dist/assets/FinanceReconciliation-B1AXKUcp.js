import{a as e,n as t,t as n}from"./jsx-runtime--AOyvnT1.js";import{t as r}from"./hubSectionBorder-Bcqt3g4U.js";import{i}from"./dates-C1sf_28L.js";import{D as a,J as o,X as s,c,i as l,k as u,s as d}from"./financeExpenseTypes-BKI8Eglr.js";import{a as f,n as p}from"./useFinanceExpenses-BC1kz1Ix.js";import{t as m}from"./EmptyState-DTJNuzli.js";import{_ as h,b as g,c as _,d as v,g as y,h as b,m as x,o as S,p as C,t as w,w as T,x as E,y as D}from"./expenseReconciliation-C9BJqMOB.js";import{n as O,t as k}from"./financeTableSort-UBAmeodw.js";import{t as A}from"./FinanceClickable-LaSeG5ST.js";import{t as j}from"./SortableTh-DNgPtcjc.js";import{n as M,r as N}from"./useFinancePeriodFilter-l1dhcpih.js";import{n as ee,t as te}from"./useFinanceAttention-tcdx3xan.js";import{t as P}from"./AttachInvoiceModal-B2jhZD5n.js";import{n as F,t as I}from"./ExpenseVatClassChip-Bvr4GaIn.js";var L=e(t(),1),R=n();function z(e){return e.name?.replace(/^זיכוי\s*—\s*/,``).trim()||e.supplierName?.trim()||e.description?.trim()||`—`}function B(e){return e.paidDate??e.documentDate??`${e.billingMonth}-01`}function V({ymFrom:e,ymTo:t}){let{expenses:n,updateExpense:o}=p(),[s,c]=(0,L.useState)(null),[l,u]=(0,L.useState)(null),d=(0,L.useMemo)(()=>x(n).filter(n=>{let r=B(n).slice(0,7);return r>=e&&r<=t}),[n,e,t]),f=(0,L.useMemo)(()=>n.filter(n=>{if(!C(n)||!n.refundForExpenseId?.trim())return!1;let r=B(n).slice(0,7);return r>=e&&r<=t}),[n,e,t]),m=(0,L.useMemo)(()=>new Map(n.map(e=>[e.id,e])),[n]),_=(0,L.useRef)(``);(0,L.useEffect)(()=>{let e=b(n);if(e.size===0)return;let t=[...e.entries()].map(([e,t])=>`${e}:${t}`).sort().join(`|`);t!==_.current&&(_.current=t,(async()=>{for(let[t,n]of e)await o(t,{refundForExpenseId:n})})())},[n,o]);async function v(e,t){u(e.id);try{await o(e.id,{refundForExpenseId:t}),c(null)}finally{u(null)}}async function S(e){u(e.id);try{await o(e.id,{refundForExpenseId:null})}finally{u(null)}}return d.length===0&&f.length===0?null:(0,R.jsxs)(`section`,{className:`min-w-0 rounded-2xl ${r(3)} p-4 shadow-[0_2px_12px_rgba(0,0,0,0.06)]`,children:[(0,R.jsxs)(`div`,{className:`mb-3 text-right`,children:[(0,R.jsx)(`h2`,{className:`text-[15px] font-extrabold text-[var(--text-dark)]`,children:`זיכויים והחזרים`}),(0,R.jsx)(`p`,{className:`mt-1 text-[12px] leading-relaxed text-[var(--text-mid)]`,children:`זיכוי על עסקה שבוטלה נשמר כתנועה נפרדת, בדיוק כמו בכרטיס. שיוך שלו לחיוב המקורי מראה שהצמד מתאפס, ומונע דרישת חשבונית על הוצאה שכבר בוטלה.`})]}),d.length>0?(0,R.jsx)(`ul`,{className:`space-y-2`,children:d.map(e=>{let t=g(e,n),r=h(e,n),o=s===e.id;return(0,R.jsxs)(`li`,{className:`rounded-xl border border-[#FDE68A] bg-[#FFFBEB] p-3`,children:[(0,R.jsxs)(`div`,{className:`flex flex-wrap items-center gap-x-3 gap-y-1`,children:[(0,R.jsx)(`span`,{className:`text-[13px] font-bold text-[var(--text-dark)]`,children:z(e)}),(0,R.jsx)(`span`,{className:`text-[13px] font-extrabold tabular-nums text-[#059669]`,children:a(y(e))}),(0,R.jsx)(`span`,{className:`text-[11px] text-[var(--text-mid)]`,children:i(B(e))}),(0,R.jsxs)(`div`,{className:`ms-auto flex flex-wrap gap-2`,children:[t?(0,R.jsxs)(`button`,{type:`button`,onClick:()=>void v(e,t.charge.id),disabled:l===e.id,className:`rounded-lg bg-[var(--orange)] px-3 py-1.5 text-[11px] font-bold text-white transition hover:brightness-95 disabled:opacity-50`,children:[`התאם ל`,z(t.charge),` ·`,` `,a(t.charge.amount),t.nearAmount?` (הפרש שער ${a(Math.abs(t.gap))})`:``]}):null,(0,R.jsx)(`button`,{type:`button`,onClick:()=>c(o?null:e.id),className:`rounded-lg border border-[var(--border)] bg-[var(--dash-panel-bg)] px-3 py-1.5 text-[11px] font-semibold text-[var(--text-mid)] transition hover:bg-[var(--surface-muted)]`,children:o?`סגור`:`בחר חיוב (${r.length})`})]})]}),o?(0,R.jsx)(`div`,{className:`mt-2 space-y-1.5 border-t border-[#FDE68A] pt-2`,children:r.length===0?(0,R.jsx)(`p`,{className:`text-[11px] text-[var(--text-mid)]`,children:`לא נמצא חיוב מתאים באותו אמצעי תשלום ב-120 הימים שלפני הזיכוי. ייתכן שהחיוב המקורי עדיין לא יובא.`}):r.slice(0,12).map(t=>(0,R.jsxs)(`button`,{type:`button`,onClick:()=>void v(e,t.charge.id),disabled:l===e.id,className:`flex w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-[var(--border)] bg-[var(--dash-panel-bg)] px-3 py-2 text-right transition hover:border-[var(--orange)] disabled:opacity-50`,children:[(0,R.jsx)(`span`,{className:`text-[12px] font-semibold text-[var(--text-dark)]`,children:z(t.charge)}),(0,R.jsx)(`span`,{className:`text-[12px] font-bold tabular-nums text-[var(--text-dark)]`,children:a(t.charge.amount)}),(0,R.jsx)(`span`,{className:`text-[11px] text-[var(--text-mid)]`,children:i(B(t.charge))}),(0,R.jsxs)(`span`,{className:`ms-auto flex gap-1.5`,children:[t.exactAmount?(0,R.jsx)(`span`,{className:`rounded-full bg-[#D1FAE5] px-2 py-0.5 text-[10px] font-bold text-[#065F46]`,children:`סכום זהה`}):t.nearAmount?(0,R.jsxs)(`span`,{className:`rounded-full bg-[#FEF3C7] px-2 py-0.5 text-[10px] font-bold text-[#92400E]`,children:[`הפרש שער `,a(Math.abs(t.gap))]}):null,t.sameMerchant?(0,R.jsx)(`span`,{className:`rounded-full bg-[#DBEAFE] px-2 py-0.5 text-[10px] font-bold text-[#1E40AF]`,children:`אותו ספק`}):null]})]},t.charge.id))}):null]},e.id)})}):null,f.length>0?(0,R.jsx)(`ul`,{className:`mt-3 space-y-1.5 border-t border-[var(--border)] pt-3`,children:f.map(e=>{let t=m.get(e.refundForExpenseId),r=t?D(t,n):void 0;return(0,R.jsxs)(`li`,{className:`flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-[var(--surface-muted)]/50 px-3 py-2`,children:[(0,R.jsx)(`span`,{className:`text-[12px] font-semibold text-[var(--text-dark)]`,children:z(e)}),(0,R.jsx)(`span`,{className:`text-[12px] font-bold tabular-nums text-[#059669]`,children:a(y(e))}),(0,R.jsx)(`span`,{className:`text-[11px] text-[var(--text-mid)]`,children:t?`מקוזז מחיוב ${i(B(t))} · ${a(t.amount)}`:`החיוב המקורי נמחק`}),r?(0,R.jsx)(`span`,{className:`rounded-full px-2 py-0.5 text-[10px] font-bold ${r.fully?`bg-[#D1FAE5] text-[#065F46]`:`bg-[#FEF3C7] text-[#92400E]`}`,children:r.fully?`בוטל במלואו`:`נותר ${a(r.remaining)}`}):null,(0,R.jsx)(`button`,{type:`button`,onClick:()=>void S(e),disabled:l===e.id,className:`ms-auto text-[11px] font-semibold text-[var(--text-mid)] underline disabled:opacity-50`,children:`בטל שיוך`})]},e.id)})}):null]})}function H(e){let t=Math.abs(e).toLocaleString(`he-IL`,{minimumFractionDigits:2,maximumFractionDigits:2});return e<0?`(₪${t})`:`₪${t}`}function U(e){return e.replace(/&/g,`&amp;`).replace(/</g,`&lt;`).replace(/>/g,`&gt;`).replace(/"/g,`&quot;`)}function W(e){return e.supplierName?.trim()||e.name?.trim()||e.employeeName?.trim()||e.description?.trim()||`—`}function G(e,t){return e===t?u(e):`${u(e)} — ${u(t)}`}function K(e,t,n,r){let a=_(e,t,n,r?.typeById),o=0,s=0,c=0,l=0,u=a.map((e,t)=>{let n=e.expense,a=S(e);a?(o+=1,s+=n.amount):(c+=1,l+=n.amount);let u=r?.typeById?d(n.type,r.typeById):void 0,f=v(n);return{index:t+1,date:f,dateLabel:i(f),merchant:W(n),typeLabel:u?.label??n.type,amount:n.amount,vatAmount:typeof n.vatAmount==`number`?n.vatAmount:null,paymentLabel:n.paymentMethod===`credit_card`?`כרטיס אשראי`:`עובר ושב`,status:e.status,statusLabel:w[e.status],invoiceNumber:n.invoiceNumber?.trim()||(n.documentUrl?`מסמך מצורף`:`—`),bankRef:n.bankRef?.trim()||`—`,isOpen:a}}),f=Math.round((s+l)*100)/100;return{ymFrom:t,ymTo:n,periodLabel:G(t,n),generatedAtLabel:new Date().toLocaleString(`he-IL`,{day:`2-digit`,month:`2-digit`,year:`numeric`,hour:`2-digit`,minute:`2-digit`}),rows:u,openRows:o,openAmount:Math.round(s*100)/100,closedRows:c,closedAmount:Math.round(l*100)/100,totalAmount:f,coveragePercent:f>0?Math.round(l/f*1e3)/10:100}}function q(e){return e.map(e=>`
      <tr class="${e.isOpen?`open`:``}">
        <td class="num">${e.index}</td>
        <td class="date">${U(e.dateLabel)}</td>
        <td class="merchant">${U(e.merchant)}</td>
        <td class="type">${U(e.typeLabel)}</td>
        <td class="money">${H(e.amount)}</td>
        <td class="money">${e.vatAmount===null?`—`:H(e.vatAmount)}</td>
        <td class="pay">${U(e.paymentLabel)}</td>
        <td class="status">${U(e.statusLabel)}</td>
        <td class="invoice">${U(e.invoiceNumber)}</td>
        <td class="ref">${U(e.bankRef)}</td>
      </tr>`).join(``)}function J(e,t,n){return n.length===0?``:`
  <section class="block">
    <div class="block-head">
      <h2>${U(e)}</h2>
      <span class="block-meta">${U(t)}</span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width:34px">#</th>
          <th style="width:78px">תאריך</th>
          <th>ספק / תיאור</th>
          <th style="width:100px">סוג</th>
          <th style="width:92px">סכום</th>
          <th style="width:80px">מע״מ</th>
          <th style="width:78px">אמצעי</th>
          <th style="width:104px">מצב</th>
          <th style="width:96px">חשבונית</th>
          <th style="width:130px">אסמכתא בנק</th>
        </tr>
      </thead>
      <tbody>${q(n)}</tbody>
    </table>
  </section>`}function Y(e){let t=e.rows.filter(e=>e.isOpen),n=e.rows.filter(e=>!e.isOpen);return`<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
  <meta charset="utf-8" />
  <title>דוח התאמות בנקים</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    * { box-sizing: border-box; }
    body {
      font-family: "Segoe UI", Tahoma, Arial, sans-serif;
      color: #1e293b;
      margin: 0;
      padding: 24px;
      background: #fff;
      font-size: 11px;
      line-height: 1.45;
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      border-bottom: 3px solid #f97316;
      padding-bottom: 14px;
      margin-bottom: 18px;
    }
    .brand { font-size: 22px; font-weight: 800; color: #ea580c; }
    .title { font-size: 18px; font-weight: 800; margin-top: 4px; }
    .subtitle { color: #64748b; margin-top: 6px; font-size: 12px; }
    .meta { text-align: left; color: #64748b; font-size: 11px; }
    .summary {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-bottom: 18px;
    }
    .card {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px 14px;
      background: #f8fafc;
    }
    .card.alert { border-color: #fecaca; background: #fef2f2; }
    .card.good { border-color: #bbf7d0; background: #f0fdf4; }
    .card-label { color: #64748b; font-size: 10px; font-weight: 700; }
    .card-value { font-size: 20px; font-weight: 800; margin-top: 4px; }
    .card-sub { color: #64748b; font-size: 10px; margin-top: 2px; }
    .block { margin-bottom: 22px; break-inside: avoid; }
    .block-head {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      margin-bottom: 8px;
      padding: 0 2px;
    }
    .block-head h2 { margin: 0; font-size: 15px; color: #0f172a; }
    .block-meta { color: #64748b; font-size: 11px; font-weight: 600; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td {
      border: 1px solid #e2e8f0;
      padding: 6px 5px;
      vertical-align: top;
      word-wrap: break-word;
    }
    th { background: #f1f5f9; color: #334155; font-size: 10px; font-weight: 800; }
    td.num, td.date, td.money, td.pay, td.status { text-align: center; white-space: nowrap; }
    td.merchant { font-weight: 700; }
    td.ref { font-family: Consolas, monospace; font-size: 9px; }
    tr.open td.status { color: #b91c1c; font-weight: 800; }
    .footer {
      margin-top: 20px;
      padding-top: 12px;
      border-top: 1px solid #e2e8f0;
      color: #94a3b8;
      font-size: 10px;
      display: flex;
      justify-content: space-between;
    }
    @media print { body { padding: 0; } }
  </style>
</head>
<body>
  <header class="header">
    <div>
      <div class="brand">HUB POS</div>
      <div class="title">דוח התאמות בנקים</div>
      <div class="subtitle">תקופה: ${U(e.periodLabel)} · תשלומים מול חשבוניות ספק</div>
    </div>
    <div class="meta">
      <div>נוצר: ${U(e.generatedAtLabel)}</div>
      <div>${e.rows.length} תשלומים בתקופה</div>
    </div>
  </header>

  <section class="summary">
    <div class="card">
      <div class="card-label">סה״כ תשלומים</div>
      <div class="card-value">${H(e.totalAmount)}</div>
      <div class="card-sub">${e.rows.length} שורות</div>
    </div>
    <div class="card good">
      <div class="card-label">מגובה בחשבונית</div>
      <div class="card-value">${H(e.closedAmount)}</div>
      <div class="card-sub">${e.closedRows} שורות</div>
    </div>
    <div class="card ${e.openRows>0?`alert`:``}">
      <div class="card-label">ממתין לחשבונית</div>
      <div class="card-value">${H(e.openAmount)}</div>
      <div class="card-sub">${e.openRows} שורות</div>
    </div>
    <div class="card">
      <div class="card-label">אחוז כיסוי</div>
      <div class="card-value">${e.coveragePercent}%</div>
      <div class="card-sub">מתוך סכום התשלומים</div>
    </div>
  </section>

  ${J(`ממתין לחשבונית`,`${t.length} שורות · ${H(e.openAmount)}`,t)}
  ${J(`מגובה בחשבונית`,`${n.length} שורות · ${H(e.closedAmount)}`,n)}
  ${e.rows.length===0?`<p>אין תשלומים בתקופה שנבחרה.</p>`:``}

  <footer class="footer">
    <span>HUB ADVANCED · דוח פנימי</span>
    <span>${e.rows.length} שורות · ${U(e.periodLabel)}</span>
  </footer>
</body>
</html>`}var X=null;function Z(e){let t=Y(e);X||(X=document.createElement(`iframe`),X.id=`bank-reconciliation-print-frame`,X.setAttribute(`title`,`דוח התאמות בנקים`),X.style.cssText=`position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden`,document.body.appendChild(X));let n=X,r=new Blob([t],{type:`text/html;charset=utf-8`}),i=URL.createObjectURL(r);n.onload=()=>{URL.revokeObjectURL(i),window.setTimeout(()=>{let e=n.contentWindow;if(!e)throw Error(`לא ניתן להדפיס את הדוח`);e.focus(),e.print()},300)},n.src=i}function Q(e){return e.supplierName?.trim()||e.name?.trim()||e.employeeName?.trim()||e.description?.trim()||`—`}function ne(){let{expenses:e,loading:t,updateExpense:n}=p(),{types:r}=f(),h=M(),g=te(h.filterYmFrom,h.filterYmTo),[y,b]=(0,L.useState)(`open`),[x,C]=(0,L.useState)(``),{sort:w,toggle:D}=O(`date`,`desc`),[z,B]=(0,L.useState)(null),H=(0,L.useMemo)(()=>l(r),[r]),U=(0,L.useMemo)(()=>_(e,h.filterYmFrom,h.filterYmTo,H),[e,h.filterYmFrom,h.filterYmTo,H]),W=(0,L.useMemo)(()=>{let e=0,t=0,n=0,r=0;for(let i of U)S(i)?(e+=1,t+=i.expense.amount):(n+=1,r+=i.expense.amount);let i=t+r;return{openCount:e,openAmount:t,closedCount:n,closedAmount:r,total:i,percent:i>0?Math.round(r/i*1e3)/10:100}},[U]),G=(0,L.useMemo)(()=>{let e=y===`all`?U:U.filter(e=>S(e)===(y===`open`)),t=x.trim().toLowerCase();return t&&(e=e.filter(e=>Q(e.expense).toLowerCase().includes(t))),k(e,w,(e,t)=>t===`amount`?e.expense.amount:v(e.expense))},[U,y,x,w]);function q(){Z(K(e,h.filterYmFrom,h.filterYmTo,{typeById:H}))}return(0,R.jsxs)(`div`,{className:`mx-auto min-w-0 w-full max-w-[1400px] space-y-5 px-4 py-6 sm:px-8`,children:[(0,R.jsxs)(`header`,{className:`flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between`,children:[(0,R.jsxs)(`div`,{className:`min-w-0 text-right`,children:[(0,R.jsx)(`h1`,{className:`text-[22px] font-extrabold leading-tight text-[var(--text-dark)] md:text-[26px]`,children:`התאמות בנקים`}),(0,R.jsx)(`p`,{className:`mt-1 text-[12px] leading-relaxed text-[var(--text-mid)] md:text-[13px]`,children:`תשלום מול חשבונית ספק — משכורת, מיסים ועמלות לא מוצגים כאן`})]}),(0,R.jsx)(`button`,{type:`button`,onClick:q,disabled:U.length===0,className:`rounded-[10px] border border-[var(--border-orange-soft)] bg-[var(--dash-panel-bg)] px-3 py-2 text-sm font-semibold text-[var(--orange)] transition hover:bg-[var(--orange-light)] disabled:cursor-not-allowed disabled:opacity-50`,children:`דוח PDF`})]}),(0,R.jsx)(ee,{badges:g}),(0,R.jsx)(N,{periodMode:h.periodMode,setPeriodMode:h.setPeriodMode,monthPreset:h.monthPreset,setMonthPreset:h.setMonthPreset,filterYmFrom:h.filterYmFrom,filterYmTo:h.filterYmTo,setFilterYmFrom:h.setFilterYmFrom,setFilterYmTo:h.setFilterYmTo,filterRangeLabel:h.filterRangeLabel,applyMonthPreset:h.applyMonthPreset,applySingleMonth:h.applySingleMonth,monthOptions:h.monthOptions}),(0,R.jsx)(V,{ymFrom:h.filterYmFrom,ymTo:h.filterYmTo}),(0,R.jsxs)(`section`,{"aria-label":`סיכום התאמות`,className:`grid grid-cols-2 gap-2.5 lg:grid-cols-4`,children:[(0,R.jsx)($,{label:`סה״כ תשלומים`,value:a(W.total),sub:`${U.length} שורות`}),(0,R.jsx)($,{label:`סגורות`,value:a(W.closedAmount),sub:`${W.closedCount} שורות`,tone:`good`}),(0,R.jsx)($,{label:`ממתינות לחשבונית`,value:a(W.openAmount),sub:`${W.openCount} שורות`,tone:W.openCount>0?`alert`:`neutral`}),(0,R.jsx)($,{label:`אחוז כיסוי`,value:`${W.percent}%`,sub:`מתוך סכום התשלומים`,tone:W.percent>=95?`good`:W.percent>=80?`warn`:`alert`})]}),(0,R.jsxs)(`section`,{className:`rounded-2xl border border-[var(--border)] bg-[var(--dash-panel-bg)] p-4 shadow-[0_1px_8px_rgba(0,0,0,0.04)] sm:p-5`,children:[(0,R.jsxs)(`div`,{className:`mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between`,children:[(0,R.jsxs)(`div`,{className:`min-w-0`,children:[(0,R.jsx)(`h2`,{className:`text-base font-extrabold text-[var(--text-dark)]`,children:`רשימת התאמות`}),(0,R.jsxs)(`p`,{className:`mt-0.5 text-[11px] text-[var(--text-mid)]`,children:[h.filterRangeLabel,W.openCount>0?` · ${W.openCount} ממתינות לחשבונית`:` · הכל סגור`]})]}),(0,R.jsxs)(`div`,{className:`flex flex-wrap items-center gap-2`,children:[x.trim()?(0,R.jsxs)(`button`,{type:`button`,onClick:()=>C(``),className:`rounded-[8px] border border-[var(--orange)] bg-[var(--orange-light)] px-2.5 py-1.5 text-[11px] font-bold text-[var(--orange)]`,title:`נקה סינון שם`,children:[`שם: `,x,` ×`]}):null,(0,R.jsx)(`div`,{className:`flex flex-wrap gap-1 rounded-xl border border-[var(--border)] bg-[var(--bg-main)] p-1`,children:[[`open`,`ממתינות (${W.openCount})`],[`closed`,`סגורות (${W.closedCount})`],[`all`,`הכל (${U.length})`]].map(([e,t])=>(0,R.jsx)(`button`,{type:`button`,onClick:()=>b(e),className:y===e?e===`closed`?`rounded-[8px] bg-[#DCFCE7] px-2.5 py-1.5 text-[11px] font-bold text-[#166534]`:e===`open`?`rounded-[8px] bg-[#FEF3C7] px-2.5 py-1.5 text-[11px] font-bold text-[#92400E]`:`rounded-[8px] bg-[var(--orange)] px-2.5 py-1.5 text-[11px] font-bold text-white`:`rounded-[8px] px-2.5 py-1.5 text-[11px] font-semibold text-[var(--text-mid)] hover:bg-black/[0.03]`,children:t},e))})]})]}),t?(0,R.jsx)(`p`,{className:`py-8 text-center text-[13px] text-[var(--text-mid)]`,children:`טוען…`}):G.length===0?(0,R.jsx)(m,{title:y===`open`?`אין תשלומים שממתינים לחשבונית`:y===`closed`?`אין שורות סגורות בסינון`:`אין שורות להצגה`,description:y===`open`?`כל התשלומים בתקופה הזו מגובים בחשבונית.`:`נסו לשנות את התקופה או את הסינון.`}):(0,R.jsx)(`div`,{className:`overflow-x-auto rounded-xl border border-[var(--border)]`,children:(0,R.jsxs)(`table`,{className:`w-full border-collapse text-sm`,children:[(0,R.jsx)(`thead`,{className:`sticky top-0 z-[1]`,children:(0,R.jsxs)(`tr`,{className:`border-b border-[var(--border)] bg-[var(--surface-muted)]/80 text-[11px] font-bold text-[var(--text-mid)] backdrop-blur-sm`,children:[(0,R.jsx)(j,{label:`תאריך`,columnKey:`date`,sort:w,onSort:D}),(0,R.jsx)(`th`,{className:`px-2.5 py-2.5 text-right`,children:`ספק / תיאור`}),(0,R.jsx)(j,{label:`סכום`,columnKey:`amount`,sort:w,onSort:D}),(0,R.jsx)(`th`,{className:`px-2.5 py-2.5 text-right`,children:`אמצעי`}),(0,R.jsx)(`th`,{className:`px-2.5 py-2.5 text-right`,children:`סטטוס · חשבונית`}),(0,R.jsx)(`th`,{className:`px-2.5 py-2.5 text-right`})]})}),(0,R.jsx)(`tbody`,{children:G.map(e=>{let t=e.expense,r=S(e),l=d(t.type,H),f=o(t),p=t.paidDate?.trim()||v(t),m=t.documentDate?.trim()||``,h=c(t);return(0,R.jsxs)(`tr`,{className:`border-b border-[var(--border)]/80 transition ${r?`bg-[#FFFBEB]/55 hover:bg-[#FEF3C7]/50`:`bg-[#DCFCE7] hover:bg-[#BBF7D0]`}`,children:[(0,R.jsxs)(`td`,{className:`whitespace-nowrap px-2.5 py-3 align-top tabular-nums`,children:[(0,R.jsx)(`div`,{className:`font-semibold text-[var(--text-dark)]`,title:`חודש לפי תאריך החשבונית`,children:u(h)}),(0,R.jsxs)(`div`,{className:`mt-0.5 space-y-0.5 text-[10px] font-semibold text-[var(--text-mid)]`,children:[p?(0,R.jsxs)(`div`,{children:[`תשלום `,i(p)]}):null,t.chargeDate&&t.chargeDate!==t.paidDate?(0,R.jsxs)(`div`,{children:[`חיוב `,i(t.chargeDate)]}):null,m?(0,R.jsxs)(`div`,{children:[`חשבונית `,i(m)]}):(0,R.jsx)(`div`,{className:`text-[var(--text-light)]`,children:`חשבונית —`})]})]}),(0,R.jsxs)(`td`,{className:`px-2.5 py-3 align-top`,children:[(0,R.jsx)(A,{title:`סנן לפי ספק`,onClick:()=>C(Q(t)),children:Q(t)}),(0,R.jsxs)(`div`,{className:`mt-0.5 flex flex-wrap items-center gap-1 text-[10px] text-[var(--text-mid)]`,children:[(0,R.jsx)(`span`,{children:l?.label??t.type}),(0,R.jsx)(`span`,{"aria-hidden":!0,children:`·`}),(0,R.jsx)(I,{value:f,onChange:e=>{n(t.id,{vatClass:e,vatLiable:s(e)})}})]})]}),(0,R.jsxs)(`td`,{className:`whitespace-nowrap px-2.5 py-3 align-top tabular-nums font-bold text-[var(--text-dark)]`,children:[a(t.amount),(()=>{let e=T(t);return e?(0,R.jsx)(`span`,{className:`mt-0.5 block text-[10px] font-bold text-[#3730A3]`,children:E(e.amount,e.currency)}):null})()]}),(0,R.jsx)(`td`,{className:`whitespace-nowrap px-2.5 py-3 align-top text-[11px] font-semibold text-[var(--text-mid)]`,children:t.paymentMethod===`credit_card`?`כרטיס אשראי`:`עובר ושב`}),(0,R.jsx)(`td`,{className:`px-2.5 py-3 align-top`,children:(0,R.jsxs)(`div`,{className:`flex min-w-[140px] flex-col items-start gap-1.5`,children:[(0,R.jsx)(F,{status:e.status,linkedGi:e.linkedGi,expense:t}),t.expenseNote?.trim()?(0,R.jsxs)(`div`,{className:`max-w-[220px] rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-2 py-1 text-[11px] font-bold leading-snug text-[#DC2626]`,children:[`הערה: `,t.expenseNote.trim()]}):null,t.documentUrl?(0,R.jsx)(`a`,{href:t.documentUrl,target:`_blank`,rel:`noreferrer`,className:`text-[10px] font-bold text-[var(--orange)] underline decoration-[var(--orange)]/40 underline-offset-2`,children:t.invoiceNumber?.trim()?`#${t.invoiceNumber.trim()}`:`צפייה במסמך`}):t.invoiceNumber?.trim()?(0,R.jsxs)(`span`,{className:`text-[10px] font-semibold text-[var(--text-mid)]`,children:[`#`,t.invoiceNumber.trim()]}):null]})}),(0,R.jsx)(`td`,{className:`whitespace-nowrap px-2.5 py-3 align-top`,children:(0,R.jsx)(`button`,{type:`button`,onClick:()=>B(t),className:r?`inline-flex h-8 items-center justify-center rounded-[10px] bg-[var(--orange)] px-3 text-[11px] font-bold text-white transition hover:brightness-95`:`inline-flex h-8 items-center justify-center rounded-[10px] border border-[var(--border)] px-3 text-[11px] font-semibold text-[var(--text-mid)] transition hover:bg-[var(--surface-muted)]`,children:r?`צרף חשבונית`:`החלף`})})]},t.id)})}),(0,R.jsx)(`tfoot`,{children:(0,R.jsxs)(`tr`,{className:`border-t-2 border-[var(--border)] bg-[var(--surface-muted)]/70 text-[13px] font-extrabold text-[var(--text-dark)]`,children:[(0,R.jsxs)(`td`,{className:`px-2.5 py-3`,colSpan:2,children:[`סה״כ (`,G.length,`)`]}),(0,R.jsx)(`td`,{className:`px-2.5 py-3 tabular-nums`,children:a(G.reduce((e,t)=>e+t.expense.amount,0))}),(0,R.jsx)(`td`,{className:`px-2.5 py-3`,colSpan:3})]})})]})})]}),(0,R.jsx)(P,{expense:z,onClose:()=>B(null)})]})}function $({label:e,value:t,sub:n,tone:r=`neutral`}){return(0,R.jsxs)(`div`,{className:`rounded-xl border p-3.5 ${r===`good`?`border-[#BBF7D0] bg-[#F0FDF4]`:r===`warn`?`border-[#FDE68A] bg-[#FFFBEB]`:r===`alert`?`border-[#FECACA] bg-[#FEF2F2]`:`border-[var(--border)] bg-[var(--dash-panel-bg)]`}`,children:[(0,R.jsx)(`div`,{className:`text-[11px] font-bold text-[var(--text-mid)]`,children:e}),(0,R.jsx)(`div`,{className:`mt-2 text-[22px] font-extrabold tabular-nums leading-none text-[var(--text-dark)]`,children:t}),(0,R.jsx)(`div`,{className:`mt-1.5 text-[10px] text-[var(--text-mid)]`,children:n})]})}export{ne as FinanceReconciliation};