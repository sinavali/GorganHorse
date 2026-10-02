/*
 * tests/Frontend/dom-stub.js
 *
 * Purpose:
 *   A dependency-free DOM stub, just large enough to execute the panel's real
 *   UI helpers (UI.filterBar, UI.initPicks) outside a browser. The project
 *   ships no npm dependencies and must not add any, so instead of jsdom we
 *   parse the flat markup these helpers generate and model the handful of
 *   DOM APIs they touch.
 *
 * What is modelled:
 *   - element tree with tag/attrs/children and a class list
 *   - innerHTML (re-parsed), appendChild, insertAdjacentHTML
 *   - addEventListener / dispatchEvent, dataset (data-* reflection)
 *   - querySelector / querySelectorAll for tag, .class, #id, [attr] and
 *     [attr="value"] selectors, including descendant chains ("tag .class")
 *
 * What is NOT modelled (and is not needed by these helpers):
 *   - layout, focus rings, animation frames, real event dispatching.
 */
'use strict';

/** Tags that never have a closing tag in the generated markup. */
var VOID = { input: 1, img: 1, br: 1, hr: 1, meta: 1, link: 1, source: 1 };

/** Create an element node. */
function makeNode(tag, attrs) {
  var n = {
    tag: String(tag || 'div').toLowerCase(),
    attrs: attrs || {},
    children: [],
    /* A real DOM mirrors data-* attributes into dataset. Without this,
       initPicks()'s `host.dataset.spk` lookup returned undefined, so a lazy
       picker's loader was never invoked and its options never rendered. */
    dataset: {},
    style: {},
    _listeners: {},
    _html: '',
    appendChild: function (c) { if (c && c.text === undefined) { c._parent = n; } n.children.push(c); return c; },
    insertAdjacentHTML: function (_pos, html) {
      var added = parse(html).children;
      added.forEach(function (c) { if (c && c.text === undefined) { c._parent = n; } });
      n.children = added.concat(n.children);
    },
    addEventListener: function (t, f) { (n._listeners[t] = n._listeners[t] || []).push(f); },
    removeEventListener: function () {},
    dispatchEvent: function () { return true; },
    setAttribute: function (k, v) {
      n.attrs[k] = v;
      if (k.indexOf('data-') === 0) { n.dataset[dataKey(k.slice(5))] = v; }
    },
    /* A real DOM resolves character references when reading an attribute, so
       `data-spk-opts="[{&quot;v&quot;…}]"` reads back as valid JSON. Without
       this the stub would make every pickField() option list look corrupt. */
    getAttribute: function (k) {
      if (!(k in n.attrs)) { return null; }
      return String(n.attrs[k])
        .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
        .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    },
    hasAttribute: function (k) { return k in n.attrs; },
    removeAttribute: function (k) { delete n.attrs[k]; },
    focus: function () {},
    click: function () {},
    scrollIntoView: function () {},
    getBoundingClientRect: function () {
      return { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 };
    },
    querySelector: function (sel) { return findAll(n, sel)[0] || null; },
    querySelectorAll: function (sel) { return findAll(n, sel); }
  };

  Object.defineProperty(n, 'classList', {
    get: function () {
      return {
        add: function (c) {
          var cur = (n.attrs.class || '').split(/\s+/).filter(Boolean);
          if (cur.indexOf(c) === -1) { cur.push(c); }
          n.attrs.class = cur.join(' ');
        },
        remove: function (c) {
          n.attrs.class = (n.attrs.class || '').split(/\s+/).filter(function (x) { return x && x !== c; }).join(' ');
        },
        contains: function (c) { return (n.attrs.class || '').split(/\s+/).indexOf(c) > -1; },
        toggle: function (c, on) { if (on) { this.add(c); } else { this.remove(c); } }
      };
    }
  });

  Object.defineProperty(n, 'innerHTML', {
    get: function () { return n._html; },
    set: function (v) {
      n.children = parse(String(v)).children;
      /* parse() roots the fragment at a throwaway div; re-parent so descendant
         selectors like `#tbl [data-page]` still resolve against this node. */
      n.children.forEach(function (c) { if (c && c.text === undefined) { c._parent = n; } });
      n._html = String(v);
    }
  });

  Object.defineProperty(n, 'textContent', {
    get: function () {
      return n.children.map(function (c) { return c.text !== undefined ? c.text : (c.textContent || ''); }).join('');
    },
    set: function (v) { n.children = [{ text: String(v) }]; }
  });

  Object.defineProperty(n, 'firstElementChild', {
    get: function () {
      return n.children.filter(function (c) { return c.text === undefined; })[0] || null;
    }
  });
  Object.defineProperty(n, 'lastElementChild', {
    get: function () {
      var e = n.children.filter(function (c) { return c.text === undefined; });
      return e[e.length - 1] || null;
    }
  });
  Object.defineProperty(n, 'offsetHeight', { get: function () { return 10; } });
  Object.defineProperty(n, 'disabled', {
    get: function () { return 'disabled' in n.attrs; },
    set: function (v) { if (v) { n.attrs.disabled = ''; } else { delete n.attrs.disabled; } }
  });

  // Reflect common HTML attributes as plain properties.
  ['value', 'type', 'name', 'id', 'placeholder'].forEach(function (a) {
    if (n.attrs[a] !== undefined) { n[a] = n.attrs[a]; }
  });
  // Reflect data-* attributes into dataset, the way a browser does.
  Object.keys(n.attrs).forEach(function (k) {
    if (k.indexOf('data-') === 0) { n.dataset[dataKey(k.slice(5))] = n.attrs[k]; }
  });
  n.hidden = 'hidden' in n.attrs;
  /* `checked` mirrors the attribute both ways, like a real checkbox. As a
     static value it was frozen at construction, so setting the attribute
     later (what a radio click does) was invisible to `rade()`. */
  Object.defineProperty(n, 'checked', {
    get: function () { return 'checked' in n.attrs; },
    set: function (v) { if (v) { n.attrs.checked = ''; } else { delete n.attrs.checked; } }
  });
  return n;
}

/** "data-foo-bar" -> "fooBar" */
function dataKey(s) {
  return s.replace(/-(\w)/g, function (m, c) { return c.toUpperCase(); });
}

/** Parse a flat HTML string into a node tree rooted at a div. */
function parse(html) {
  var root = makeNode('div', {});
  var stack = [root];
  var re = /<(\/?)([a-zA-Z0-9]+)((?:\s+[^>]*?)?)(\/?)>/g;
  var m, last = 0;
  while ((m = re.exec(html))) {
    var text = html.slice(last, m.index);
    if (text.trim()) { stack[stack.length - 1].children.push({ text: text.trim() }); }
    last = re.lastIndex;
    var close = m[1], tag = m[2], attrStr = m[3], selfClose = m[4];
    if (close) { if (stack.length > 1) { stack.pop(); } continue; }
    var attrs = {};
    var ar = /([a-zA-Z0-9_-]+)(?:="([^"]*)")?/g, a;
    while ((a = ar.exec(attrStr))) { attrs[a[1]] = a[2] === undefined ? '' : a[2]; }
    var node = makeNode(tag, attrs);
    node._parent = stack[stack.length - 1];
    stack[stack.length - 1].children.push(node);
    if (!selfClose && !VOID[tag.toLowerCase()]) { stack.push(node); }
  }
  var tail = html.slice(last);
  if (tail.trim()) { stack[stack.length - 1].children.push({ text: tail.trim() }); }
  return root;
}

/**
 * Match one node against one simple selector part.
 *
 * Supports a tag, any number of `.class` and `#id`, and any number of
 * `[attr]` / `[attr=value]` (value quoted or bare) conditions, in any
 * combination — `button.edt-b[data-format="script"][data-value="sub"]` is
 * what the Quill toolbar is queried with.
 */
function matchPart(node, part) {
  if (node.text !== undefined) { return false; }
  var m = part.match(/^([a-zA-Z][a-zA-Z0-9]*)?((?:[.#][\w-]+|\[[^\]]+\])*)$/);
  if (!m) { return node.tag === part.toLowerCase(); }
  if (m[1] && node.tag !== m[1].toLowerCase()) { return false; }
  var rest = m[2] || '';
  var tre = /([.#])([\w-]+)|\[([^\]]+)\]/g, x;
  while ((x = tre.exec(rest))) {
    if (x[1] === '.') {
      if ((node.attrs.class || '').split(/\s+/).indexOf(x[2]) === -1) { return false; }
    } else if (x[1] === '#') {
      if (node.attrs.id !== x[2]) { return false; }
    } else {
      var am = x[3].match(/^([a-zA-Z-]+)(?:="?([^"\]]*)"?)?$/);
      if (!am) { return false; }
      if (!(am[1] in node.attrs)) { return false; }
      if (am[2] !== undefined && String(node.attrs[am[1]]) !== am[2]) { return false; }
    }
  }
  return true;
}

/** Parent link, maintained lazily by walking up from a node's owner. */
function ancestors(node) {
  var out = [];
  var owner = node && node._parent;
  while (owner) { out.push(owner); owner = owner._parent; }
  return out;
}

/**
 * Collect descendants matching a (possibly descendant-chained) selector.
 *
 * `a b c` matches any `c` that has a `b` ancestor which in turn has an `a`
 * ancestor — at any depth, not just the first child. The earlier version only
 * stepped into the first child of the previous match, so real selectors like
 * `#tbl [data-page]` silently matched nothing and the pager under test never
 * had its click handler bound.
 */
function findAll(root, sel) {
  /* Selector lists: `input[name],select[name],textarea[name]` is what
     UI.formValues() queries. Without this it matched nothing and every form
     silently produced {} — which hid the rider-signup bug entirely. */
  var groups = String(sel).split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  if (groups.length > 1) {
    var all = [];
    groups.forEach(function (g) {
      findAll(root, g).forEach(function (n) { if (all.indexOf(n) === -1) { all.push(n); } });
    });
    return all;
  }
  var parts = String(sel).trim().split(/\s+/).filter(Boolean);
  var out = [];
  (function walk(n) {
    (n.children || []).forEach(function (c) {
      if (c.text === undefined) {
        var last = parts[parts.length - 1];
        if (matchPart(c, last)) {
          var chain = ancestors(c);
          var ok = true;
          for (var i = parts.length - 2; i >= 0; i--) {
            var found = false;
            for (var j = 0; j < chain.length; j++) {
              if (matchPart(chain[j], parts[i])) { found = true; break; }
            }
            if (!found) { ok = false; break; }
          }
          if (ok) { out.push(c); }
        }
        walk(c);
      }
    });
  })(root);
  return out;
}

var documentStub = {
  addEventListener: function () {},
  createElement: function (t) { return makeNode(t, {}); },
  querySelector: function (sel) { return findAll(documentStub.body, sel)[0] || null; },
  querySelectorAll: function (sel) { return findAll(documentStub.body, sel); }
};
documentStub.body = makeNode('body', {});

module.exports = { parse: parse, makeNode: makeNode, findAll: findAll, documentStub: documentStub };