/* Parse the import in the browser; no file is uploaded or stored on the server. */
function wcdpParseProductCSV(text) {
    'use strict';
    var rows = [], row = [], cell = '', quoted = false, closed = false;
    text = text.replace(/^\uFEFF/, '');
    for (var i = 0; i < text.length; i++) {
        var ch = text[i];
        if (quoted) {
            if (ch === '"') {
                if (text[i + 1] === '"') { cell += '"'; i++; }
                else { quoted = false; closed = true; }
            } else { cell += ch; }
        } else if (ch === ',' || ch === '\r' || ch === '\n') {
            row.push(cell); cell = ''; closed = false;
            if (ch !== ',') {
                rows.push(row); row = [];
                if (ch === '\r' && text[i + 1] === '\n') { i++; }
            }
        } else if (ch === '"') {
            if (cell !== '' || closed) { throw new Error('Invalid CSV quoting. Use the sample format.'); }
            quoted = true;
        } else if (closed) {
            if (ch !== ' ' && ch !== '\t') { throw new Error('Unexpected text after a quoted CSV value.'); }
        } else { cell += ch; }
    }
    if (quoted) { throw new Error('A quoted CSV value is not closed.'); }
    if (cell !== '' || row.length || closed) { row.push(cell); rows.push(row); }
    var header = rows.shift() || [];
    if (header.length !== 2 || header[0].trim().toUpperCase() !== 'ID' || header[1].trim().toUpperCase() !== 'SKU') {
        throw new Error('The first row must contain exactly two columns: ID,SKU.');
    }
    var ids = new Set(), skus = new Set();
    rows.forEach(function (values, index) {
        if (values.every(function (value) { return value.trim() === ''; })) { return; }
        if (values.length !== 2) { throw new Error('CSV row ' + (index + 2) + ' must have two columns.'); }
        var id = values[0].trim(), sku = values[1].trim();
        if (id) {
            if (!/^\d+$/.test(id) || !Number.isSafeInteger(Number(id)) || Number(id) <= 0) {
                throw new Error('CSV row ' + (index + 2) + ' has an invalid product ID. Use a positive whole number.');
            }
            ids.add(id.replace(/^0+/, ''));
        }
        if (/[\r\n]/.test(sku)) { throw new Error('CSV row ' + (index + 2) + ' has a SKU containing a line break.'); }
        if (sku) { skus.add(sku); }
    });
    if (!ids.size && !skus.size) { throw new Error('The CSV has no product IDs or SKUs to import.'); }
    return { ids: Array.from(ids), skus: Array.from(skus) };
}
