const { json, requireAdmin } = require('../../lib/vercel-utils');

module.exports = async function handler(req, res) {
  if (!requireAdmin(req)) {
    res.statusCode = 401;
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    return res.end(JSON.stringify({ error: 'Unauthorized' }));
  }
  return json(res, 410, {
    error: 'Database exports are disabled. Forms submit directly to Formspree (no Vercel database).'
  });
};

