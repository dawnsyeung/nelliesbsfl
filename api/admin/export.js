const { sql, ensureTables, json, requireAdmin } = require('../../lib/vercel-utils');

function toCsv(rows) {
  const header = Object.keys(rows[0] || {});
  const escape = (v) => {
    if (v == null) return '';
    const s = typeof v === 'string' ? v : JSON.stringify(v);
    if (/[",\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
    return s;
  };
  const lines = [header.join(',')];
  rows.forEach((r) => {
    lines.push(header.map((h) => escape(r[h])).join(','));
  });
  return lines.join('\n');
}

module.exports = async function handler(req, res) {
  if (!requireAdmin(req)) {
    res.statusCode = 401;
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    return res.end(JSON.stringify({ error: 'Unauthorized' }));
  }

  await ensureTables();

  const db = String(req.query?.db || '');
  const format = String(req.query?.format || 'json').toLowerCase();

  let rows = [];
  let filename = '';

  if (db === 'form_submissions') {
    const r = await sql`SELECT * FROM form_submissions ORDER BY id DESC LIMIT 5000;`;
    rows = r.rows || [];
    filename = `form_submissions.${format === 'csv' ? 'csv' : 'json'}`;
  } else if (db === 'customer_registrations') {
    const r = await sql`SELECT * FROM customer_registrations ORDER BY id DESC LIMIT 5000;`;
    rows = r.rows || [];
    filename = `customer_registrations.${format === 'csv' ? 'csv' : 'json'}`;
  } else if (db === 'offer_board_requests') {
    const r = await sql`SELECT * FROM offer_board_requests ORDER BY created_at DESC LIMIT 5000;`;
    rows = r.rows || [];
    filename = `offer_board_requests.${format === 'csv' ? 'csv' : 'json'}`;
  } else {
    return json(res, 400, { error: 'Invalid db' });
  }

  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);

  if (format === 'csv') {
    res.statusCode = 200;
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    return res.end(rows.length ? toCsv(rows) : '');
  }

  res.statusCode = 200;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  return res.end(JSON.stringify(rows));
};

