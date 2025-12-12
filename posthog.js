/* PostHog analytics */
(function (t, e) {
  var o, n, p, r;
  if (e.__SV) return;
  window.posthog = e;
  e._i = [];
  e.init = function (i, s, a) {
    function g(t, e) {
      var o = e.split('.');
      if (o.length === 2) {
        t = t[o[0]];
        e = o[1];
      }
      t[e] = function () {
        t.push([e].concat(Array.prototype.slice.call(arguments, 0)));
      };
    }

    p = t.createElement('script');
    p.type = 'text/javascript';
    p.async = true;
    p.src = s.api_host + '/static/array.js';
    r = t.getElementsByTagName('script')[0];
    r.parentNode.insertBefore(p, r);

    var u = e;
    if (a !== undefined) {
      u = e[a] = [];
    } else {
      a = 'posthog';
    }

    u.people = u.people || [];

    u.toString = function (t) {
      var e = 'posthog';
      if (a !== 'posthog') e += '.' + a;
      if (!t) e += ' (stub)';
      return e;
    };

    u.people.toString = function () {
      return u.toString(1) + '.people (stub)';
    };

    o =
      'capture identify alias people.set people.set_once people.unset people.increment people.append people.remove group identify_group get_group reset debug on off'.split(
        ' ',
      );

    for (n = 0; n < o.length; n++) {
      g(u, o[n]);
    }

    e._i.push([i, s, a]);
  };

  e.__SV = 1;
})(document, window.posthog || []);

posthog.init('phc_mWecd5gQP9g3xt2yI4VbfuDQg9rCcTNhBZnNPmr09x6', {
  api_host: 'https://app.posthog.com',
  autocapture: true,
  capture_pageview: true,
});
