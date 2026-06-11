(function () {
  'use strict';

  var compat = flarum.core.compat;
  var app = moduleDefault(compat['admin/app']);
  var Button = moduleDefault(compat['common/components/Button']);

  var testText = 'hello';
  var testResult = null;
  var testError = null;
  var testing = false;

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

  function runTranslationTest() {
    testing = true;
    testResult = null;
    testError = null;
    m.redraw();

    app.request({
      method: 'POST',
      url: apiUrl('/translate'),
      body: {
        lang: currentLang(),
        text: testText || ''
      }
    }).then(function (response) {
      testResult = response && response.translated ? response.translated : JSON.stringify(response);
    }).catch(function (error) {
      if (error && error.response && error.response.errors && error.response.errors[0]) {
        testError = error.response.errors[0].detail || app.translator.trans('twikura-translate.admin.test.failed');
      } else {
        testError = error && error.message ? error.message : app.translator.trans('twikura-translate.admin.test.failed');
      }
    }).then(function () {
      testing = false;
      m.redraw();
    });
  }

  function testPanel() {
    return m('div', { className: 'Form-group TwikuraTranslateTestPanel' }, [
      m('label', app.translator.trans('twikura-translate.admin.test.title')),
      m('div', { className: 'helpText' }, app.translator.trans('twikura-translate.admin.test.description', {lang: currentLang()})),
      m('input', {
        className: 'FormControl',
        type: 'text',
        value: testText,
        oninput: function (event) {
          testText = event.target.value;
        }
      }),
      m(
        Button,
        {
          className: 'Button Button--primary TwikuraTranslateTestButton',
          loading: testing,
          disabled: testing,
          onclick: runTranslationTest
        },
        app.translator.trans('twikura-translate.admin.test.button')
      ),
      testResult && m('pre', { className: 'TwikuraTranslateTestResult' }, testResult),
      testError && m('div', { className: 'Alert Alert--error TwikuraTranslateTestError' }, testError)
    ]);
  }

  app.initializers.add('twikura-translate-admin', function () {
    app.extensionData
      .for('twikura-translate')
      .registerSetting({
        setting: 'twikura-translate.api_base_url',
        label: app.translator.trans('twikura-translate.admin.settings.api_base_url'),
        help: 'Use a local-only URL such as http://127.0.0.1:8000 or a Docker network URL.',
        type: 'text'
      })
      .registerSetting({
        setting: 'twikura-translate.allow_guests',
        label: app.translator.trans('twikura-translate.admin.settings.allow_guests'),
        type: 'boolean'
      })
      .registerSetting({
        setting: 'twikura-translate.max_text_length',
        label: app.translator.trans('twikura-translate.admin.settings.max_text_length'),
        type: 'number'
      })
      .registerSetting({
        setting: 'twikura-translate.max_batch_size',
        label: app.translator.trans('twikura-translate.admin.settings.max_batch_size'),
        type: 'number'
      })
      .registerSetting(testPanel);
  });

  if (typeof module !== 'undefined') {
    module.exports = {};
  }
})();
