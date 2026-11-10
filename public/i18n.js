/*
 * Translations.
 *
 * The code refers to texts by key; the texts themselves live in one file per
 * language (lang/en.js, lang/de.js, …), all with the same keys:
 *
 *   I18n.t('events.addMe')                                  // "+ Add me"
 *   I18n.t('events.removePerson', { name: 'Anna' })         // placeholders like {name}
 *   I18n.tn(count, 'admin.people')                          // singular/plural; {n} = count
 *
 * A plural entry is an object: { one: '{n} person', other: '{n} people' }.
 * Missing texts fall back to English, then to the key itself.
 *
 * Static texts in the HTML: data-i18n="key" sets an element's text;
 * data-i18n-placeholder, data-i18n-aria-label and data-i18n-title set attributes.
 *
 * The language is the browser's by default and can be changed with
 * I18n.setLanguage(); the choice is remembered in this browser.
 *
 * To add a language: create lang/xx.js (copy lang/en.js and translate the
 * texts) and load it in index.htm after i18n.js.
 */
var I18n = (function() {
  'use strict';

  var STORAGE_KEY = 'volunteertable-language';
  var FALLBACK = 'en';

  var languages = {};   // code -> { name, locale, texts }
  var current = null;
  var listeners = [];

  function lookup(language, key) {
    var entry = languages[language];
    return entry && Object.prototype.hasOwnProperty.call(entry.texts, key) ? entry.texts[key] : undefined;
  }

  function fill(text, params) {
    if (!params) {
      return text;
    }
    return text.replace(/\{(\w+)\}/g, function(match, name) {
      return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match;
    });
  }

  function t(key, params) {
    var value = lookup(language(), key);
    if (value === undefined) {
      value = lookup(FALLBACK, key);
    }
    if (value === undefined) {
      return key;
    }
    if (typeof value === 'object') {
      // A plural entry used without a count.
      value = value.other;
    }
    return fill(value, params);
  }

  function tn(count, key, params) {
    var all = { n: count };
    Object.keys(params || {}).forEach(function(name) {
      all[name] = params[name];
    });
    var value = lookup(language(), key);
    if (value === undefined) {
      value = lookup(FALLBACK, key);
    }
    if (value === undefined) {
      return key;
    }
    if (typeof value === 'object') {
      value = count === 1 ? value.one : value.other;
    }
    return fill(value, all);
  }

  function detectLanguage() {
    try {
      var stored = window.localStorage.getItem(STORAGE_KEY);
      if (stored && languages[stored]) {
        return stored;
      }
    } catch (e) {
      // Storage unavailable: use the browser's language.
    }
    var preferred = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || FALLBACK];
    for (var i = 0; i < preferred.length; i++) {
      var code = String(preferred[i]).toLowerCase().split('-')[0];
      if (languages[code]) {
        return code;
      }
    }
    return FALLBACK;
  }

  // The language is chosen on first use, once all language files have been loaded.
  function language() {
    if (current === null) {
      current = detectLanguage();
    }
    return current;
  }

  // Translates the static texts in the page.
  function applyStatic(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-i18n]').forEach(function(node) {
      node.textContent = t(node.getAttribute('data-i18n'));
    });
    ['placeholder', 'aria-label', 'title'].forEach(function(attribute) {
      scope.querySelectorAll('[data-i18n-' + attribute + ']').forEach(function(node) {
        node.setAttribute(attribute, t(node.getAttribute('data-i18n-' + attribute)));
      });
    });
    document.documentElement.lang = language();
  }

  function setLanguage(code) {
    if (!languages[code] || code === language()) {
      return;
    }
    current = code;
    try {
      window.localStorage.setItem(STORAGE_KEY, code);
    } catch (e) {
      // Not essential: the choice just isn't remembered.
    }
    applyStatic();
    listeners.forEach(function(listener) {
      listener(code);
    });
  }

  return {
    // Called by each lang/xx.js file.
    addLanguage: function(code, name, locale, texts) {
      languages[code] = { name: name, locale: locale, texts: texts };
    },
    t: t,
    tn: tn,
    applyStatic: applyStatic,
    setLanguage: setLanguage,
    onChange: function(listener) { listeners.push(listener); },
    language: language,
    // Locale for dates and times, e.g. "de-DE".
    locale: function() { return languages[language()].locale; },
    languages: function() {
      return Object.keys(languages).map(function(code) { return { code: code, name: languages[code].name }; });
    }
  };
})();
