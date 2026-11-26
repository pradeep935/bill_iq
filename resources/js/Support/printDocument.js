import { formatDate } from './dateFormat.js';

// Printable copy of a quotation, order, challan or goods receipt. The browser's print window can also save it as PDF.
const KINDS = {
    Quotation: { date: 'quotation_date', qty: 'quantity', rate: 'unit_price', amount: 'total' },
    'Sales Order': { date: 'order_date', qty: 'ordered_quantity', rate: 'unit_price', amount: 'line_total' },
    // A challan travels with the goods: it carries quantities only, never the cost.
    'Delivery Challan': { date: 'challan_date', qty: 'dispatch_quantity' },
    'Purchase Order': { date: 'po_date', qty: 'ordered_quantity', rate: 'purchase_rate', amount: 'line_total' },
    'Goods Receipt Note': { date: 'receipt_date', qty: 'received_quantity', extra: [['Rejected', 'rejected_quantity'], ['Damaged', 'damaged_quantity']] },
};

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
const number = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const documentHtml = (title, number_, record = {}, business = '') => {
    const kind = KINDS[title] || KINDS.Quotation;
    const items = (record.items || []).filter((item) => item.product_id || item.product);
    const party = record.customer?.customer_name || record.customer_name || record.supplier?.supplier_name || record.supplier?.name || '';
    const date = record[kind.date];
    const columns = ['#', 'Item', 'Quantity', ...(kind.extra || []).map(([label]) => label), ...(kind.rate ? ['Rate', 'Amount'] : [])];
    const rows = items.map((item, index) => [
        index + 1,
        item.product?.name || item.product_name || '',
        number(item[kind.qty] ?? item.quantity),
        ...(kind.extra || []).map(([, key]) => number(item[key])),
        ...(kind.rate ? [money(item[kind.rate]), money(item[kind.amount])] : []),
    ]);
    const total = record.grand_total ?? record.total_amount ?? (kind.rate ? items.reduce((sum, item) => sum + Number(item[kind.amount] || 0), 0) : null);
    const cell = (tag, value, index) => `<${tag}${index > 1 ? ' class="n"' : ''}>${escape(value)}</${tag}>`;

    return `<!doctype html><meta charset="utf-8"><title>${escape(`${title} ${number_ || ''}`)}</title>
<style>body{font-family:Arial,sans-serif;padding:24px;color:#111}h1{margin:0;font-size:18px}h2{margin:2px 0 14px;font-size:15px}
p{margin:3px 0;font-size:13px}table{width:100%;border-collapse:collapse;margin-top:14px;font-size:13px}th,td{border:1px solid #999;padding:6px 8px;text-align:left}
.n{text-align:right}.total{text-align:right;font-weight:700;margin-top:10px}.sign{display:flex;justify-content:space-between;margin-top:60px;font-size:13px}</style>
<h1>${escape(business)}</h1><h2>${escape(title)}</h2>
<p><b>No:</b> ${escape(number_ || 'Draft')}</p>${date ? `<p><b>Date:</b> ${escape(formatDate(date))}</p>` : ''}${party ? `<p><b>Party:</b> ${escape(party)}</p>` : ''}
<table><thead><tr>${columns.map((c, i) => cell('th', c, i)).join('')}</tr></thead><tbody>
${rows.map((row) => `<tr>${row.map((value, i) => cell('td', value, i)).join('')}</tr>`).join('') || `<tr><td colspan="${columns.length}">No items</td></tr>`}
</tbody></table>${kind.rate ? `<p class="total">Total: Rs. ${escape(money(total))}</p>` : ''}${record.remarks || record.notes ? `<p><b>Remarks:</b> ${escape(record.remarks || record.notes)}</p>` : ''}
<div class="sign"><span>Receiver’s signature</span><span>Authorised signature</span></div>`;
};

export const printDocument = (title, number_, record, business) => {
    const win = window.open('', '_blank');
    if (!win) {
        window.alert('Allow pop-ups for this site to print.');
        return;
    }
    win.document.write(documentHtml(title, number_, record, business));
    win.document.close();
    win.focus();
    win.print();
};
