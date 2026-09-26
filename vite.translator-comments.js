/**
 * Keeps translator comments in the minified bundles, where `wp i18n
 * make-pot` (and translate.wordpress.org) read the strings from.
 *
 * The minifier drops every ordinary comment. It keeps "legal" ones, which
 * start with `/*!`, when the build sets `output.comments.legal` — so the
 * source writes translator notes as `/*! translators: … *\/` right before
 * the `__()` / `_n()` call, and this plugin turns them back into the
 * `/* translators: … *\/` that make-pot looks for. Legal comments stay
 * where they were written, so each note still sits on the line just above
 * its call.
 */
export const translatorCommentOutput = {
  comments: { legal: true },
  minify: { compress: false, mangle: true, codegen: { removeWhitespace: true } },
};

export function translatorComments() {
  return {
    name: 'byzo-translator-comments',
    apply: 'build',
    generateBundle(options, bundle) {
      for (const file of Object.values(bundle)) {
        if (file.type === 'chunk') {
          file.code = file.code.replace(/\/\*!(\s*translators:)/g, '/*$1');
        }
      }
    },
  };
}
