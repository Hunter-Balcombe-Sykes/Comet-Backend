// Partna brand-scan collector — runs INSIDE the scanned page (injected via
// Browser Run addScriptTag through the partna-brand-scan Worker). Collects
// rendered-style EVIDENCE only; every conclusion (tiers, confidence, accent
// choice) is drawn in EvidenceConclusions. Sections are independently
// try/caught: partial evidence beats none. Output is stashed as JSON in a data
// attribute on <html> — present in the returned snapshot HTML, invisible in
// the screenshot. THIS file is the canonical copy (a dev twin lives in
// partna-brand-scan/dev/ for live tuning; this one wins on drift).
(function () {
  var STASH_ATTR = 'data-partna-scan';
  var MAX_STASH = 30000;
  var errors = [];

  function guard(label, fn, fallback) {
    try { return fn(); } catch (e) { errors.push(label + ':' + String(e).slice(0, 80)); return fallback; }
  }

  function cs(el) { return window.getComputedStyle(el); }

  function visible(el) {
    if (!el || !el.getClientRects || el.getClientRects().length === 0) return false;
    var r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    if (r.top > window.innerHeight * 2) return false; // first ~2 viewports only
    var s = cs(el);
    return s.visibility !== 'hidden' && s.display !== 'none' && parseFloat(s.opacity || '1') > 0.05;
  }

  function px(v) { var n = parseFloat(v); return isFinite(n) ? Math.round(n * 100) / 100 : null; }

  // Longest duration token of "0.3s, 0s" → ms.
  function ms(v) {
    if (!v) return null;
    var best = 0;
    String(v).split(',').forEach(function (t) {
      t = t.trim();
      var n = parseFloat(t);
      if (!isFinite(n)) return;
      var m = /ms$/i.test(t) ? n : n * 1000;
      if (m > best) best = m;
    });
    return best;
  }

  function alphaOf(color) {
    var m = /rgba?\(\s*[\d.]+\s*[, ]\s*[\d.]+\s*[, ]\s*[\d.]+\s*(?:[,/]\s*([\d.%]+))?\s*\)/.exec(color || '');
    if (!m) return color && color !== 'transparent' ? 1 : 0;
    if (m[1] === undefined) return 1;
    var a = parseFloat(m[1]);
    return /%$/.test(m[1]) ? a / 100 : a;
  }

  // Effective painted background: walk up past transparent layers; note when a
  // background-image is in play anywhere along the chain.
  function effBg(el) {
    var hadImage = false;
    var node = el;
    for (var i = 0; node && i < 12; i++) {
      var s = cs(node);
      if (s.backgroundImage && s.backgroundImage !== 'none') hadImage = true;
      var c = s.backgroundColor;
      if (c && alphaOf(c) >= 0.1) return { color: c, image: hadImage };
      node = node.parentElement;
    }
    return { color: null, image: hadImage };
  }

  function abs(href) {
    try { return href ? new URL(href, window.location.href).href : null; } catch (e) { return null; }
  }

  function take(list, n, fn) {
    var out = [];
    for (var i = 0; i < list.length && out.length < n; i++) {
      if (!visible(list[i])) continue;
      var v = guard('take', function () { return fn(list[i]); }, null);
      if (v) out.push(v);
    }
    return out;
  }

  // ── page-level ─────────────────────────────────────────────────────────
  var body = document.body || document.documentElement;
  var page = guard('page', function () {
    var bs = cs(body);
    var main = document.querySelector('main') || (function () {
      var best = null, bestArea = 0;
      var kids = body.children || [];
      for (var i = 0; i < kids.length; i++) {
        if (!visible(kids[i])) continue;
        var r = kids[i].getBoundingClientRect();
        var area = r.width * r.height;
        if (area > bestArea) { bestArea = area; best = kids[i]; }
      }
      return best;
    })();
    var bodyEff = effBg(body);
    // Some builders (Wix) paint the page on a wrapper BELOW body — when the
    // upward walk finds nothing, descend through the largest visible child
    // chain looking for the first painted layer.
    var deepBg = null;
    if (bodyEff.color === null) {
      var node = body;
      for (var depth = 0; depth < 4 && node; depth++) {
        var kids2 = node.children || [];
        var bestChild = null, bestArea2 = 0;
        for (var k = 0; k < kids2.length; k++) {
          if (!visible(kids2[k])) continue;
          var kr = kids2[k].getBoundingClientRect();
          if (kr.width * kr.height > bestArea2) { bestArea2 = kr.width * kr.height; bestChild = kids2[k]; }
        }
        if (!bestChild) break;
        var kc = cs(bestChild).backgroundColor;
        if (kc && alphaOf(kc) >= 0.9) { deepBg = kc; break; }
        node = bestChild;
      }
    }
    return {
      htmlBg: cs(document.documentElement).backgroundColor,
      bodyBg: bodyEff.color,
      bodyBgImage: bodyEff.image,
      deepBg: deepBg,
      mainBg: main ? effBg(main).color : null,
      bodyColor: bs.color,
      bodyFont: (bs.fontFamily || '').slice(0, 200),
      bodyFontSize: px(bs.fontSize),
      bodyFontWeight: bs.fontWeight,
      lineHeight: bs.lineHeight,
    };
  }, {});

  var headerEl = guard('headerEl', function () {
    var candidates = document.querySelectorAll('header, [role="banner"], nav');
    for (var i = 0; i < candidates.length; i++) if (visible(candidates[i])) return candidates[i];
    return null;
  }, null);

  var header = guard('header', function () {
    if (!headerEl) return null;
    var r = headerEl.getBoundingClientRect();
    return { bg: effBg(headerEl).color, top: Math.round(r.top), height: Math.round(r.height) };
  }, null);

  // ── typography evidence ────────────────────────────────────────────────
  var copy = guard('copy', function () {
    return take(document.querySelectorAll('p, li'), 12, function (el) {
      if ((el.textContent || '').trim().length < 40) return null; // real copy, not crumbs
      var s = cs(el);
      return { size: px(s.fontSize), weight: s.fontWeight, family: (s.fontFamily || '').slice(0, 120), color: s.color };
    });
  }, []);

  var headings = guard('headings', function () {
    var out = [];
    ['h1', 'h2'].forEach(function (tag) {
      var els = document.querySelectorAll(tag);
      for (var i = 0; i < els.length; i++) {
        if (!visible(els[i])) continue;
        var s = cs(els[i]);
        out.push({ tag: tag, family: (s.fontFamily || '').slice(0, 120), weight: s.fontWeight, size: px(s.fontSize) });
        break;
      }
    });
    return out;
  }, []);

  // ── interactive evidence ───────────────────────────────────────────────
  var BTN_SEL = 'button, [role="button"], a.button, .btn, [class*="btn"], input[type="submit"], a[class*="button"]';
  var buttons = guard('buttons', function () {
    return take(document.querySelectorAll(BTN_SEL), 12, function (el) {
      var s = cs(el);
      var r = el.getBoundingClientRect();
      return {
        bg: s.backgroundColor,
        color: s.color,
        radius: px(s.borderTopLeftRadius),
        height: Math.round(r.height),
        transition: ms(s.transitionDuration),
        cls: ((el.className && String(el.className)) || el.tagName.toLowerCase()).replace(/\s+/g, ' ').trim().slice(0, 60),
      };
    });
  }, []);

  var links = guard('links', function () {
    return take(document.querySelectorAll('a[href]'), 12, function (el) {
      if (el.matches && el.matches(BTN_SEL)) return null;
      if ((el.textContent || '').trim().length < 2) return null;
      return { color: cs(el).color };
    });
  }, []);

  var sections = guard('sections', function () {
    return take(document.querySelectorAll('section, article, [class*="card"], [class*="container"], [class*="wrapper"]'), 15, function (el) {
      var s = cs(el);
      return {
        padT: px(s.paddingTop), padB: px(s.paddingBottom), padL: px(s.paddingLeft), padR: px(s.paddingRight),
        gap: px(s.rowGap) || px(s.columnGap),
        radius: px(s.borderTopLeftRadius),
        transition: ms(s.transitionDuration),
      };
    });
  }, []);

  // ── resolved custom properties (the browser resolves var() chains) ─────
  var rootVars = guard('rootVars', function () {
    var names = [
      '--color-background', '--color-foreground', '--color-accent', '--color-primary',
      '--color-button', '--color-button-background', '--color-brand',
      '--brand', '--brand-color', '--primary', '--primary-color',
      '--accent', '--accent-color', '--theme-color', '--background', '--background-color',
    ];
    var out = {};
    var rootStyle = cs(document.documentElement);
    var bodyStyle = cs(body);
    names.forEach(function (n) {
      var v = (rootStyle.getPropertyValue(n) || bodyStyle.getPropertyValue(n) || '').trim();
      if (v) out[n] = v.slice(0, 60);
    });
    return out;
  }, {});

  // ── document metadata ──────────────────────────────────────────────────
  function metaContent(sel) {
    var el = document.querySelector(sel);
    return el ? (el.getAttribute('content') || null) : null;
  }
  var meta = guard('meta', function () {
    var icons = [];
    var iconLinks = document.querySelectorAll('link[rel*="icon"]');
    for (var i = 0; i < iconLinks.length && icons.length < 8; i++) {
      var l = iconLinks[i];
      icons.push({ rel: l.getAttribute('rel'), href: abs(l.getAttribute('href')), sizes: l.getAttribute('sizes'), type: l.getAttribute('type') });
    }
    var manifest = document.querySelector('link[rel="manifest"]');
    return {
      themeColor: metaContent('meta[name="theme-color"]'),
      colorScheme: metaContent('meta[name="color-scheme"]'),
      manifest: manifest ? abs(manifest.getAttribute('href')) : null,
      icons: icons,
      ogImage: abs(metaContent('meta[property="og:image"]') || metaContent('meta[name="og:image"]')),
      twitterImage: abs(metaContent('meta[name="twitter:image"]') || metaContent('meta[property="twitter:image"]')),
    };
  }, {});

  // ── logo candidates (rendered header marks) ────────────────────────────
  var logos = guard('logos', function () {
    var out = [];
    var seen = {};
    var scope = headerEl || document;
    var nodes = scope.querySelectorAll('img, svg');
    // Also site-wide logo-hinted images outside the header (top band only).
    var hinted = document.querySelectorAll('img[class*="logo" i], img[id*="logo" i], img[alt*="logo" i], img[src*="logo" i], svg[class*="logo" i], a[class*="logo" i] img, a[class*="logo" i] svg, [class*="logo" i] > img, [class*="logo" i] > svg');
    var all = [];
    var i;
    for (i = 0; i < nodes.length; i++) all.push(nodes[i]);
    for (i = 0; i < hinted.length; i++) all.push(hinted[i]);

    for (i = 0; i < all.length && out.length < 6; i++) {
      var el = all[i];
      if (!visible(el)) continue;
      var r = el.getBoundingClientRect();
      if (r.top > 250) continue;               // header band only
      if (r.width * r.height < 400) continue;  // ignore glyph-sized noise
      var hint = ((String(el.className) + ' ' + (el.id || '') + ' ' + (el.getAttribute('alt') || '') + ' ' + (el.getAttribute('src') || '')).toLowerCase().indexOf('logo') !== -1);
      if (el.tagName.toLowerCase() === 'img') {
        var src = abs(el.currentSrc || el.getAttribute('src'));
        if (!src || seen[src]) continue;
        seen[src] = true;
        out.push({ kind: 'img', src: src, natW: el.naturalWidth || null, natH: el.naturalHeight || null, w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), alt: (el.getAttribute('alt') || '').slice(0, 80), hint: hint, inHeader: !!(headerEl && headerEl.contains(el)) });
      } else {
        var outer = el.outerHTML || '';
        var key = 'svg:' + outer.slice(0, 120);
        if (seen[key]) continue;
        seen[key] = true;
        out.push({ kind: 'inline-svg', svg: outer.length <= 60000 ? outer : null, svgLen: outer.length, w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), viewBox: el.getAttribute('viewBox'), hint: hint, inHeader: !!(headerEl && headerEl.contains(el)) });
      }
    }
    return out;
  }, []);

  // ── assemble + stash (progressively shed weight to fit the cap) ────────
  var evidence = {
    v: 1,
    ok: true,
    url: window.location.href,
    title: (document.title || '').slice(0, 120),
    viewport: { w: window.innerWidth, h: window.innerHeight },
    page: page,
    header: header,
    copy: copy,
    headings: headings,
    buttons: buttons,
    links: links,
    sections: sections,
    rootVars: rootVars,
    meta: meta,
    logos: logos,
    errors: errors.slice(0, 10),
  };

  var s = '';
  guard('stash', function () {
    s = JSON.stringify(evidence);
    if (s.length > MAX_STASH) {
      for (var i = 0; i < evidence.logos.length; i++) { evidence.logos[i].svg = null; }
      s = JSON.stringify(evidence);
    }
    if (s.length > MAX_STASH) {
      evidence.sections = evidence.sections.slice(0, 6);
      evidence.copy = evidence.copy.slice(0, 6);
      evidence.buttons = evidence.buttons.slice(0, 6);
      evidence.links = evidence.links.slice(0, 6);
      s = JSON.stringify(evidence);
    }
    if (s.length > MAX_STASH) s = s.slice(0, MAX_STASH); // last resort; PHP treats unparseable as csp/partial
    return true;
  }, false);

  document.documentElement.setAttribute(STASH_ATTR, s || JSON.stringify({ v: 1, ok: false, errors: errors.slice(0, 10) }));
})();
