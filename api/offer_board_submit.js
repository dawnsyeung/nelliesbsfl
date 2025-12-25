const {
  parseBody,
  isLikelyBrowser,
  json,
  redirect,
  safeString,
  forwardToFormspree
} = require('../lib/vercel-utils');

module.exports = async function handler(req, res) {
  if (req.method === 'OPTIONS') {
    res.statusCode = 204;
    return res.end();
  }
  if (req.method !== 'POST') {
    return json(res, 405, { error: 'Method not allowed' });
  }

  const payload = await parseBody(req);

  const gotcha = safeString(payload._gotcha);
  const next = safeString(payload._next) || '/offer-board-thanks.html';
  const referer = safeString(req.headers.referer) || '/orders.html#submit';
  if (gotcha) {
    if (isLikelyBrowser(req)) return redirect(res, next);
    return json(res, 200, { success: true, spam: true });
  }

  const email = safeString(payload.email);
  if (!email) {
    if (isLikelyBrowser(req)) return redirect(res, referer);
    return json(res, 422, { error: 'Email is required.', errors: { email: 'Email is required.' } });
  }

  const createdAtUtc = new Date().toISOString();
  const fwd = await forwardToFormspree(payload, {
    captured_at_utc: createdAtUtc,
    captured_by: 'vercel_offer_board_submit_passthrough'
  });

  if (isLikelyBrowser(req)) return redirect(res, next);
  return json(res, fwd.ok ? 200 : 502, fwd.ok ? { success: true } : { error: fwd.error || 'Failed to notify via Formspree.' });
};

