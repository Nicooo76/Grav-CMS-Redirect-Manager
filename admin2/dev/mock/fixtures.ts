/** Deterministic fixture generation: pages, rules (all match/target/status/badge cases), 404s, suggestions. */
import type { PageHit, Rule, RuleInput, StatusCode, StoredSuggestion, SuggestionReason, UaClass } from '../../src/lib/types';
import type { CheckResult, MockState, NotFoundGroup, ResolvedOptions } from './types';
import type { Rng } from './util';
import { DAY, chance, dayKey, hash, isoAtom, mulberry32, pick, rint, shuffle, weighted } from './util';
import { analyseAll } from './analysis';
import { makeRule } from './rules';
import { suggest } from './suggest';

const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
const titleOf = (slug: string) => cap(slug.replace(/-/g, ' '));

/* ---- pages ---------------------------------------------------------------------------------- */

const POSTS = ['website-relaunch-checkliste', 'core-web-vitals-verbessern', 'dsgvo-cookie-banner', 'seo-tipps-2026', 'shopify-oder-woocommerce', 'barrierefreiheit-im-web', 'grav-cms-vorteile', 'hosting-vergleich', 'newsletter-aufbauen', 'google-ads-budget', 'ladezeit-optimieren', 'ki-im-webdesign'];
// [de route, de title, en route | null, en title]
const PAGES: [string, string, string | null, string][] = [
  ['/', 'Startseite', '/en', 'Home'],
  ['/blog', 'Blog', '/en/blog', 'Blog'],
  ['/journal', 'Journal', '/en/journal', 'Journal'],
  ['/ueber-uns', 'Über uns', '/en/about-us', 'About us'],
  ['/kontakt', 'Kontakt', '/en/contact', 'Contact'],
  ['/leistungen', 'Leistungen', '/en/services', 'Services'],
  ['/leistungen/webdesign', 'Webdesign', '/en/services/web-design', 'Web design'],
  ['/leistungen/seo', 'Suchmaschinenoptimierung', '/en/services/seo', 'SEO'],
  ['/leistungen/hosting', 'Hosting und Wartung', '/en/services/hosting', 'Hosting and maintenance'],
  ['/leistungen/shop', 'Online-Shop', null, ''],
  ['/shop', 'Shop', '/en/shop', 'Shop'],
  ['/shop/sale', 'Sale', '/en/shop/sale', 'Sale'],
  ['/shop/moebel', 'Möbel', null, ''],
  ['/shop/leuchten', 'Leuchten', null, ''],
  ['/shop/textilien', 'Textilien', null, ''],
  ['/shop/accessoires', 'Accessoires', null, ''],
  ['/shop/garten', 'Garten', null, ''],
  ['/impressum', 'Impressum', '/en/imprint', 'Imprint'],
  ['/datenschutz', 'Datenschutz', '/en/privacy', 'Privacy policy'],
  ['/agb', 'AGB', null, ''],
  ['/team', 'Team', '/en/team', 'Team'],
  ['/referenzen', 'Referenzen', '/en/portfolio', 'Portfolio'],
  ['/karriere', 'Karriere', '/en/careers', 'Careers'],
  ['/downloads', 'Downloads', null, ''],
  ['/faq', 'Häufige Fragen', '/en/faq', 'FAQ'],
  ...POSTS.map((s, i): [string, string, string | null, string] => [`/journal/${s}`, titleOf(s), i % 3 === 0 ? `/en/journal/${s}` : null, titleOf(s)]),
];
const EXISTING_ONLY = [['/products', 'Products'], ['/contact', 'Contact'], ['/about', 'About']] as const;

export function buildPages(): PageHit[] {
  const out: PageHit[] = [];
  for (const [route, title, en, enTitle] of PAGES) {
    out.push({ route, title, language: 'de', translations: en ? ['en'] : [] });
    if (en) out.push({ route: en, title: enTitle, language: 'en', translations: ['de'] });
  }
  for (const [route, title] of EXISTING_ONLY) out.push({ route, title, language: 'en', translations: [] });
  return out;
}

/* ---- rules ---------------------------------------------------------------------------------- */

const TOPICS = ['webdesign', 'relaunch', 'seo', 'ecommerce', 'shopify', 'typo3', 'wordpress', 'grav-cms', 'datenschutz', 'dsgvo', 'barrierefreiheit', 'ladezeit', 'hosting', 'wartung', 'newsletter', 'social-media', 'google-ads', 'analytics', 'conversion', 'branding', 'fotografie', 'video', 'content', 'marketing', 'agentur', 'kunden', 'preise', 'angebot'];
const MODS = ['tipps', 'guide', 'leitfaden', 'checkliste', 'erfahrungen', 'fehler', 'vorteile', 'kosten', 'ratgeber', 'vergleich', 'update', 'so-gehts', 'die-besten', 'warum', 'was-ist'];
const PRODUCTS = ['schreibtisch', 'regal', 'stuhl', 'lampe', 'sofa', 'sessel', 'kommode', 'teppich', 'vase', 'spiegel', 'tisch', 'bank', 'kissen', 'decke'];
const MATERIALS = ['eiche', 'buche', 'nussbaum', 'weiss', 'schwarz', 'massiv', 'leinen', 'messing', 'beton'];
const CATS = ['moebel', 'leuchten', 'textilien', 'accessoires', 'garten'];
const DIRS = ['blog', 'news', 'artikel', 'magazin', 'presse', 'archiv', 'wissen', 'ratgeber', 'produkt', 'katalog', 'aktuelles', 'portfolio'];
const WORDS = ['impressum-alt', 'preise', 'agentur', 'anfahrt', 'jobs', 'partner', 'presse', 'support', 'hilfe', 'newsletter', 'angebote', 'unternehmen', 'philosophie', 'kunden', 'leistungen-alt'];
const NOTES = ['Aus dem Relaunch-Mapping übernommen', 'Alte Newsletter-Links', 'Kunde wünscht 302 bis Kampagnenende', 'Von der Search Console gemeldet', 'Ticket #1234', 'Backlink von Fachportal, bitte behalten', 'Nach Migration aus WordPress'];
const TAGS = ['seo', 'newsletter', 'wordpress', 'import', 'kampagne', 'prio', 'extern', 'shop', 'bilder', 'pdf', 'backlink'];

type Draft = RuleInput & { source: string; target: string };

export function buildRules(r: Rng, n: number, now: number, pages: PageHit[]): Rule[] {
  const used = new Set<string>();
  const ids = new Set<string>();
  let counter = 1;
  const uniq = (make: () => string): string => {
    for (let i = 0; i < 6; i++) {
      const s = make();
      if (!used.has(s.toLowerCase())) {
        used.add(s.toLowerCase());
        return s;
      }
    }
    const s = make().replace(/([^/?]*?)(\.[a-z]+)?$/, `$1-${++counter}$2`);
    used.add(s.toLowerCase());
    return s;
  };
  const newId = () => {
    let id: string;
    do id = 'r' + [0, 1, 2].map(() => Math.floor(r() * 0x1000000).toString(16).padStart(6, '0')).join('');
    while (ids.has(id));
    ids.add(id);
    return id;
  };
  const status = (): StatusCode => weighted<StatusCode>(r, [[301, 80], [302, 12], [307, 3], [308, 5]]);
  const topic = () => `${pick(r, MODS)}-${pick(r, TOPICS)}`;
  const product = () => `${pick(r, PRODUCTS)}-${pick(r, MATERIALS)}`;
  const dePages = pages.filter((p) => p.language === 'de' && p.route !== '/' && !p.route.startsWith('/journal/'));
  const day = (d: number) => isoAtom(now + d * DAY);

  const kinds: [number, () => Draft][] = [
    [20, () => { const s = uniq(() => '/blog/' + topic()); return { source: s, target: '/journal/' + s.slice(6), status: status(), group: 'Blog', tags: chance(r, 0.3) ? ['seo'] : [] }; }],
    [6, () => { const t = topic(); const s = uniq(() => `/${rint(r, 2014, 2021)}/${String(rint(r, 1, 12)).padStart(2, '0')}/${t}/`); return { source: s, target: '/journal/' + t, group: 'Legacy', tags: ['wordpress'], origin: chance(r, 0.5) ? 'import' : 'manual' }; }],
    [14, () => { const p = product(); const c = pick(r, CATS); const s = uniq(() => '/produkte/' + product()); const page = chance(r, 0.25); return { source: s, target: page ? '/shop/' + c : `/shop/${c}/${s.slice(10)}`, target_type: page ? 'page' : 'route', status: status(), group: 'Shop', note: p && chance(r, 0.1) ? pick(r, NOTES) : '' }; }],
    [4, () => { const c = pick(r, CATS); const s = uniq(() => `/kategorie/${c}-${rint(r, 1, 99)}`); return { source: s, target: '/shop/' + c, group: 'Shop', target_type: 'page' }; }],
    [10, () => { const pg = pick(r, dePages); const s = uniq(() => `/${pick(r, ['leistungen', 'service', 'team', 'firma'])}/${pick(r, WORDS)}-${rint(r, 1, 40)}`); return { source: s, target: pg.route, target_type: 'page', status: status(), group: 'Relaunch 2026', origin: chance(r, 0.5) ? 'import' : 'manual', tags: chance(r, 0.2) ? ['prio'] : [] }; }],
    [4, () => { const x = pick(r, WORDS); const s = uniq(() => `/aktion/${x}-${rint(r, 1, 60)}`); const k = r(); return { source: s, target: '/shop/sale', status: 302, group: 'Kampagnen', tags: ['kampagne'], active_from: k > 0.75 ? day(rint(r, 3, 40)) : day(-rint(r, 60, 200)), expires_at: k > 0.75 ? day(rint(r, 60, 120)) : k < 0.4 ? day(-rint(r, 2, 50)) : day(rint(r, 5, 90)) }; }],
    [5, () => { const f = pick(r, ['katalog', 'preisliste', 'broschuere', 'agb', 'datenblatt']); const s = uniq(() => `/downloads/${f}-${rint(r, 2016, 2025)}.pdf`); return { source: s, target: '/user/downloads/' + s.slice(11), group: 'Legacy', tags: ['pdf'], case_sensitive: chance(r, 0.2) }; }],
    [4, () => {
      const k = r();
      if (k < 0.55) { const id = rint(r, 1, 9999); const s = uniq(() => `/index.php?id=${id}`); return { source: s, target: pick(r, dePages).route, query_mode: 'exact', group: 'Legacy', note: 'Altes CMS, Seiten-ID' }; }
      if (k < 0.8) { const s = uniq(() => `/${pick(r, ['suche', 'search', 'finden'])}-${rint(r, 1, 300)}`); return { source: s, target: '/shop', query_mode: 'pass', group: 'Shop' }; }
      const s = uniq(() => `/produkte-${rint(r, 1, 500)}`);
      return { source: s, target: '/shop/moebel', query_mode: 'params', query_params: { kat: null, sort: 'preis' }, query_ignore: ['session'], group: 'Shop' };
    }],
    [4, () => {
      if (chance(r, 0.4)) { const y = rint(r, 2010, 2025); const s = uniq(() => `/wp-content/uploads/${y}/*`); return { source: s, target: `/user/images/${y}/$1`, match_type: 'wildcard', group: 'Legacy', tags: ['bilder'] }; }
      const d = pick(r, DIRS); const s = uniq(() => `/${d}-${rint(r, 1, 999)}/*`);
      return { source: s, target: `/journal/$1`, match_type: 'wildcard', group: 'Blog', status: status() };
    }],
    [4, () => {
      const d = `${pick(r, DIRS)}${chance(r, 0.5) ? '' : rint(r, 2, 300)}`;
      const t = [
        { source: `^/${d}/(\\d{4})/(.*)$`, target: '/journal/$1/$2' },
        { source: `^/${d}/(?<slug>[a-z0-9-]+)\\.html$`, target: '/shop/{slug}' },
        { source: `^/${d}/(\\d+)-(.*)$`, target: '/{lang}/journal/$2' },
        { source: `^/${d}/([^/]+)/seite/(\\d+)$`, target: '/shop/$1?page=$2' },
      ][rint(r, 0, 3)]!;
      used.add(t.source.toLowerCase());
      return { ...t, match_type: 'regex', status: status(), group: chance(r, 0.5) ? 'Blog' : 'Legacy', case_sensitive: chance(r, 0.15) };
    }],
    [4, () => { const w = pick(r, WORDS); const en = chance(r, 0.5); const s = uniq(() => `/${w}-${rint(r, 1, 900)}`); return { source: s, target: en ? `/en/${w}` : `/${w}`, conditions: { hosts: [], schemes: [], rules: [], languages: [en ? 'en' : 'de'] }, group: 'Relaunch 2026' }; }],
    [2.5, () => {
      const s = uniq(() => `/${pick(r, WORDS)}-${rint(r, 1, 900)}`);
      const rules = chance(r, 0.5)
        ? [{ kind: 'header' as const, name: 'Accept-Language', operator: 'starts_with' as const, value: 'en', negate: false }]
        : [{ kind: 'cookie' as const, name: pick(r, ['beta', 'ab_variant', 'session_id']), operator: pick(r, ['equals', 'exists'] as const), value: '1', negate: chance(r, 0.3) }];
      return { source: s, target: pick(r, dePages).route, conditions: { hosts: [], languages: [], schemes: chance(r, 0.2) ? ['https'] : [], rules }, status: 302, group: 'Kampagnen' };
    }],
    [3, () => { const s = uniq(() => `/${pick(r, ['alt', 'archiv', 'shop-alt'])}/${topic()}`); return { source: s, target: '', status: 410, group: 'Legacy', note: chance(r, 0.4) ? 'Inhalt bewusst entfernt' : '' }; }],
    [0.7, () => { const s = uniq(() => `/inhalte/${topic()}`); return { source: s, target: '', status: 451, group: 'Legacy', note: 'Rechtlich gesperrt (Gerichtsbeschluss)' }; }],
    [1.5, () => { const t = topic(); const s = uniq(() => `/go/${t}`); return { source: s, target: `/kampagne/${t}`, status: 200, group: 'Kampagnen', target_type: 'route' }; }],
    [3, () => {
      const k = pick(r, [['https://shop.example-partner.de/', 'partner'], ['https://www.instagram.com/pixagentur', 'insta'], ['https://github.com/pixagentur', 'github'], ['https://maps.google.com/?q=Musterstrasse', 'anfahrt']] as const);
      const s = uniq(() => `/${k[1]}-${rint(r, 1, 999)}`);
      return { source: s, target: k[0] + (k[1] === 'partner' ? topic() : ''), target_type: 'url', status: pick(r, [301, 302] as const), group: 'Kampagnen', tags: ['extern'] };
    }],
    [2, () => { const d = pick(r, DIRS); const s = uniq(() => `/${d}-shop-${rint(r, 1, 900)}/*`); return { source: s, target: '/shop/$1', match_type: 'wildcard', continue: true, group: 'Shop', note: 'Zuerst umschreiben, dann weitere Regeln prüfen' }; }],
    [3, () => { const d = pick(r, DIRS); const s = uniq(() => `/${d}-fallback-${rint(r, 1, 900)}/*`); return { source: s, target: '/shop', match_type: 'wildcard', only_if_not_found: true, status: 302, group: 'Shop' }; }],
    [1, () => { const s = uniq(() => `/Downloads/Katalog-${rint(r, 2010, 2025)}.PDF`); return { source: s, target: '/downloads', case_sensitive: true, ignore_trailing_slash: false, group: 'Legacy' }; }],
    [1.5, () => { const d = pick(r, DIRS); const s = uniq(() => `/${d}-${rint(r, 1, 900)}/*`); return { source: s, target: 'https://www.example.test/$1', target_type: 'url', match_type: 'wildcard', conditions: { hosts: ['alt.example.test'], languages: [], schemes: [], rules: [] }, status: 301, group: 'Relaunch 2026' }; }],
  ];

  const extras = n >= 40 ? { chain: Math.round(n * 0.02), loop: Math.max(1, Math.round(n * 0.005)), conflict: Math.round(n * 0.015) } : { chain: 0, loop: 0, conflict: 0 };
  const extraCount = extras.chain + extras.loop * 2 + extras.conflict;
  const base = Math.max(1, n - extraCount);
  const drafts: Draft[] = [];
  for (let i = 0; i < base; i++) drafts.push(weighted(r, kinds.map(([w, f]) => [f, w] as const))());

  // chains: A(new) -> R1 -> R2 (R1 and A get the badge)
  const plain = () => drafts.filter((d) => (d.match_type ?? 'exact') === 'exact' && !d.source.includes('?') && (d.status ?? 301) === 301 && d.target_type !== 'url' && d.target.startsWith('/') && !d.expires_at && !d.active_from && !d.conditions && !d.only_if_not_found && !d.continue);
  const pool = shuffle(r, plain());
  const chainStart = (): Draft[] => {
    const out: Draft[] = [];
    for (let i = 0; i < extras.chain && pool.length >= 2; i++) {
      const r1 = pool.pop()!;
      const r2 = pool.pop()!;
      r1.target = r2.source;
      used.add('/legacy-chain-' + i);
      out.push({ source: `/legacy/alt-${topic()}-${i}`, target: r1.source, group: r1.group, note: 'Zeigt auf eine Regel, die selbst weiterleitet' });
    }
    return out;
  };
  drafts.push(...chainStart());
  for (let i = 0; i < extras.loop; i++) {
    drafts.push({ source: `/team-alt-${i}`, target: `/team-neu-${i}`, group: 'Relaunch 2026' }, { source: `/team-neu-${i}`, target: `/team-alt-${i}`, group: 'Relaunch 2026' });
  }
  for (let i = 0; i < extras.conflict && pool.length; i++) {
    const o = pool.pop()!;
    drafts.push({ source: o.source, target: '/shop/sale', group: o.group, note: 'Doppelt angelegt', priority: -1 });
  }
  while (drafts.length < n) drafts.push({ source: uniq(() => `/extra/${topic()}`), target: '/journal' });

  // materialise
  const rules = drafts.slice(0, n).map((d, i) => {
    const rule = makeRule(d, newId(), '');
    const tail = i < base ? 0 : 1;
    const rank = rule.continue ? 4 : { exact: 3, wildcard: 2, regex: 1 }[rule.match_type];
    rule.priority = d.priority === -1 ? (rank - 1) * (n + 1) + (n - i) : rank * (n + 1) + (n - i) - tail;
    rule.enabled = d.expires_at || d.active_from ? true : !chance(r, 0.05);
    if (!d.group && chance(r, 0.15)) rule.group = '';
    if (!rule.note && chance(r, 0.08)) rule.note = pick(r, NOTES);
    if (!rule.tags.length && chance(r, 0.15)) rule.tags = [pick(r, TAGS)];
    if (!d.origin) rule.origin = weighted(r, [['manual', 55], ['import', 25], ['auto', 10], ['suggestion', 10]] as const);
    const isLoopOrConflict = i >= base;
    if (!isLoopOrConflict && !d.expires_at && !d.active_from) {
      const k = r();
      if (k < 0.02) { rule.expires_at = day(-rint(r, 2, 180)); rule.active_from = day(-rint(r, 200, 500)); }
      else if (k < 0.04) { rule.active_from = day(rint(r, 2, 60)); if (chance(r, 0.5)) rule.expires_at = day(rint(r, 90, 200)); }
      else if (k < 0.06) rule.expires_at = day(rint(r, 10, 300));
    }
    return rule;
  });
  assignStats(r, rules, now);
  return rules;
}

function assignStats(r: Rng, rules: Rule[], now: number): void {
  for (const rule of rules) {
    const scheduled = rule.active_from && Date.parse(rule.active_from) > now;
    const expired = rule.expires_at && Date.parse(rule.expires_at) <= now;
    const roll = r();
    let created = now - rint(r, 20, 700) * DAY;
    const stats = rule.stats!;
    if (scheduled) {
      created = now - rint(r, 1, 30) * DAY;
    } else if (!expired && rule.enabled && roll < 0.12) {
      created = now - rint(r, 100, 700) * DAY;
      if (chance(r, 0.6)) { /* never hit */ } else { stats.total = rint(r, 1, 200); stats.last_hit = isoAtom(now - rint(r, 95, 400) * DAY); }
    } else if (!expired && rule.enabled && roll < 0.15) {
      const age = rint(r, 1, 13);
      created = now - age * DAY;
      if (chance(r, 0.6)) fillDaily(r, stats, now, age, age);
    } else {
      const minOffset = expired ? Math.floor((now - Date.parse(rule.expires_at!)) / DAY) : 0;
      if (minOffset < 90) fillDaily(r, stats, now, 90, 90 - minOffset, minOffset);
      else { stats.total = rint(r, 1, 300); stats.last_hit = isoAtom(now - (minOffset + rint(r, 1, 30)) * DAY); }
    }
    const oldest = Object.keys(stats.daily).sort()[0];
    if (oldest) created = Math.min(created, Date.parse(oldest) - rint(r, 3, 400) * DAY);
    rule.created_at = isoAtom(created);
    rule.updated_at = isoAtom(created + rint(r, 0, Math.max(0, Math.floor((now - created) / DAY))) * DAY);
  }
}

/** Sparse daily hits over the last `span` days, biased to recent days; total >= sum(daily). */
function fillDaily(r: Rng, stats: NonNullable<Rule['stats']>, now: number, maxDays: number, span: number, minOffset = 0): void {
  const w = Math.max(1, Math.min(span, rint(r, 30, 90)));
  const total = Math.floor(Math.exp(r() * 6.5)) + 1;
  const k = Math.min(total, w, rint(r, 1, 25));
  const days = new Set<number>();
  for (let i = 0; i < k * 3 && days.size < k; i++) days.add(minOffset + Math.min(maxDays - 1 - minOffset, Math.floor(r() * r() * w)));
  const daily: Record<string, number> = {};
  const offs = [...days];
  let left = total;
  offs.forEach((o, i) => {
    const n = i === offs.length - 1 ? Math.max(1, Math.min(left, rint(r, 1, Math.ceil(total / k) * 2))) : Math.max(1, Math.min(left - (offs.length - 1 - i), rint(r, 1, Math.ceil(total / k) * 2)));
    daily[dayKey(now - o * DAY)] = n;
    left -= n;
  });
  const sum = Object.values(daily).reduce((a, b) => a + b, 0);
  const newest = Math.min(...offs);
  stats.daily = daily;
  stats.total = sum + (chance(r, 0.4) ? rint(r, 0, sum * 3) : 0);
  stats.last_hit = isoAtom(Math.min(now - 1000, Date.parse(dayKey(now - newest * DAY)) + rint(r, 0, 86399) * 1000));
}

/* ---- 404 monitor ---------------------------------------------------------------------------- */

const BOTS = ['/wp-login.php', '/xmlrpc.php', '/.env', '/wp-admin/', '/phpmyadmin/', '/.git/config', '/administrator/', '/wp-config.php.bak', '/backup.sql', '/.aws/credentials', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', '/cgi-bin/luci', '/actuator/health', '/server-status', '/admin/login', '/config.json', '/wp-content/plugins/revslider/temp/update_extract/revslider.zip', '/solr/admin/info/system', '/.DS_Store', '/wp-includes/wlwmanifest.xml', '/owa/auth/logon.aspx', '/boaform/admin/formLogin'];
const REFERERS = ['https://www.google.com/', 'https://www.bing.com/', 'https://duckduckgo.com/', 'https://newsletter.example.test/2026-09', 'https://www.example-blog.de/webdesign-tipps', 'https://de.pinterest.com/', ''];
const UA: Record<UaClass, string[]> = {
  browser: ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'],
  bot: ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'python-requests/2.31.0'],
  monitoring: ['UptimeRobot/2.0; http://www.uptimerobot.com/', 'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)'],
  unknown: ['', 'curl/8.4.0', 'Go-http-client/1.1'],
};
export const uaSamples = UA;
export const referersPool = REFERERS;

type Kind = 'bot' | 'typo' | 'wp' | 'blog' | 'image' | 'shop' | 'misc' | 'en' | 'rule';
const UA_MIX: Record<Kind, [UaClass, number][]> = {
  bot: [['bot', 80], ['unknown', 20]], typo: [['browser', 90], ['bot', 5], ['unknown', 5]], wp: [['browser', 45], ['bot', 45], ['unknown', 10]],
  blog: [['browser', 60], ['bot', 35], ['unknown', 5]], image: [['browser', 35], ['bot', 55], ['unknown', 10]], shop: [['browser', 80], ['bot', 15], ['unknown', 5]],
  misc: [['browser', 40], ['monitoring', 25], ['bot', 25], ['unknown', 10]], en: [['browser', 85], ['bot', 10], ['unknown', 5]], rule: [['browser', 80], ['bot', 15], ['unknown', 5]],
};

function typo(r: Rng, s: string): string {
  const i = rint(r, 1, Math.max(1, s.length - 2));
  return chance(r, 0.5) ? s.slice(0, i) + s.slice(i + 1) : s.slice(0, i) + (s[i + 1] ?? '') + (s[i] ?? '') + s.slice(i + 2);
}

export function buildNotFound(r: Rng, now: number, pages: PageHit[], ruleSources: string[]): NotFoundGroup[] {
  const paths: [string, Kind][] = [];
  const seen = new Set<string>();
  const add = (p: string, k: Kind) => { if (!seen.has(p)) { seen.add(p); paths.push([p, k]); } };
  const dePages = pages.filter((p) => p.language === 'de' && p.route.length > 5 && !p.route.startsWith('/journal/'));
  BOTS.forEach((p) => add(p, 'bot'));
  for (let i = 0; i < 20; i++) { const p = pick(r, dePages).route; add(p.replace(/[^/]+$/, (m) => typo(r, m)), 'typo'); }
  ['/kontackt', '/ueber_uns', '/impressum.html', '/products/', '/kontakt-us'].forEach((p) => add(p, 'typo'));
  for (let i = 0; i < 10; i++) add(`/${rint(r, 2016, 2021)}/${String(rint(r, 1, 12)).padStart(2, '0')}/${pick(r, MODS)}-${pick(r, TOPICS)}/`, 'wp');
  for (let i = 0; i < 6; i++) add(`/wp-content/uploads/${rint(r, 2016, 2021)}/${String(rint(r, 1, 12)).padStart(2, '0')}/${pick(r, PRODUCTS)}-${rint(r, 1, 20)}.jpg`, 'image');
  ['/category/allgemein/', '/tag/seo/', '/feed/', '/author/admin/', '/page/2/', '/comments/feed/', '/wp-json/wp/v2/users', '/?p=123'.split('?')[0]! + 'p/123', '/blog/feed/', '/sitemap_index.xml'].forEach((p) => add(p, 'wp'));
  for (let i = 0; i < 20; i++) add(`/blog/${rint(r, 2018, 2020)}/${pick(r, MODS)}-${pick(r, TOPICS)}`, 'blog');
  for (let i = 0; i < 16; i++) add(`/${pick(r, ['images', 'assets/img', 'user/images', 'bilder'])}/${pick(r, PRODUCTS)}-${pick(r, MATERIALS)}.${pick(r, ['jpg', 'png', 'webp'])}`, 'image');
  for (let i = 0; i < 16; i++) add(pick(r, [`/produkt/${pick(r, PRODUCTS)}-${pick(r, MATERIALS)}.html`, `/shop/alt/${pick(r, PRODUCTS)}-${rint(r, 1, 99)}`, `/shop/${pick(r, CATS)}/${pick(r, PRODUCTS)}-${pick(r, MATERIALS)}`]), 'shop');
  ['/index.php', '/downloads/katalog-2017.pdf', '/downloads/preisliste-2018.pdf', '/favicon.ico', '/apple-touch-icon.png', '/status', '/health', '/robots.txt.old', '/ads.txt', '/humans.txt'].forEach((p) => add(p, 'misc'));
  for (let i = 0; i < 10; i++) add('/en/' + typo(r, pick(r, dePages).route.slice(1)), 'en');
  ruleSources.filter((s) => !s.includes('?') && !s.includes('*') && !s.startsWith('^')).slice(0, 8).forEach((p) => add(p, 'rule'));

  const groups = paths.map(([path, kind]): NotFoundGroup => {
    const g = mulberry32(hash(path) ^ 0x51ed);
    const start = kind === 'rule' ? rint(g, 30, 89) : rint(g, 4, 89);
    const end = kind === 'rule' ? rint(g, 10, 30) : chance(g, 0.5) ? rint(g, 0, 2) : rint(g, 0, Math.max(0, start - 3));
    const lam = kind === 'bot' ? rint(g, 1, 6) : kind === 'misc' ? rint(g, 2, 20) : rint(g, 1, kind === 'typo' || kind === 'shop' ? 14 : 8);
    const p = 0.15 + g() * 0.6;
    const spike = rint(g, end, start);
    const daily: Record<string, number> = {};
    for (let d = start; d >= end; d--) {
      if (d === end || chance(g, p)) daily[dayKey(now - d * DAY)] = Math.max(1, Math.round(lam * (0.4 + g() * 1.2))) * (d === spike && kind !== 'bot' && g() > 0.5 ? 6 : 1);
    }
    const total = Object.values(daily).reduce((a, b) => a + b, 0);
    const ua: Record<UaClass, number> = { browser: 0, bot: 0, monitoring: 0, unknown: 0 };
    let left = total;
    UA_MIX[kind].forEach(([c, w], i, a) => { const n = i === a.length - 1 ? left : Math.round((total * w) / 100); ua[c] = n; left -= n; });
    const refs: Record<string, number> = {};
    const bot = kind === 'bot';
    (bot ? [''] : shuffle(g, [...REFERERS]).slice(0, rint(g, 2, 5))).forEach((ref, i) => (refs[ref] = Math.max(1, Math.round(total * (bot ? 1 : 0.5 / (i + 1))))));
    return {
      path, daily, referers: refs, ua,
      languages: kind === 'en' ? ['en'] : chance(g, 0.25) ? ['de', 'en'] : ['de'],
      hosts: chance(g, 0.2) ? ['example.test', 'www.example.test'] : ['example.test'],
      resolved: false,
      suggestion: kind === 'bot' || kind === 'misc' ? null : (suggest(pages, path, 1)[0] ?? null),
    };
  });
  shuffle(r, groups.filter((g) => g.suggestion).map((g) => g)).slice(0, 5).forEach((g) => (g.suggestion = null));
  groups.filter((g) => g.suggestion && chance(r, 0.05)).forEach((g) => (g.resolved = true));
  return groups;
}

/* ---- suggestions ---------------------------------------------------------------------------- */

const REASONS: SuggestionReason[] = ['same_slug', 'other_language', 'similar_route', 'title_match', 'taxonomy_match', 'parent_fallback', 'home_fallback'];
const SCORE: Record<SuggestionReason, [number, number]> = { same_slug: [0.9, 0.99], other_language: [0.8, 0.9], similar_route: [0.6, 0.88], title_match: [0.5, 0.8], taxonomy_match: [0.45, 0.7], parent_fallback: [0.35, 0.5], home_fallback: [0.3, 0.35] };

export function buildSuggestions(r: Rng, now: number, groups: NotFoundGroup[], pages: PageHit[]): StoredSuggestion[] {
  const hitsOf = (g: NotFoundGroup) => Object.values(g.daily).reduce((a, b) => a + b, 0);
  const cands = groups.filter((g) => !g.resolved && g.suggestion).sort((a, b) => hitsOf(b) - hitsOf(a));
  const out: StoredSuggestion[] = [];
  const mk = (path: string, target: string, score: number, reason: SuggestionReason, title: string, hits: number): StoredSuggestion => ({
    id: 'sg' + Math.floor(r() * 0xffffffff).toString(16).padStart(8, '0'), path, target, score: Math.round(score * 100) / 100, reason, page_title: title,
    hits, status: weighted(r, [['open', 70], ['accepted', 15], ['rejected', 15]] as const), source: weighted(r, [['auto', 75], ['sitemap', 15], ['import', 10]] as const),
    created_at: isoAtom(now - rint(r, 0, 60) * DAY),
  });
  for (const g of cands.slice(0, 34)) out.push(mk(g.path, g.suggestion!.target, g.suggestion!.score, g.suggestion!.reason, g.suggestion!.page_title, hitsOf(g)));
  for (const reason of REASONS) {
    if (out.some((s) => s.reason === reason)) continue;
    const g = pick(r, groups);
    const page = pick(r, pages.filter((p) => p.language === 'de'));
    const [lo, hi] = SCORE[reason];
    out.push(mk(g.path, page.route, lo + r() * (hi - lo), reason, page.title, hitsOf(g)));
  }
  while (out.length < 40) {
    const g = pick(r, groups);
    const page = pick(r, pages);
    const reason = pick(r, REASONS);
    const [lo, hi] = SCORE[reason];
    out.push(mk(g.path, page.route, lo + r() * (hi - lo), reason, page.title, hitsOf(g)));
  }
  return out;
}

/* ---- checks, pending, site config, state ---------------------------------------------------- */

export function buildChecks(r: Rng, rules: Rule[], dead: ReadonlyMap<string, number>, ids: string[] | null, now: number): CheckResult[] {
  const rows: Rule[] = ids ? rules.filter((x) => ids.includes(x.id)) : [...rules.filter((x) => dead.has(x.id)), ...shuffle(r, rules.filter((x) => x.enabled && !dead.has(x.id) && x.status !== 410 && x.status !== 451)).slice(0, 60)];
  return rows.filter((x) => x.target).map((x): CheckResult => {
    const st = dead.get(x.id);
    const url = x.target_type === 'url' ? x.target : 'https://example.test' + x.target.split('?')[0]!.replace(/\$\d|\{[^}]+\}/g, 'x');
    const timeout = st === 0;
    const status = st ?? (chance(r, 0.15) ? 301 : 200);
    return { rule_id: x.id, url, status: timeout ? 0 : status, ok: st === undefined, error: timeout ? 'Timeout nach 10 s' : null, final_url: timeout ? null : status === 301 ? url + '/' : url, redirects: status === 301 ? 1 : 0, duration_ms: rint(r, 40, timeout ? 10000 : 900), checked_at: isoAtom(now - rint(r, 0, 3) * 3600_000) };
  });
}

export function buildState(opts: ResolvedOptions): MockState {
  const r = mulberry32(opts.seed);
  const now = Date.now();
  const pages = buildPages();
  const rules = buildRules(r, Math.max(1, Math.min(10000, opts.rules)), now, pages);
  const dead = new Map<string, number>();
  const live = rules.filter((x) => x.enabled && x.target && REDIRECTABLE.includes(x.status) && !(x.expires_at && Date.parse(x.expires_at) < now) && !(x.active_from && Date.parse(x.active_from) > now));
  shuffle(r, live).slice(0, Math.round(rules.length * 0.03)).forEach((x) => dead.set(x.id, weighted(r, [[404, 70], [410, 5], [500, 15], [0, 10]] as const)));
  analyseAll(rules, { now, dead });
  const notFound = buildNotFound(r, now, pages, rules.filter((x) => x.match_type === 'exact').map((x) => x.source));
  const state: MockState = {
    opts, rules, notFound, pages, dead,
    suggestions: buildSuggestions(r, now, notFound, pages),
    pending: [
      { id: 'd1a0ed959231866287', title: 'Alter Artikel (2022)', route: '/blog/alter-artikel-2022', routes: { '*': '/blog/alter-artikel-2022' }, languages: [], children: [], children_count: 0, deleted_at: isoAtom(now - 2 * DAY), suggested_parent: '/blog' },
      { id: 'd1a0ed95923186628a', title: 'Printdesign', route: '/leistungen/printdesign', routes: { '*': '/leistungen/printdesign' }, languages: [], children: ['/leistungen/printdesign/flyer', '/leistungen/printdesign/plakate', '/leistungen/printdesign/visitenkarten'], children_count: 3, deleted_at: isoAtom(now - 5 * DAY), suggested_parent: '/leistungen' },
      { id: 'd1a0ed95923186628d', title: 'Eckbank Eiche', route: '/shop/moebel/eckbank-eiche', routes: { '*': '/shop/moebel/eckbank-eiche' }, languages: [], children: [], children_count: 0, deleted_at: isoAtom(now - 9 * DAY), suggested_parent: '/shop/moebel' },
    ],
    unseen: rules.filter((x) => x.origin === 'auto').slice(0, 3).map((x) => x.id),
    checks: { last_run: isoAtom(now - 3 * 3600_000), results: [] },
    ignorePatterns: ['/wp-content/plugins/*', '*.map'],
    siteConfig: {
      redirects: { '/old-blog': '/blog', '/impressum.html': '/impressum', '/news': '/journal', '/downloads/katalog': '/user/downloads/katalog.pdf', '/produkte-alt/(.*)': '/produkte/$1' },
      routes: { '/home': '/', '/shop-start': '/shop', '/agb-neu': '/agb' },
      settings: { redirect_default_route: '', redirect_default_code: 302, redirect_trailing_slash: true, redirect_to_lang: false, home_route: '/' },
    },
    groups: [], rev: new Map(), seq: 1, lastCheckRun: 0,
  };
  state.checks.results = buildChecks(r, rules, dead, null, now);
  state.groups = [...new Set(rules.map((x) => x.group).filter(Boolean))].sort();
  return state;
}
const REDIRECTABLE = [301, 302, 307, 308];
