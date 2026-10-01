/**
 * Compiles src/i18n/{en,de}/*.ts into the UI section of the plugin's languages.yaml:
 *   en: { PLUGIN_REDIRECT_MANAGER: { UI: { AREA: { KEY: text } } } }
 * Keys are the flat dotted keys the code uses ("RULES.NEW" -> RULES: NEW).
 */
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';
import YAML from 'yaml';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

function loadDir(lang) {
  const dir = join(root, 'src/i18n', lang);
  const out = {};
  for (const file of readdirSync(dir).filter((f) => f.endsWith('.ts')).sort()) {
    const js = ts.transpileModule(readFileSync(join(dir, file), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    const mod = { exports: {} };
    new Function('module', 'exports', js)(mod, mod.exports);
    const strings = mod.exports.default;
    for (const [k, v] of Object.entries(strings)) {
      if (k in out) throw new Error(`Duplicate key ${k} (${lang}/${file})`);
      out[k] = v;
    }
  }
  return out;
}

function nest(flat) {
  const tree = {};
  for (const key of Object.keys(flat).sort()) {
    const parts = key.split('.');
    let node = tree;
    parts.forEach((p, i) => {
      if (i === parts.length - 1) {
        if (typeof node[p] === 'object') throw new Error(`Key ${key} collides with a group`);
        node[p] = flat[key];
      } else {
        if (typeof node[p] === 'string') throw new Error(`Key ${parts.slice(0, i + 1).join('.')} is both a string and a group`);
        node = node[p] ??= {};
      }
    });
  }
  return tree;
}

/**
 * Words the YAML 1.1 core schema reads as booleans or null. Grav parses languages.yaml with the libyaml extension when
 * the server has it (Grav\\Framework\\File\\Formatter\\YamlFormatter), and libyaml follows YAML 1.1: a bare key
 * `YES:` becomes the key 1, `NO:` becomes 0, and COMMON.YES / COMMON.NO vanish from the dictionary (the host then
 * serves the English backfill on a German page). Symfony's parser, the fallback, does not do that, so a test site
 * without libyaml never shows it. Such keys are written quoted.
 */
const YAML11_WORDS = /^(y|n|yes|no|true|false|on|off|null|~)$/i;

const en = loadDir('en');
const de = loadDir('de');
const missing = Object.keys(en).filter((k) => !(k in de));
const stray = Object.keys(de).filter((k) => !(k in en));
if (missing.length || stray.length) {
  console.error('en/de mismatch', { missing, stray });
  process.exit(1);
}

/**
 * Replaces PLUGIN_REDIRECT_MANAGER.UI in the plugin's languages.yaml (en and de) and leaves every other
 * section, comment and quoting style as it is. The file is parsed as a YAML document, not re-generated.
 */
const file = join(root, '..', 'languages.yaml');
const doc = YAML.parseDocument(readFileSync(file, 'utf8'));
for (const [lang, flat] of [['en', en], ['de', de]]) {
  const node = doc.createNode(nest(flat));
  YAML.visit(node, {
    Scalar(key, sc) {
      if (typeof sc.value !== 'string') return;
      if (key !== 'key' || YAML11_WORDS.test(sc.value)) sc.type = 'QUOTE_DOUBLE';
    },
  });
  const plugin = doc.getIn([lang, 'PLUGIN_REDIRECT_MANAGER']);
  if (!YAML.isMap(plugin)) throw new Error(`languages.yaml has no ${lang}.PLUGIN_REDIRECT_MANAGER map`);
  plugin.delete('UI');
  plugin.set('UI', node); // UI stays the last section
}
writeFileSync(file, doc.toString({ lineWidth: 0, singleQuote: true }));
console.log(`languages.yaml updated: ${Object.keys(en).length} UI strings x 2 languages`);
