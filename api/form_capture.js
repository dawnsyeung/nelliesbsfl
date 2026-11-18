const {
  parseBody,
  isLikelyBrowser,
  json,
  redirect,
  safeString,
  forwardToFormspree
} = require('../lib/vercel-utils');

const DEFAULT_AUTO_REPLY = 'Hi [Name], we received your inquiry and will be in touch within 1 business day. To make sure you receive our reply, please reply to this email with "Got it!" — this ensures our emails reach your inbox.';

function firstNonEmpty(payload, names) {
  for (const name of names) {
    const value = safeString(payload[name]);
    if (value) return value;
  }
  return '';
}

function personalizeMessage(message, name) {
  const greetingName = name || 'there';
  return safeString(message || DEFAULT_AUTO_REPLY).replace(/\[Name\]/g, greetingName);
}

async function sendAutoReply(payload) {
  const apiKey = safeString(process.env.SENDGRID_API_KEY);
  const fromEmail = safeString(process.env.SENDGRID_FROM_EMAIL || process.env.INQUIRY_FROM_EMAIL);
  const fromName = safeString(process.env.SENDGRID_FROM_NAME || "Nellie's BSFL");
  const replyTo = safeString(process.env.INQUIRY_REPLY_TO_EMAIL || fromEmail);
  const email = firstNonEmpty(payload, ['email', 'contact_email', 'primary_contact_email']);
  const name = firstNonEmpty(payload, ['name', 'contact_name', 'primary_contact_name']);

  if (!email) {
    return { ok: false, skipped: true, reason: 'missing_submitter_email' };
  }

  if (!apiKey || !fromEmail) {
    return { ok: false, skipped: true, reason: 'sendgrid_not_configured' };
  }

  const message = personalizeMessage(payload.auto_reply_message || DEFAULT_AUTO_REPLY, name);
  const body = {
    personalizations: [
      {
        to: [{ email, name: name || undefined }],
        subject: "We received your Nellie's BSFL inquiry"
      }
    ],
    from: { email: fromEmail, name: fromName },
    reply_to: replyTo ? { email: replyTo, name: fromName } : undefined,
    content: [
      {
        type: 'text/plain',
        value: `${message}\n\nPrefer a text? Reply with your mobile number or call/text ${process.env.INQUIRY_PHONE || '+1-503-555-0145'}.\n\nNellie's BSFL`
      }
    ]
  };

  try {
    const resp = await fetch('https://api.sendgrid.com/v3/mail/send', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${apiKey}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body)
    });

    if (resp.status >= 200 && resp.status < 300) {
      return { ok: true, skipped: false, reason: '' };
    }

    return { ok: false, skipped: false, reason: `sendgrid_returned_${resp.status}` };
  } catch (error) {
    return {
      ok: false,
      skipped: false,
      reason: error && error.message ? String(error.message) : 'sendgrid_request_failed'
    };
  }
}

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
    captured_by: 'vercel_form_capture_passthrough',
    auto_reply_requested: 'yes',
    auto_reply_message: personalizeMessage(payload.auto_reply_message || DEFAULT_AUTO_REPLY, firstNonEmpty(payload, ['name', 'contact_name', 'primary_contact_name'])),
    sms_followup_available: 'yes'
  });

  if (!fwd.ok) {
    if (isLikelyBrowser(req)) return redirect(res, referer || '/');
    return json(res, 502, { error: fwd.error || 'Failed to notify via Formspree.' });
  }

  const autoReply = await sendAutoReply(payload);

  if (isLikelyBrowser(req)) return redirect(res, next || '/');
  return json(res, 200, { success: true, auto_reply: autoReply });
};

