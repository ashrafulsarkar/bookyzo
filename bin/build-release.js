#!/usr/bin/env node
/**
 * Builds the WordPress.org-ready release zip (ROADMAP.md Phase 1 step
 * 10) — runs a fresh `npm run build` (assets/admin/ + assets/widget/ +
 * assets/account/), copies everything from the plugin root EXCEPT what
 * .distignore lists into a staging folder, then zips that staging folder
 * as bookyzo.zip.
 *
 * This is the "plugin build" the user distinguished from the ordinary
 * npm/Vite build early in Phase 1 step 1: that one produces
 * assets/admin/, assets/widget/, and assets/account/ (compiled JS/CSS)
 * but leaves src/, node_modules, and every
 * dev-tooling config file sitting right next to them at the plugin
 * root; this script is the one that actually assembles a clean,
 * submittable plugin folder from everything that survives .distignore.
 *
 * Usage: `npm run build:release` (runs `npm run build` first via the
 * package.json script chain) or `node bin/build-release.js` directly
 * if assets/admin/, assets/widget/, and assets/account/ are already
 * freshly built.
 */

import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
// archiver@8 is itself a native-ESM package with a different API than
// older archiver versions' `archiver('zip', options)` factory function —
// it exports the format-specific classes directly instead.
import { ZipArchive } from 'archiver';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const SLUG = 'bookyzo';
const STAGING_DIR = path.join(ROOT, 'release', SLUG);
const RELEASE_DIR = path.join(ROOT, 'release');

function readDistignore() {
  const raw = fs.readFileSync(path.join(ROOT, '.distignore'), 'utf8');
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'));
}

function isIgnored(relativePath, patterns) {
  const topLevel = relativePath.split(path.sep)[0];
  const name = path.basename(relativePath);
  return patterns.some((pattern) => {
    if (pattern.includes('*')) {
      // A file-name glob (`*.zip`, `BOOKYZO-QA-REPORT-*.md`): `*` stands
      // for any run of characters within the name.
      const escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
      return new RegExp(`^${escaped}$`).test(name);
    }
    return topLevel === pattern || relativePath === pattern;
  });
}

function copyRecursive(src, dest, patterns, root) {
  const entries = fs.readdirSync(src, { withFileTypes: true });
  for (const entry of entries) {
    const srcPath = path.join(src, entry.name);
    const relativePath = path.relative(root, srcPath);
    if (isIgnored(relativePath, patterns)) {
      continue;
    }
    const destPath = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      fs.mkdirSync(destPath, { recursive: true });
      copyRecursive(srcPath, destPath, patterns, root);
    } else {
      fs.copyFileSync(srcPath, destPath);
    }
  }
}

async function zipDirectory(sourceDir, outPath) {
  return new Promise((resolve, reject) => {
    const output = fs.createWriteStream(outPath);
    const archive = new ZipArchive({ zlib: { level: 9 } });
    output.on('close', resolve);
    archive.on('error', reject);
    archive.pipe(output);
    // Zip with the slug as the top-level folder (bookyzo/...), matching
    // what WordPress.org's own SVN/zip structure expects — extracting
    // this zip into wp-content/plugins/ drops the plugin in directly.
    archive.directory(sourceDir, SLUG);
    archive.finalize();
  });
}

/**
 * Generates languages/bookyzo.pot via WP-CLI (Phase 1.5 step 9's
 * translation-readiness item, DETAILED-SPEC.md §A5) — before
 * copyRecursive() below, so the freshly generated file is picked up like
 * any other source file (languages/ isn't in .distignore, it's meant to
 * ship). Soft-fails rather than aborting the whole release build: a
 * missing WP-CLI on some machine/CI shouldn't block producing the zip,
 * it should just mean a slightly stale .pot until re-run somewhere that
 * has it.
 */
function generatePot() {
  console.log('Generating languages/bookyzo.pot (wp i18n make-pot)...');
  try {
    execSync(
      'wp i18n make-pot . languages/bookyzo.pot --domain=bookyzo --slug=bookyzo --exclude=src,release,scratchpad,.claude,bin',
      { cwd: ROOT, stdio: 'inherit' }
    );
  } catch (error) {
    console.warn('Could not generate languages/bookyzo.pot (WP-CLI not found or the command failed) — continuing without it.');
    console.warn(error.message);
  }
}

async function main() {
  console.log('Building admin/ and public/ (npm run build)...');
  execSync('npm run build', { cwd: ROOT, stdio: 'inherit' });

  generatePot();

  console.log('Cleaning staging directory...');
  fs.rmSync(RELEASE_DIR, { recursive: true, force: true });
  fs.mkdirSync(STAGING_DIR, { recursive: true });

  console.log('Copying shippable files (per .distignore)...');
  const patterns = readDistignore();
  copyRecursive(ROOT, STAGING_DIR, patterns, ROOT);

  const zipPath = path.join(RELEASE_DIR, `${SLUG}.zip`);
  console.log('Zipping...');
  await zipDirectory(STAGING_DIR, zipPath);

  console.log(`\nDone: ${path.relative(ROOT, zipPath)}`);
  console.log(`Staged folder (for inspection): ${path.relative(ROOT, STAGING_DIR)}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
