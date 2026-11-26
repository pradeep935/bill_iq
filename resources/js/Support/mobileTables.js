// On a phone every list table is shown as cards. This names each cell after its column heading
// and marks the table; the styles in app.css do the rest. Tables with their own phone layout are skipped.
const OWN_LAYOUT = '.bq-table, .crud-table, [data-no-cards]';

const mark = (table) => {
    if (table.matches(OWN_LAYOUT) || !table.tHead || table.tHead.rows.length !== 1) return;
    const headings = [...table.tHead.rows[0].cells];
    if (headings.length < 4 || headings.some((cell) => cell.colSpan > 1)) return;
    const names = headings.map((cell) => cell.textContent.replace('*', '').trim());
    table.setAttribute('data-cards', '');
    [...table.tBodies, ...(table.tFoot ? [table.tFoot] : [])].forEach((body) => {
        [...body.rows].forEach((row) => {
            let column = 0;
            [...row.cells].forEach((cell) => {
                const name = cell.colSpan > 1 ? '' : (names[column] || '');
                if (cell.getAttribute('data-label') !== name) cell.setAttribute('data-label', name);
                column += cell.colSpan;
            });
        });
    });
};

export default function watchTables() {
    let queued = false;
    const run = () => {
        queued = false;
        document.querySelectorAll('.bill-main table').forEach(mark);
    };
    const queue = () => {
        if (queued) return;
        queued = true;
        window.requestAnimationFrame(run);
    };
    new MutationObserver(queue).observe(document.body, { childList: true, subtree: true, characterData: true });
    queue();
}
