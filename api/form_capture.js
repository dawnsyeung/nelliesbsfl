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
  const next = safeString(payload._next);
  const referer = safeString(req.headers.referer);

  if (gotcha) {
    if (isLikelyBrowser(req)) return redirect(res, next || '/');
    return json(res, 200, { success: true, spam: true });
  }

  const createdAtUtc = new Date().toISOString();
  const fwd = await forwardToFormspree(payload, {
    captured_at_utc: createdAtUtc,
    captured_by: 'vercel_form_capture_passthrough'
  });

  if (!fwd.ok) {
    if (isLikelyBrowser(req)) return redirect(res, referer || '/');
    return json(res, 502, { error: fwd.error || 'Failed to notify via Formspree.' });
  }

  if (isLikelyBrowser(req)) return redirect(res, next || '/');
  return json(res, 200, { success: true });
};

