const crypto = require('crypto');
const {
  sql,
  ensureTables,
  parseBody,
  isLikelyBrowser,
  json,
  redirect,
  safeString,
  forwardToFormspree
} = require('../lib/vercel-utils');

function uuidv4() {
  return crypto.randomUUID ? crypto.randomUUID() : ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
    (c ^ crypto.randomBytes(1)[0] & 15 >> c / 4).toString(16)
  );
}

module.exports = async function handler(req, res) {
  if (req.method === 'OPTIONS') {
    res.statusCode = 204;
    return res.end();
  }
  if (req.method !== 'POST') {
    return json(res, 405, { error: 'Method not allowed' });
  }

  await ensureTables();
  const payload = await parseBody(req);

  const gotcha = safeString(payload._gotcha);
  const next = safeString(payload._next) || '/offer-board-thanks.html';
  const referer = safeString(req.headers.referer) || '/orders.html#submit';
  const ip = safeString(req.headers['x-forwarded-for'] || '').split(',')[0].trim() || safeString(req.socket?.remoteAddress);
  const userAgent = safeString(req.headers['user-agent']);

  if (gotcha) {
    if (isLikelyBrowser(req)) return redirect(res, next);
    return json(res, 200, { success: true, spam: true });
  }

  const email = safeString(payload.email);
  if (!email) {
    if (isLikelyBrowser(req)) return redirect(res, referer);
    return json(res, 422, { error: 'Email is required.', errors: { email: 'Email is required.' } });
  }

  const requestId = uuidv4();

  const companyName = safeString(payload.company_name);
  const contactName = safeString(payload.contact_name);
  const shippingRegion = safeString(payload.shipping_region);
  const grade = safeString(payload.grade);
  const format = safeString(payload.format);

  const quantityLbs = Number.isFinite(Number(payload.quantity_lbs)) ? parseInt(String(payload.quantity_lbs), 10) : null;
  const targetPrice = Number.isFinite(Number(payload.target_price_per_lb)) ? Number(payload.target_price_per_lb) : null;

  try {
    await sql`
      INSERT INTO offer_board_requests (
        id, status, company_name, contact_name, email,
        shipping_region, grade, format, quantity_lbs, target_price_per_lb,
        payload, ip, user_agent
      ) VALUES (
        ${requestId}, 'submitted', ${companyName || null}, ${contactName || null}, ${email},
        ${shippingRegion || null}, ${grade || null}, ${format || null}, ${quantityLbs}, ${targetPrice},
        ${payload}, ${ip || null}, ${userAgent || null}
      );
    `;
  } catch (e) {
    if (isLikelyBrowser(req)) return redirect(res, referer);
    return json(res, 500, { error: 'Server error: unable to save your request right now.' });
  }

  const createdAtUtc = new Date().toISOString();
  const fwd = await forwardToFormspree(payload, {
    request_id: requestId,
    captured_at_utc: createdAtUtc,
    captured_by: 'vercel_offer_board_submit'
  });

  try {
    await sql`
      UPDATE offer_board_requests
      SET forwarded = ${Boolean(fwd.ok)},
          forward_status = ${fwd.status || null},
          forward_error = ${fwd.ok ? null : (fwd.error || 'Forward failed')}
      WHERE id = ${requestId};
    `;
  } catch (e) {
    // non-blocking
  }

  if (isLikelyBrowser(req)) return redirect(res, next);
  return json(res, fwd.ok ? 200 : 502, fwd.ok ? { success: true, id: requestId } : { error: 'Saved, but failed to notify via Formspree.', id: requestId });
};

