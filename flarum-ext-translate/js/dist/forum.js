(function () {
  'use strict';

  var EXT_ID = 'twikura-translate';
  var AUTO_KEY = 'twikuraTranslateAutoEnabled';

  var compat = flarum.core.compat;
  var app = moduleDefault(compat['forum/app']);
  var extend = compat['common/extend'].extend;
  var HeaderSecondary = moduleDefault(compat['forum/components/HeaderSecondary']);
  var Post = moduleDefault(compat['forum/components/Post']);
  var CommentPost = moduleDefault(compat['forum/components/CommentPost']);
  var Button = moduleDefault(compat['common/components/Button']);

  var states = new Map();
  var cache = new Map();
  var pending = new Map();

  function moduleDefault(value) {
    return value && value.__esModule ? value.default : value;
  }

  function apiUrl(path) {
    var base = app.forum && app.forum.attribute('apiUrl') ? app.forum.attribute('apiUrl') : '/api';

    return base.replace(/\/$/, '') + path;
  }

  function currentLang() {
    return document.documentElement.getAttribute('lang') || (app.data && app.data.locale) || 'en';
  }

  function autoEnabled() {
    return localStorage.getItem(AUTO_KEY) === '1';
  }

  function setAutoEnabled(enabled) {
    localStorage.setItem(AUTO_KEY, enabled ? '1' : '0');

    if (enabled) {
      states.forEach(function (state) {
        startAutoTranslation(state);
      });
    }

    m.redraw();
  }

  function postKey(post) {
    var id = typeof post.id === 'function' ? post.id() : null;
    var number = typeof post.number === 'function' ? post.number() : null;

    if (id !== null && id !== undefined && id !== '') {
      return String(id);
    }

    if (number !== null && number !== undefined && number !== '') {
      return 'number:' + String(number);
    }

    return 'content:' + cacheKey('', post.contentHtml() || '');
  }

  function isTranslatablePost(post) {
    return post && post.contentType && post.contentType() === 'comment' && typeof post.contentHtml() === 'string';
  }

  function postHtml(post) {
    return typeof post.contentHtml === 'function' ? (post.contentHtml() || '').trim() : '';
  }

  function getState(post) {
    var key = postKey(post);
    var state = states.get(key);

    if (!state) {
      state = {
        key: key,
        post: post,
        component: null,
        mode: 'original',
        lang: null,
        translatedHtml: null,
        loading: false,
        error: null,
        autoAttempted: Object.create(null)
      };
      states.set(key, state);
    }

    state.post = post;

    return state;
  }

  function cacheKey(lang, text) {
    return lang + '\u0000' + text;
  }

  function invalidate(state) {
    if (state.component && state.component.subtree && typeof state.component.subtree.invalidate === 'function') {
      state.component.subtree.invalidate();
    }
  }

  function redrawState(state) {
    invalidate(state);
    m.redraw();
  }

  function setTranslated(state, lang, html) {
    state.mode = 'translated';
    state.lang = lang;
    state.translatedHtml = html;
    state.error = null;
    redrawState(state);
  }

  function restoreOriginal(state) {
    state.mode = 'original';
    state.lang = null;
    state.error = null;
    redrawState(state);
  }

  function translateState(state) {
    var post = state.post;
    var lang = currentLang();
    var source = postHtml(post);
    var key = cacheKey(lang, source);

    if (!source || state.loading) {
      return Promise.resolve();
    }

    if (cache.has(key)) {
      setTranslated(state, lang, cache.get(key));
      return Promise.resolve();
    }

    state.loading = true;
    state.error = null;
    redrawState(state);

    var request = pending.get(key);

    if (!request) {
      request = app.request({
        method: 'POST',
        url: apiUrl('/translate'),
        body: {
          lang: lang,
          text: source
        }
      }).then(function (response) {
        cache.set(key, response.translated);
        return response.translated;
      }).then(function (translated) {
        pending.delete(key);
        return translated;
      }).catch(function (error) {
        pending.delete(key);
        throw error;
      });

      pending.set(key, request);
    }

    return request.then(function (translated) {
      setTranslated(state, lang, translated);
    }).catch(function (error) {
      state.error = error && error.message ? error.message : 'Translation failed.';
      restoreOriginal(state);
    }).then(function () {
      state.loading = false;
      redrawState(state);
    });
  }

  function startAutoTranslation(state) {
    var lang = currentLang();

    if (!autoEnabled() || !isTranslatablePost(state.post) || state.loading) {
      return;
    }

    if (state.mode === 'translated' && state.lang === lang) {
      return;
    }

    if (state.autoAttempted[lang]) {
      return;
    }

    state.autoAttempted[lang] = true;
    translateState(state);
  }

  var TranslatedPostBody = {
    oninit: function (vnode) {
      this.state = getState(vnode.attrs.post);
    },

    oncreate: function () {
      startAutoTranslation(this.state);
    },

    onupdate: function (vnode) {
      this.state.post = vnode.attrs.post;
      startAutoTranslation(this.state);
    },

    view: function (vnode) {
      var state = getState(vnode.attrs.post);
      var showingTranslation = state.mode === 'translated' && state.lang === currentLang();

      if (showingTranslation) {
        return m.trust(state.translatedHtml || '');
      }

      return m.trust(vnode.attrs.post.contentHtml() || '');
    }
  };

  function addAutoButton() {
    extend(HeaderSecondary.prototype, 'items', function (items) {
      items.add(
        'twikuraTranslateAuto',
        m(
          Button,
          {
            className: 'Button Button--link TwikuraTranslateAutoButton' + (autoEnabled() ? ' active' : ''),
            icon: 'fas fa-language',
            onclick: function () {
              setAutoEnabled(!autoEnabled());
            },
            'aria-pressed': autoEnabled() ? 'true' : 'false'
          },
          autoEnabled() ? '关闭翻译' : '自动翻译'
        ),
        25
      );
    });
  }

  function addPostButton() {
    extend(Post.prototype, 'footerItems', function (items) {
      var post = this.attrs.post;

      if (!isTranslatablePost(post)) {
        return;
      }

      var state = getState(post);
      state.component = this;

      items.add(
        'twikuraTranslate',
        m(
          Button,
          {
            className: 'Button Button--link TwikuraTranslatePostButton',
            loading: state.loading,
            disabled: state.loading,
            onclick: function () {
              if (state.mode === 'translated' && state.lang === currentLang()) {
                restoreOriginal(state);
              } else {
                translateState(state);
              }
            }
          },
          state.mode === 'translated' && state.lang === currentLang() ? '原文' : '翻译'
        ),
        -10
      );
    });
  }

  function replacePostBody() {
    extend(CommentPost.prototype, 'bodyItems', function (items) {
      var post = this.attrs.post;

      if (!isTranslatablePost(post)) {
        return;
      }

      var state = getState(post);
      state.component = this;

      if (typeof items.replace === 'function') {
        items.replace('content', m(TranslatedPostBody, { post: post }), 100);
      } else {
        items.remove('content');
        items.add('content', m(TranslatedPostBody, { post: post }), 100);
      }
    });
  }

  app.initializers.add(EXT_ID, function () {
    addAutoButton();
    addPostButton();
    replacePostBody();
  });

  if (typeof module !== 'undefined') {
    module.exports = {};
  }
})();
