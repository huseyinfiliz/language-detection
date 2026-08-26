import app from 'flarum/admin/app';

import LanguageDetectionPage from './components/LanguageDetectionPage';

app.initializers.add('huseyinfiliz-language-detection', () => {
  // Replaces the settings modal core would otherwise generate. Everything the extension does -- the
  // statistics, the missing languages and the five settings -- lives on that one page.
  app.extensionData.for('huseyinfiliz-language-detection').registerPage(LanguageDetectionPage);
});
