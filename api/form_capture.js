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
  const next = safeString(payload._next);
  const referer = safeString(req.headers.referer);
  const ip = safeString(req.headers['x-forwarded-for'] || '').split(',')[0].trim() || safeString(req.socket?.remoteAddress);
  const userAgent = safeString(req.headers['user-agent']);

  if (gotcha) {
    if (isLikelyBrowser(req)) return redirect(res, next || '/');
    return json(res, 200, { success: true, spam: true });
  }

  const formName = safeString(payload.form_name) || safeString(payload.request_type) || 'Website form submission';
  const name = safeString(payload.name) || safeString(payload.primary_contact_name);
  const email = safeString(payload.email) || safeString(payload.primary_contact_email);

  let insertedId = null;
  try {
    const result = await sql`
      INSERT INTO form_submissions (form_name, name, email, referer, ip, user_agent, payload)
      VALUES (${formName}, ${name || null}, ${email || null}, ${referer || null}, ${ip || null}, ${userAgent || null}, ${payload})
      RETURNING id;
    `;
    insertedId = result.rows?.[0]?.id ?? null;
  } catch (e) {
    if (isLikelyBrowser(req)) return redirect(res, referer || '/');
    return json(res, 500, { error: 'Server error: unable to save submission.' });
  }

  const createdAtUtc = new Date().toISOString();
  const fwd = await forwardToFormspree(payload, {
    submission_id: insertedId ? String(insertedId) : '',
    captured_at_utc: createdAtUtc,
    captured_by: 'vercel_form_capture'
  });

  try {
    await sql`
      UPDATE form_submissions
      SET forwarded = ${Boolean(fwd.ok)},
          forward_status = ${fwd.status || null},
          forward_error = ${fwd.ok ? null : (fwd.error || 'Forward failed')}
      WHERE id = ${insertedId};
    `;
  } catch (e) {
    // non-blocking
  }

  if (!fwd.ok) {
    if (isLikelyBrowser(req)) return redirect(res, referer || '/');
    return json(res, 502, { error: 'Saved, but failed to notify via Formspree.', id: insertedId });
  }

  if (isLikelyBrowser(req)) return redirect(res, next || '/');
  return json(res, 200, { success: true, id: insertedId });
};

