let utcStr = "2026-03-19 15:30:00Z";
const [datePart, timePart] = utcStr.split(' ');
if (datePart && timePart) {
    const [y, m, d] = datePart.split('-');
    const [h, min, s] = timePart.split(':');
    const utcDate = new Date(Date.UTC(parseInt(y), parseInt(m) - 1, parseInt(d), parseInt(h), parseInt(min), parseInt(s || 0)));
    const mskDate = new Date(utcDate.getTime() + (3 * 3600000));
    
    if (!isNaN(mskDate.getTime())) {
        const py = mskDate.getUTCFullYear();
        const pm = String(mskDate.getUTCMonth() + 1).padStart(2, '0');
        const pd = String(mskDate.getUTCDate()).padStart(2, '0');
        const ph = String(mskDate.getUTCHours()).padStart(2, '0');
        const pmin = String(mskDate.getUTCMinutes()).padStart(2, '0');
        console.log(`${py}-${pm}-${pd} ${ph}:${pmin}`);
    } else {
        console.log("NaN mskDate");
    }
} else {
    console.log("no datePart and timePart");
}
