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
  const next = safeString(payload._next) || '/customer-registration-thank-you.html';
  const referer = safeString(req.headers.referer) || '/customer-registration.html';
  const ip = safeString(req.headers['x-forwarded-for'] || '').split(',')[0].trim() || safeString(req.socket?.remoteAddress);
  const userAgent = safeString(req.headers['user-agent']);

  if (gotcha) {
    if (isLikelyBrowser(req)) return redirect(res, next);
    return json(res, 200, { success: true, spam: true });
  }

  const primaryEmail = safeString(payload.primary_contact_email);
  if (!primaryEmail) {
    if (isLikelyBrowser(req)) return redirect(res, referer);
    return json(res, 422, { error: 'Primary Contact Email is required.', errors: { primary_contact_email: 'Required.' } });
  }

  const legalBusinessName = safeString(payload.legal_business_name);
  let insertedId = null;

  try {
    const result = await sql`
      INSERT INTO customer_registrations (legal_business_name, primary_contact_email, ip, user_agent, payload)
      VALUES (${legalBusinessName || null}, ${primaryEmail}, ${ip || null}, ${userAgent || null}, ${payload})
      RETURNING id;
    `;
    insertedId = result.rows?.[0]?.id ?? null;
  } catch (e) {
    if (isLikelyBrowser(req)) return redirect(res, referer);
    return json(res, 500, { error: 'Server error: unable to save submission.' });
  }

  const createdAtUtc = new Date().toISOString();
  const fwd = await forwardToFormspree(payload, {
    form_name: safeString(payload.form_name) || 'Customer Registration',
    submission_id: insertedId ? String(insertedId) : '',
    captured_at_utc: createdAtUtc,
    captured_by: 'vercel_customer_registration'
  });

  try {
    await sql`
      UPDATE customer_registrations
      SET forwarded = ${Boolean(fwd.ok)},
          forward_status = ${fwd.status || null},
          forward_error = ${fwd.ok ? null : (fwd.error || 'Forward failed')}
      WHERE id = ${insertedId};
    `;
  } catch (e) {
    // non-blocking
  }

  if (isLikelyBrowser(req)) return redirect(res, next);
  return json(res, fwd.ok ? 200 : 502, fwd.ok ? { success: true, id: insertedId } : { error: 'Saved, but failed to notify via Formspree.', id: insertedId });
};

