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
  const next = safeString(payload._next) || '/customer-registration-thank-you.html';
  const referer = safeString(req.headers.referer) || '/customer-registration.html';
  if (gotcha) {
    if (isLikelyBrowser(req)) return redirect(res, next);
    return json(res, 200, { success: true, spam: true });
  }

  const primaryEmail = safeString(payload.primary_contact_email);
  if (!primaryEmail) {
    if (isLikelyBrowser(req)) return redirect(res, referer);
    return json(res, 422, { error: 'Primary Contact Email is required.', errors: { primary_contact_email: 'Required.' } });
  }

  const createdAtUtc = new Date().toISOString();
  const fwd = await forwardToFormspree(payload, {
    form_name: safeString(payload.form_name) || 'Customer Registration',
    captured_at_utc: createdAtUtc,
    captured_by: 'vercel_customer_registration_passthrough'
  });

  if (isLikelyBrowser(req)) return redirect(res, next);
  return json(res, fwd.ok ? 200 : 502, fwd.ok ? { success: true } : { error: fwd.error || 'Failed to notify via Formspree.' });
};

