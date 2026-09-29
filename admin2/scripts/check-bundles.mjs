/** Fails when a bundle would not survive the Blob import (relative imports, import.meta, chunks). */
import { readFileSync, statSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'admin-next');
const budgets = { 'pages/redirect-manager.js': 250 * 1024, 'widgets/redirect-manager.js': 60 * 1024 };
let failed = false;

for (const dir of ['pages', 'widgets']) {
  const files = readdirSync(join(root, dir));
  if (files.length !== 1 || files[0] !== 'redirect-manager.js') {
    console.error(`FAIL ${dir}/ must contain exactly redirect-manager.js, found: ${files.join(', ')}`);
    failed = true;
  }
}
for (const [rel, budget] of Object.entries(budgets)) {
  const p = join(root, rel);
  const code = readFileSync(p, 'utf8');
  const problems = [];
  if (/\bimport\.meta\b/.test(code)) problems.push('import.meta');
  if (/^\s*import\s*[\w{*"']/m.test(code)) problems.push('static import');
  if (/\bimport\s*\(/.test(code)) problems.push('dynamic import()');
  if (/^\s*export\s/m.test(code)) problems.push('export statement');
  if (/from\s*["']\.{1,2}\//.test(code)) problems.push('relative import');
  if (!/customElements\.define/.test(code)) problems.push('no customElements.define');
  if (/__RM_MOCK|installMockApi|mock-api/.test(code)) problems.push('mock code in bundle');
  const size = statSync(p).size;
  const gz = gzipSync(code).length;
  const kb = (n) => (n / 1024).toFixed(1) + ' KB';
  const over = size > budget ? `  (over the ${kb(budget)} target)` : '';
  console.log(`${problems.length ? 'FAIL' : 'ok  '} ${rel}: ${kb(size)} raw, ${kb(gz)} gzip${over}${problems.length ? '  -> ' + problems.join(', ') : ''}`);
  if (problems.length) failed = true;
}
process.exit(failed ? 1 : 0);
