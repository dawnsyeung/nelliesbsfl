const Busboy = require('busboy');

function isLikelyBrowser(req) {
  const accept = String(req.headers?.accept || '');
  return accept.includes('text/html') || accept.includes('application/xhtml+xml');
}

function json(res, status, payload) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify(payload));
}

function redirect(res, location) {
  res.statusCode = 302;
  res.setHeader('Location', location || '/');
  res.end();
}

function safeString(v) {
  return typeof v === 'string' ? v.trim() : (v == null ? '' : String(v).trim());
}

async function parseBody(req) {
  const contentType = String(req.headers['content-type'] || '').toLowerCase();

  if (contentType.includes('application/json')) {
    const raw = await readRaw(req);
    if (!raw) return {};
    try {
      return JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }

  if (contentType.includes('application/x-www-form-urlencoded')) {
    const raw = await readRaw(req);
    const params = new URLSearchParams(raw);
    const out = {};
    for (const [k, v] of params.entries()) {
      if (Object.prototype.hasOwnProperty.call(out, k)) {
        out[k] = Array.isArray(out[k]) ? [...out[k], v] : [out[k], v];
      } else {
        out[k] = v;
      }
    }
    return out;
  }

  if (contentType.includes('multipart/form-data')) {
    return await parseMultipart(req);
  }

  const raw = await readRaw(req);
  if (!raw) return {};
  const params = new URLSearchParams(raw);
  const out = {};
  for (const [k, v] of params.entries()) out[k] = v;
  return out;
}

function readRaw(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.setEncoding('utf8');
    req.on('data', (chunk) => (data += chunk));
    req.on('end', () => resolve(data));
    req.on('error', reject);
  });
}

function parseMultipart(req) {
  return new Promise((resolve, reject) => {
    const bb = Busboy({ headers: req.headers, limits: { files: 0 } });
    const out = {};

    bb.on('field', (name, value) => {
      const k = String(name);
      const v = typeof value === 'string' ? value : String(value);
      if (Object.prototype.hasOwnProperty.call(out, k)) {
        out[k] = Array.isArray(out[k]) ? [...out[k], v] : [out[k], v];
      } else {
        out[k] = v;
      }
    });
    bb.on('file', (name, stream) => {
      stream.resume();
    });
    bb.on('error', reject);
    bb.on('finish', () => resolve(out));
    req.pipe(bb);
  });
}

function requireAdmin(req) {
  const expected = process.env.ADMIN_TOKEN || '';
  const provided = String(req.headers['x-admin-token'] || '');
  return expected !== '' && provided !== '' && timingSafeEqual(expected, provided);
}

function timingSafeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  const aBuf = Buffer.from(a);
  const bBuf = Buffer.from(b);
  if (aBuf.length !== bBuf.length) return false;
  return require('crypto').timingSafeEqual(aBuf, bBuf);
}

async function forwardToFormspree(payload, meta) {
  const enabled = String(process.env.FORMSPREE_FORWARDING_ENABLED || '1').toLowerCase();
  const forwardingDisabled = enabled === '0' || enabled === 'false' || enabled === 'no';
  if (forwardingDisabled) return { ok: true, status: 0, error: '' };

  const endpoint = String(process.env.FORMSPREE_ENDPOINT || 'https://formspree.io/f/xjkeljzv').trim();
  if (!/^https:\/\/formspree\.io\/f\/[A-Za-z0-9]+$/.test(endpoint)) {
    return { ok: false, status: 0, error: 'Invalid FORMSPREE_ENDPOINT' };
  }

  const forwarded = { ...payload, ...(meta || {}) };
  const body = new URLSearchParams();
  Object.entries(forwarded).forEach(([k, v]) => {
    if (v == null) return;
    if (Array.isArray(v)) {
      v.forEach((vv) => body.append(k, String(vv)));
      return;
    }
    body.append(k, String(v));
  });

  try {
    const resp = await fetch(endpoint, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    });
    const ok = resp.ok;
    return { ok, status: resp.status, error: ok ? '' : `Formspree returned ${resp.status}` };
  } catch (e) {
    return { ok: false, status: 0, error: e && e.message ? String(e.message) : 'Forward failed' };
  }
}

module.exports = {
  parseBody,
  isLikelyBrowser,
  json,
  redirect,
  safeString,
  requireAdmin,
  forwardToFormspree
};

