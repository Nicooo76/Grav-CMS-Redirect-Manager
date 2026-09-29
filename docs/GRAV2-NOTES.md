# Grav 2 notes for Redirect Manager

Research done on 2026-09-29 against Grav 2.2.2 (`getgrav/grav@d7f46bf`), API plugin 1.0.42 (`getgrav/grav-plugin-api@e220660`), Admin 2 2.1.25 (`getgrav/grav-plugin-admin2@eb09166`, SPA source `getgrav/grav-admin-next@230ed21`) and grav-mcp (`getgrav/grav-mcp@f569737`). Every statement in the appendices cites a file and line in those commits or a page under `https://learn.getgrav.org/2/`. Note: the docs moved from `/20` (404) to `/2`.

Section 1 lists the binding answers this plugin is built on. Section 2 lists what was verified on a running Grav 2.2.2 site. Appendix A (core and API) and appendix B (Admin 2) hold the evidence.

## 1. Binding answers

### Grav version and manifest

- Current stable is **2.2.2** (2026-09-28). The API plugin requires `grav >= 2.1.5`, Admin 2 requires `api >= 1.0.40`.
- `blueprints.yaml`: `compatibility: { grav: ['2.0'] }`. GPM compares only the major version (`GPM.php:1044-1067`).
- Dependencies: `{ name: grav, version: '>=2.1.5' }`, `{ name: php, version: '>=8.3' }`. **No hard dependency on `api` or `admin2`**: without them the `onApi*` events never fire, frontend redirects and CLI keep working. The API plugin is listed as recommended in the README.

### Request pipeline: where redirects happen

- Middleware order (`Grav.php:144-158`): initialize, plugins, themes, request, tasks, backups, scheduler, assets, twig, pages, render.
- Core runs the trailing-slash redirect inside `InitializeProcessor` (`:117-125`) before any regular plugin event. `/old/` therefore reaches the plugin as `/old` after one hop when `system.pages.redirect_trailing_slash` is on. We respect that setting and do not fight it.
- The documented early hook is `onRequestHandlerInit` (`RequestProcessor.php:60-68`, `$event->setResponse()` ends the request). The runtime check in section 2 showed that responses sent there already carry the session cookie and `Cache-Control: no-store`. **The plugin therefore sends 30x redirects from `Grav\Events\PluginsLoadedEvent`**, the first event a plugin sees (`Plugins.php:188`): before the session, before the trailing-slash redirect, with config and the plugin autoloader ready. Base path and language prefix are resolved by the plugin itself.
- **404 hook: `onPageNotFound`** (`PagesProcessor.php:67-86`). The error plugin listens at priority 0 and stops propagation, so we listen at priority 10.
- Core's own `site.redirects` / `site.routes` / page `redirect:` header run lazily when `$grav['page']` resolves (`Pages::dispatch`, `Pages.php:1348-1422`), after `onPagesInitialized`. `site.redirects` only applies when no routable page exists. We never modify these settings; the admin shows them read-only with an import action.
- Redirect code handling in core: `[30x]` suffix in a target, else `system.pages.redirect_default_code` (`Grav.php:621-690`). Our rules carry their own status; `redirect_default_code` becomes the default for new rules.

### Page change events (Admin 2 goes through the API plugin)

| Event | Payload | Use |
|---|---|---|
| `onApiBeforePageUpdate` | `page` (old state), `data` | Capture old route + descendants + per-language routes |
| `onApiPageUpdated` | `page` (new state) | Compare with captured state, create redirects on slug change |
| `onApiPageMoved` | `page`, `old_route`, `new_route`, optional `method: reorganize` | Create redirects for moved root and descendants |
| `onApiBeforePageDelete` | `page`, optional `lang` | Capture routes of the subtree |
| `onApiPageDeleted` | `route`, optional `lang` | Apply the delete policy (ask / 410 / parent / never) |

Only the moved root is reported; descendants are derived from the captured subtree. Changes made outside the API (FTP, Git pull) fire none of these events; the 404 monitor and suggestions cover that case.

### API routes, auth, permissions

- Register routes in `onApiRegisterRoutes` (`$event['routes']->get|post|patch|delete(path, [Controller::class, 'method'])`), relative to `/api/v1`. Controllers extend `Grav\Plugin\Api\Controllers\AbstractApiController` and use `requirePermission()`, `ApiResponse::create|paginated|created|noContent` and the typed exceptions (RFC 7807 errors).
- The FastRoute table is cached and only rebuilt when a plugin's `blueprints.yaml` mtime changes; every route change ships with a version bump.
- Auth is handled by the API plugin (API key, JWT via `X-API-Token` or Bearer, session cookie). There is no CSRF nonce: cookie-only writes must pass the API plugin's `SameOriginGuard`. The plugin adds nothing on top except its own permission checks.
- Permissions are registered through the class event `Grav\Events\PermissionsRegisterEvent` with a `permissions.yaml`. The API resolves and lists only `api.*` permissions, so the plugin uses **`api.redirects.read`** and **`api.redirects.manage`** (see DECISIONS.md, D-003).
- No OpenAPI contribution hook exists; the plugin ships `docs/openapi.yaml`.

### MCP

- grav-mcp is a Node app that talks to the API. Plugins contribute tools declaratively with **`mcp.yaml`** in the plugin root (prefix + tools mapped to API routes, JSON-schema subset, permission per tool). grav-mcp loads them at startup. Multipart uploads are not supported, so imports go through a JSON body.

### Scheduler and CLI

- Jobs: `onSchedulerInitialized`, `$scheduler->addFunction('Class::staticMethod', $args, 'id')->at('cron')`. The event only fires in scheduler runs (CLI/webhook), never on page requests.
- CLI: `cli/<Name>Command.php`, class `Grav\Plugin\Console\<Name>Command extends Grav\Console\ConsoleCommand`, implement `configure(): void` and `serve(): int`, include the plugin autoloader and call `initializePlugins()` inside `serve()`. `onCliInitialize` no longer fires.

### Admin 2 integration

- Admin 2 is a SvelteKit SPA. Plugins do not add Svelte components to its build; they ship **self-contained JavaScript files that define a custom element**, loaded at runtime through a Blob `import()`:
  - Sidebar: `onApiSidebarItems` (label is a translation key, icon Font Awesome, `route: /plugin/<slug>`, `authorize`).
  - Page: `onApiPluginPageInfo` with `page_type: component` + `admin-next/pages/<slug>.js`, tag from `window.__GRAV_PAGE_TAG`. One route per plugin, so sub-pages use a hash router inside the element.
  - Dashboard widget: `onApiDashboardWidgets` + `admin-next/widgets/<slug>.js`, tag from `window.__GRAV_WIDGET_TAG`, attributes `size`, `data-endpoint`, `plugin`, `widget-id`.
  - Custom blueprint field: `admin-next/fields/<type>.js`.
- Context comes from globals: `__GRAV_API_SERVER_URL`, `__GRAV_API_PREFIX`, `__GRAV_API_TOKEN` (re-read on every request, rotates hourly), `__GRAV_I18N`, `__GRAV_TOAST`, `__GRAV_DIALOGS`, `__GRAV_NAVIGATE`, `__GRAV_ENVIRONMENT`.
- Design tokens are CSS custom properties that cross the shadow DOM boundary: `--background`, `--foreground`, `--card`, `--muted`, `--muted-foreground`, `--primary`, `--destructive`, `--success`, `--warning`, `--border`, `--input`, `--ring`, `--radius`, `--font-sans`. Dark mode is the `dark` class on `<html>`. Icons: Lucide in the UI, Font Awesome for sidebar/page icons.
- **Build decision:** the prompt asks for a Vite build if plugins can ship a frontend bundle. They can, as long as the output is one self-contained ES file. Team Grav's own plugins hand-write these files; we build ours from Svelte 5 with Vite into single files and commit them (see DECISIONS.md, D-010). The limit is one plugin page route; the plugin handles sub-views itself.

## 2. Runtime verification (Grav 2.2.2, PHP built-in server, 2026-09-29)

A throwaway probe plugin logged what Grav hands to plugins. Results that changed or confirmed the design:

| Question | Observed | Consequence |
|---|---|---|
| Route in `onRequestHandlerInit` | `getRoute()->getRoute()` is still percent-encoded (`/a%20b`, `/%C3%BCber`), collapses `//`, strips the query and known extensions (`/x.json` becomes `/x`), never contains the language prefix (`/de/typography` gives `/typography` + language `de`) | We normalize the raw path ourselves and keep extensions, so `/old.html` rules work |
| Redirect from `onRequestHandlerInit` | Clean 301, but the session is already started: the response carries `Set-Cookie`, `Expires: 1981` and `Cache-Control: no-store` | Browsers and CDNs would never cache our 301s. Not acceptable |
| `getRedirectResponse()` | Passes absolute URLs unchanged; coerces 410 to `redirect_default_code` (302); a `.json` request gets a 200 JSON body | We build our own PSR-7 responses |
| `PluginsLoadedEvent` | Fires before the trailing-slash redirect and before the session starts. `$grav['request']` and `$_SERVER` are readable; config and the plugin autoloader are ready (`Plugins.php:149-188`). `$grav->close($response)` sends a clean 301 without cookie and `onShutdown` still fires | **Chosen as the redirect point for 30x rules** |
| Language / base path at `PluginsLoadedEvent` | Not resolved yet (`Uri::init()` runs later) | `RequestContextFactory` strips the base path (same logic as `Uri::buildRootPath()`, `Uri.php:1559-1566`, plus `system.custom_base_url`) and the language prefix (`system.languages.supported`) itself |
| Twig in `onPagesInitialized` | `processTemplate()` works with a plugin template path | 410 and 451 pages render there, theme-overridable |
| Internal rewrite | `unset($grav['page']); $grav['page'] = $pages->find('/target')` in `onPagesInitialized` serves the target with 200, no `onPageNotFound` | Pass-through (`status: 200`) uses exactly this |
| `onPageNotFound` | Priority 10 runs before the error plugin (0, stops propagation); fires for `/missing`, `/x.jpg`, `/x.php`, `/x.pdf` under the PHP server | 404 logging and "only if not found" rules live here. On Apache/nginx, static-file rules may keep such URLs from reaching Grav |
| Trailing slash | `/typography/` answers `302` with an absolute Location before any regular plugin event | Matching at `PluginsLoadedEvent` avoids the double hop for our rules; other URLs keep Grav's behaviour |
| Page routes | `route()` never has the language prefix; translated slugs differ per language (`/probe-de` in `de`, `/probe-i18n` in `en`), `rawRoute()` is language-neutral | Rules store language-free paths; auto-redirects capture routes per language |
| `/api/*` and `/admin` | Both pass `onRequestHandlerInit`; admin2 answers from `onPagesInitialized` | The plugin never matches below the API route or the admin route |
| API page events (Admin 2 as client, verified in `tests/Integration/AutoRedirect`) | `PATCH /pages/{route}` with `header.slug` changes the public route but not the folder; `Pages::find()` keeps resolving the old structural route (`/blog` still answers 200 next to `/news`), so an auto redirect must come from the rule table, and "is this route live" must compare `$page->route()` with the route. After `onApiPageUpdated` the in-memory tree is stale until `$pages->reset()`. `onApiPageMoved` fires after `Pages::reset()`; the old per-language routes are gone by then | `Auto/PageSnapshotter::reload()`, `MoveDeriver` |
| Scheduler CLI (Grav 2.2.2) | `bin/grav scheduler --jobs` lists jobs, `--run=<job id>` runs one job now (also when not due), `--force` runs all, `--catch-up` runs missed slots. Callable jobs run in the scheduler process and only `RuntimeException` is caught: other throwables abort the run, so the plugin's jobs rethrow as `RuntimeException`. `$grav['pages']` is disabled in CLI and API requests until `enablePages()` | `Grav/SchedulerJobs`, `PageSnapshotter::pages()` |

Not tested yet: subfolder installs, reverse proxies, Apache and nginx. The integration suite covers the subfolder case through `system.custom_base_url`.

## Appendix A: Grav core and API plugin

Research date: 2026-09-29. Every claim below cites a source file (path relative to the clone, with line numbers) or a URL.
Line numbers are from the clones listed in section 0 and may shift with later commits.

### 0. Clones, commits, corrections to the brief

Paths below are relative to the repository named in the first column.

| Dir | Repo | Branch @ commit | Version read |
|---|---|---|---|
| `grav-master/` | getgrav/grav | `master` @ d7f46bf (2026-09-28) | `GRAV_VERSION` 2.2.2 (`system/defines.php:12`) |
| `grav-plugin-api/` | getgrav/grav-plugin-api | `develop` @ e220660 (2026-09-28) | 1.0.42 (`blueprints.yaml:4`) |
| `grav-mcp/` | getgrav/grav-mcp | `main` @ f569737 (2026-09-09) | npm package, not a Grav plugin |
| `grav-plugin-admin2/` | getgrav/grav-plugin-admin2 | `develop` @ eb09166 | 2.1.25 |
| `grav-plugin-error/` | getgrav/grav-plugin-error | `develop` @ 33a5f47 | 2.0.4 (owns the 404 page) |
| `grav-plugin-consent/`, `-email`, `-form`, `-login`, `-license-manager`, `-problems`, `-sync`, `-sync-mercure`, ... | getgrav/* | `develop` | used as real-world 2.x examples |
| `grav-skills/` | getgrav/grav-skills | `main` @ eab567e | Team Grav's own plugin review checklist (`skills/grav-plugin-review/SKILL.md`) |
| `grav-admin-next/` | Admin 2 SPA | `main` @ 230ed21 | frontend source |
| `docs/` | learn.getgrav.org/2/* saved as text | - | see section 12 |

Some of the extra plugin clones were already in this directory when I started (another session cloned them). I only relied on them as read-only examples.

Corrections to the brief:
- Current stable is **2.2.2** (tag list `git ls-remote --tags https://github.com/getgrav/grav` shows 2.1.1 ... 2.1.12, 2.2.0, 2.2.1, 2.2.2). `CHANGELOG.md:1-3` = v2.2.2, 09/28/2026. The API plugin needs `grav >=2.1.5` (`grav-plugin-api/blueprints.yaml:20`).
- Branches in getgrav/grav: `master` (= tag 2.2.2), `develop` (default, 3d3cbbd), `2.0`, `1.7`, `1.8`. I read `master`.
- The docs live under `https://learn.getgrav.org/2/...`, not `/20`. `https://learn.getgrav.org/20` returns 404.
- The classic admin is gone. Admin 2 (SPA) talks to the API plugin. All `onAdmin*` events are gone from the UI side, but the API plugin re-fires several for compatibility (section 6.4).

---

### 1. Request pipeline: where a plugin can redirect earliest

#### 1.1 Middleware order

`system/src/Grav/Common/Grav.php:144-158` (protected `$middleware`):

```php
'multipartRequestSupport', 'initializeProcessor', 'pluginsProcessor', 'themesProcessor',
'requestProcessor', 'tasksProcessor', 'backupsProcessor', 'schedulerProcessor',
'assetsProcessor', 'twigProcessor', 'pagesProcessor', 'debuggerAssetsProcessor', 'renderProcessor',
```

`Grav::process()` builds the PSR-15 chain at `Grav.php:275-320`. If no middleware answers, the default is `404 'Not Found'` (`Grav.php:303`).

#### 1.2 What happens in InitializeProcessor (before any plugin event except PluginsLoadedEvent)

`system/src/Grav/Common/Processors/InitializeProcessor.php:70-135`, in order:

1. config, logger, errors, debugger (lines 75-84)
2. `initializePlugins()` (line 101) which calls `$plugins->init()`. That fires **`PluginsLoadedEvent`** (`Plugins.php:188`, class `Grav\Events\PluginsLoadedEvent`: "This is the first event plugin can see", `system/src/Grav/Events/PluginsLoadedEvent.php:19-25`)
3. `initializePages()` -> `$pages->register()` (line 104; pages are NOT built yet)
4. `initializeSession()` (line 111)
5. `initializeUri()` -> `$uri->init()` (line 114)
6. **Trailing-slash redirect** (lines 117-125): `if system.pages.redirect_trailing_slash` -> `handleRedirectRequest()` (defined at line 432, GET/HEAD only). It returns a redirect response before any plugin sees the request other than via `PluginsLoadedEvent`.
7. `$handler->handle($request)` continues to the next middleware.

Consequence: `/old-page/` gets a trailing-slash redirect to `/old-page` first (default code from `redirect_trailing_slash`, `1` means default code, `>300` means that code). A redirect plugin subscribed to `onPluginsInitialized` or `onRequestHandlerInit` only sees the second hop. Only a `PluginsLoadedEvent` listener runs before it (but then `$grav['uri']` is not initialised yet; see open questions).

#### 1.3 The events that follow

| Order | Middleware | Event | Source |
|---|---|---|---|
| 1 | `PluginsProcessor` | `onPluginsInitialized` | `Processors/PluginsProcessor.php:36` |
| 2 | `ThemesProcessor` | `onThemeInitialized` | docs lifecycle |
| 3 | `RequestProcessor` | **`onRequestHandlerInit`** (`RequestHandlerEvent` with `request`, `handler`) | `Processors/RequestProcessor.php:60-68` |
| 4 | `TasksProcessor` | `onTask`, `onAction` | docs lifecycle |
| 5 | `SchedulerProcessor` | no longer fires `onSchedulerInitialized` on web requests | `Processors/SchedulerProcessor.php:34-38` |
| 6 | `TwigProcessor` | `onTwigTemplatePaths`, `onTwigInitialized`, ... | docs |
| 7 | `PagesProcessor` | `pages->init()` (builds page tree; `onBuildPagesInitialized`, `onPageProcessed`), then **`onPagesInitialized`**, then resolves `$grav['page']`, then **`onPageInitialized`**, then **`onPageNotFound`** if not routable | `Processors/PagesProcessor.php:45-90` |

#### 1.4 Answer: earliest redirect point with URI available

`onRequestHandlerInit` (`RequestProcessor.php:60-68`):

```php
$event = new RequestHandlerEvent(['request' => $request, 'handler' => $handler]);
$event = $this->container->fireEvent('onRequestHandlerInit', $event);
$response = $event->getResponse();
...
if ($response) { return $response; }   // request ends here
```

`RequestHandlerEvent` API (`Processors/Events/RequestHandlerEvent.php`):

```php
public function getRequest(): ServerRequestInterface        // attributes: grav, time, route, referrer
public function getRoute(): Route                           // request attribute 'route'
public function setResponse(ResponseInterface $response): self   // also stopPropagation()
public function addMiddleware(string $name, MiddlewareInterface $middleware): self
```

Recipe (confirmed API, pattern mirrors `grav-plugin-api/api.php:43-45, 596-608`):

```php
public static function getSubscribedEvents(): array {
    return ['onRequestHandlerInit' => ['onRequestHandlerInit', 1000]];   // higher = earlier
}
public function onRequestHandlerInit(RequestHandlerEvent $event): void {
    $path = $event->getRoute()->getRoute();          // e.g. "/old/page" (no base, no language prefix? see Open Q)
    // ... lookup ...
    $event->setResponse($this->grav->getRedirectResponse($target, 301));
}
```

At this point: config, plugins, session (if enabled), URI/language, request are ready; **pages are not built** (so no "does a page exist for this URL?" check without building the tree). Page-independent redirects (exact/regex from a table) belong here.

Alternative that also works but is later: call `$grav->redirect($url, $code)` (`Grav.php:607`), which calls `close()` and `exit`s (in CLI it throws, `Grav.php:508-520`).

`onPluginsInitialized` (fires before `onRequestHandlerInit`) can also call `$grav->redirect()`, but has no `setResponse` hook.

#### 1.5 Answer: the 404 event

`Processors/PagesProcessor.php:67-86`:

```php
if (!$page->routable()) {
    $exception = new RequestException($request, 'Page Not Found', 404);
    $event = new PageEvent([
        'page' => $page, 'code' => $exception->getCode(), 'message' => $exception->getMessage(),
        'exception' => $exception, 'route' => $route, 'request' => $request
    ]);
    $event->page = null;
    $event = $this->container->fireEvent('onPageNotFound', $event);
    if (isset($event->page)) {
        unset($this->container['page']);
        $this->container['page'] = $page = $event->page;
    } else {
        throw new RuntimeException('Page Not Found', 404);
    }
```

- The listener receives `page` (already set to null before dispatch), `code`, `message`, `exception`, `route`, `request`.
- Setting `$event->page` supplies the fallback page (this is what the error plugin does); leaving it unset makes core throw the 404.
- To redirect from here call `$this->grav->redirect($url, 301)` (or `$grav->close($response)`); both exit.
- **Priority matters.** The official error plugin subscribes at priority 0 and calls `stopPropagation()` (`grav-plugin-error/error.php:23-25, 66-70`). A redirect plugin must use a priority above 0 (for example 10) or it never runs.
- Twig fires a second, different event when a template throws an HTTP exception: `onDisplayErrorPage.{code}` (`Common/Twig/Twig.php:643-655`; error plugin listens at `onDisplayErrorPage.404` priority -1, `error.php:32-34`). The docs list `onPageNotFound` in `Twig.php` too (learn.getgrav.org/2/plugins/grav-lifecycle), but the code only fires it in `PagesProcessor.php:79` (`grep -rn onPageNotFound system/src`).
- Media-like URLs go through `onPageFallBackUrl` first (`Grav.php:962-1006`, called from `PagesServiceProvider.php:134`) and only 404 afterwards.

For a 404 log: subscribe to `onPageNotFound` at a priority above the error plugin, do not stopPropagation, do not set `$event->page`.

#### 1.6 Where core already processes redirects (do not duplicate blindly)

The `$grav['page']` service closure is executed lazily the first time something reads it. In `PagesProcessor` that happens on line 58 (building the `onPageInitialized` event), i.e. AFTER `onPagesInitialized` and BEFORE `onPageInitialized`/`onPageNotFound`. All the following happen inside that closure or `Pages::dispatch()`:

`system/src/Grav/Common/Service/PagesServiceProvider.php:52-140`
```php
$path  = $uri->path() ? urldecode($uri->path()) : '/';
$page  = $pages->dispatch($path);                       // line 63
if ($config->get('system.force_ssl')) { ... $grav->redirect('https://' . $host . $uri->uri()); }   // 68-75
...
$redirect_default_route = $page->header()->redirect_default_route ?? $config->get('system.pages.redirect_default_route', 0);  // 106
$redirectCode = (int) $redirect_default_route;
if ($language->enabled() && ($language->isLanguageInUrl() xor $language->isIncludeDefaultLanguage())) { $grav->redirect($url, $redirectCode); }  // 111
if ($redirectCode) { ... if ($route !== $requested || ...) { $grav->redirect($url, $redirectCode); } }  // 114-131
if (!$page || !$page->routable()) { $page = $grav->fallbackUrl($path); ... }   // 133-141
```

`Pages::dispatch()` (`Common/Page/Pages.php:1348-1422`):

```php
public function dispatch($route, $all = false, $redirect = true) {
    $page = $this->find($route, true);
    ...
    if ($all || isset($this->grav['admin'])) return $page;                 // admin/API context: no redirects
    if ($page) {
        $routable = $page->routable();
        if ($redirect) {
            if ($page->redirect()) { $this->grav->redirectLangSafe($page->redirect()); }   // header `redirect:`
            if (!$routable) { ... first visible routable child ... $this->grav->redirectLangSafe($child->route()); }
        }
        if ($routable) return $page;                                       // routable page wins; site.redirects NOT consulted
    }
    $route = urldecode((string)$route);
    $redirectedPage = $this->findSiteBasedRoute($route);                   // site.routes (aliases, no HTTP redirect)
    if ($redirectedPage) { $page = $this->dispatch($redirectedPage->route(), false, $redirect); }
    ...
    $source_url = $uri->uri(false);                                        // includes query string (docs: "escape ? in patterns")
    $site_redirects = $config->get('site.redirects');                      // line 1405
    if (is_array($site_redirects)) {
        foreach ((array)$site_redirects as $pattern => $replace) {
            $pattern = ltrim((string)$pattern, '^');
            $pattern = '#^' . str_replace('/', '\/', $pattern) . '#';
            try {
                $found = preg_replace($pattern, (string)$replace, $source_url);
                if ($found && $found !== $source_url) { $this->grav->redirectLangSafe($found); }   // code null -> [30x] suffix or default
            } catch (ErrorException $e) { log error }
        }
    }
    return $page;
}
```

`site.routes` matching (`Pages.php:1287-1323`, `findSiteBasedRoute`): exact key first, else regex patterns in **reverse** order; result is a page lookup (alias), not an HTTP redirect. Only used when the page is not found/routable (`Pages.php:1270-1276` also uses it inside `find()` when not admin).

Config keys (`system/config/system.yaml:116-118`, `site.yaml:19-26`):
```yaml
pages:
  redirect_default_code: 302     # 301|302|303|307
  redirect_trailing_slash: 1     # 0|1|301|302
  redirect_default_route: 0      # 0|1|301|302, also strips .htm/.html
# site.yaml
redirects:  # '/old/(.*)': '/new/$1'
routes:     # '/something/else': '/blog/sample-3'
```

Redirect code resolution (`Grav.php:621-690`, `getRedirectResponse`):
- A `[30x]` marker inside the target string sets the code: regex `'/.*(\[(30[1-7])\])(.\w+|\/.*?)?$/'` (lines 631-633), e.g. `'/new[301]'`.
- Otherwise `code` param; if outside 300-399 -> `system.pages.redirect_default_code` (default 302) (`Grav.php:672-677`).
- `.json` requests get a 200 JSON body `{code, redirect}` instead of a redirect (`Grav.php:679-681`). Relevant for API-style requests.
- `.md` requests keep the Markdown extension when `MarkdownOutput` is enabled (`Grav.php:645-665`).
- `redirect()` -> `close()` (`Grav.php:507-598`): `onShutdown` still fires after a redirect since a 2.x changelog fix (`CHANGELOG.md:254`).

Limits of core `site.redirects` that a plugin can improve: only regex, only applies when no routable page exists, no per-rule status code except the `[30x]` hack, no hit counts, no 410, no query-string awareness except by escaping `?` into the pattern, one YAML file with no UI.

---

### 2. Plugin base class facts (Grav 2.2.2)

`system/src/Grav/Common/Plugin.php`:
- `class Plugin implements EventSubscriberInterface, ArrayAccess` (line 31).
- Default `getSubscribedEvents()` (line 52) subscribes every public method starting with `on` at priority 0 (untyped return in the base; 2.x plugins declare `: array`, see `grav-plugin-api/api.php:36`).
- `public function __construct(public $name, Grav $grav, ?Config $config = null)` (line 73). Properties `$grav`, `$config`, `$active`, `$loader` are untyped `protected`.
- `config()` returns `$this->config["plugins.{$this->name}"] ?? []` (line 116).
- `enable(array $events)` (line 169) / `disable()` (line 203) add/remove listeners; priority can be overridden by `user/config/priorities.yaml` key `priorities.<plugin>.<event>.<method>` (`getPriority`, line 192; docs: learn.getgrav.org/2/advanced/plugin-prioritization).
- Event objects: `RocketTheme\Toolbox\Event\Event` implements `ArrayAccess`; you must read-modify-write arrays (no references), e.g. docs migration guide (learn.getgrav.org/2/migration/developer-upgrade-guide, "onBuildTwigSandboxPolicy" example: `$functions = $event['functions']; $functions[] = 'x'; $event['functions'] = $functions;`).
- Class-based events (`Grav\Events\PluginsLoadedEvent`, `PermissionsRegisterEvent`, `FlexRegisterEvent`, `BeforeSessionStartEvent`, `SessionStartEvent`, `PageEvent`, `TypesEvent`): subscribe with `SomeEvent::class => [...]` (`api.php:51, 55`).
- Autoload: plugin returns `require __DIR__ . '/vendor/autoload.php'` from `autoload()` (`api.php:238-241`), typically on `onPluginsInitialized` priority 100000 (`consent.php`: `['autoload', 100000]`).
- CLI: `onCliInitialize` is **not fired** in 2.2.2 (`grep -rn onCliInitialize system/src` finds nothing) although `grav-plugin-error` still subscribes to it. Do not rely on it.

Important 2.x rule (learn.getgrav.org/2/migration/developer-upgrade-guide, "Don't gate event subscriptions on isAdmin()"): never decide whether to subscribe based on `$this->isAdmin()` inside `onPluginsInitialized`. In API/Admin2 requests `$grav['admin']` is only registered later, at route dispatch (`ApiRouter.php`, `AdminProxy`), so the gated `enable()` never runs. Subscribe statically in `getSubscribedEvents()` and check inside the handler. The same rule is in `grav-skills/skills/grav-plugin-review/SKILL.md:49-53`.

---

### 3. Events relevant to a redirect manager (core)

Order copied from https://learn.getgrav.org/2/plugins/event-hooks and checked against code:

```
PluginsLoadedEvent (class)  -> onPluginsInitialized -> FlexRegisterEvent -> onThemeInitialized
-> onRequestHandlerInit -> onTask/onAction -> onBackupsInitialized -> onSchedulerInitialized (only when scheduler is used)
-> onAssetsInitialized -> onTwigTemplatePaths -> onTwigInitialized -> onTwigExtensions
-> onBuildPagesInitialized -> onPageProcessed (per page) -> onFolderProcessed -> onPagesInitialized
-> onPageInitialized -> onPageNotFound -> onPageTask/onPageAction -> onTwigSiteVariables -> onOutputGenerated -> onPageHeaders -> onOutputRendered -> onShutdown
```
Misc: `PermissionsRegisterEvent` (class), `onBeforeCacheClear`, `onAfterCacheClear`, `onPageFallBackUrl`, `onDisplayErrorPage.{code}`, `onBuildTwigSandboxPolicy`.

`onPagesInitialized` (payload `pages`, `route`, `request`; `PagesProcessor.php:49-55`) is the last hook before core's own redirect logic (site.redirects, page `redirect:` header, default-route redirect) runs in the `page` service. A plugin that must win over `site.redirects` for URLs that do match a page (for example "301 this live page elsewhere") has to act here or at `onRequestHandlerInit`; a plugin acting only at `onPageNotFound` cannot redirect a URL that still resolves to a routable page.

Shutdown: `onShutdown` fires after the response is closed, including after `redirect()` (`CHANGELOG.md:254`). Good place for slow work such as hit counting flush.

---

### 4. Blueprints and manifest (`blueprints.yaml`) in Grav 2

Real 2.x examples:

`grav-plugin-api/blueprints.yaml:1-21` (2.0-only plugin):
```yaml
name: API
slug: api
type: plugin
version: 1.0.42
description: ...
icon: plug
author:
  name: Team Grav
  email: devs@getgrav.org
  url: https://getgrav.org
homepage: https://github.com/getgrav/grav-plugin-api
keywords: api, rest, headless, json
bugs: https://github.com/getgrav/grav-plugin-api/issues
docs: https://learn.getgrav.org/api
license: MIT
compatibility:
  grav: ["2.0"]

dependencies:
  - { name: grav, version: ">=2.1.5" }
  - { name: login, version: ">=3.9.2" }
```
`grav-plugin-consent/blueprints.yaml` (2.0-only): `compatibility: {grav: ['2.0']}`, `dependencies: [{name: grav, version: '>=2.0.0'}, {name: php, version: '>=8.3'}]`.
`grav-plugin-admin2/blueprints.yaml`: `compatibility: {grav: ["2.0"]}`, `dependencies: [{ name: api, version: ">=1.0.40" }]`.
Dual-generation plugins: `compatibility: {grav: ['1.7', '2.0']}` (form, login, email, error, ...).

Rules, from https://learn.getgrav.org/2/plugins/plugin-compatibility and `system/src/Grav/Common/GPM/GPM.php`:
- `compatibility.grav` is a list of `major.minor` strings. Only the integer major is compared: `declaresGravCompatibility()` (`GPM.php:1044-1067`) does `(int)$generation === (int)GRAV_VERSION`; empty/absent means compatible; `gravGeneration()` (`GPM.php:1035-1038`) = `'2.0'` for 2.x.
- Without the key, compatibility is inferred from the `grav` dependency: `>=2.0` means 2.0 only, `<2.0`/no grav dependency means 1.7 only (docs).
- `dependencies` entries: `{ name, version }` with Composer-style constraints, or a bare slug string (`- form`, `grav-plugin-comments`). An entry may carry `grav: '2.0'` / `['1.7','2.0']` to scope it to a generation (`GPM.php:1371-1418`; docs "Requiring Different Versions Per Grav Generation"). The `grav` dependency must be listed first when using that syntax.
- A dependency named `admin` is remapped to `admin2` on Grav 2 (`GPM.php:1107-1120`).
- Grav Team's own review checklist flags `compatibility.grav: ['1.7','2.0']` on code that needs PHP 8 features; use `['2.0']` for a 2.0-only plugin (`grav-skills/skills/grav-plugin-review/SKILL.md:41-43`).

Optional API integration: if the redirect plugin must run with and without the API plugin, do NOT list `api` as a hard dependency. Subscribing to `onApiRegisterRoutes` etc. is harmless without it (the events simply never fire; review skill line 49-51 recommends unconditional subscription).

Custom `data-*@` providers used in blueprints must be allow-listed with `Blueprint::addAllowedDynamicCallable('Class::method')` in `onPluginsInitialized` (docs migration guide, "Custom data-*@ Providers Must Be Registered").

---

### 5. Permissions / ACL registration

There is no `onRegisterPermissions` *event name*. The mechanism is the class-based event `Grav\Events\PermissionsRegisterEvent`, dispatched the first time `$grav['permissions']` is accessed (`system/src/Grav/Common/Service/AccountsServiceProvider.php:44-60`; class doc at `system/src/Grav/Events/PermissionsRegisterEvent.php:15-23`; introduced in 1.7, `CHANGELOG.md:2453`).

Pattern used by the API and Admin 2 plugins (`grav-plugin-api/api.php:51, 739-743`; `grav-plugin-admin2/admin2.php:81-88`):

```php
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;

public static function getSubscribedEvents(): array {
    return [ PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000] ];
}
public function onRegisterPermissions(PermissionsRegisterEvent $event): void {
    $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
    $event->permissions->addActions($actions);
}
```

`permissions.yaml` (`grav-plugin-api/permissions.yaml`, `grav-plugin-consent/permissions.yaml`):
```yaml
actions:
  api.redirects:            # nested "actions" become api.redirects.read / api.redirects.write
    type: access
    label: PLUGIN_REDIRECTS.PERMISSION.GROUP
    actions:
      read:
        label: PLUGIN_REDIRECTS.PERMISSION.READ
      write:
        label: PLUGIN_REDIRECTS.PERMISSION.WRITE
```
`PermissionsReader::read()` flattens nested `actions` into dotted names (`system/src/Grav/Framework/Acl/PermissionsReader.php:61-70`).

Enforcement in API controllers: `requirePermission($request, 'api.redirects.write')` (see section 6.3). It resolves via `PermissionResolver::resolve()` which walks up the dot path (`api.redirects.write` -> `api.redirects` -> `api`) and returns the first explicit value (`grav-plugin-api/classes/Api/PermissionResolver.php:41-56`). `admin.super` bypasses (`AbstractApiController.php:100-101`).

Naming convention: name custom permissions under `api.<plugin>` (consent uses `api.consent.read|write`). `PermissionResolver::resolvedMap()` (used for the user profile / capabilities payload, `AbstractApiController.php:1019`) only lists permissions starting with `api.` (`PermissionResolver.php:111-113`), so a permission outside the `api.` namespace works for `requirePermission()` but will not show up in that map.

Review checklist: bare `api.access` on admin endpoints is flagged as coarse authorization; register a dedicated permission (`grav-skills/.../grav-plugin-review/SKILL.md:58-60`).

Note: `grav-plugin-consent` ships a `permissions.yaml` but I found no `PermissionsRegisterEvent` handler in `consent.php` (grep). Either it is a latent bug or registration happens elsewhere; I treated the API/admin2 pattern as the reference.

Sidebar `authorize` accepts a permission string or an array (any-of): `consent.php:487-505` uses `'authorize' => ['admin.super', 'api.consent.read']`; logic in `AbstractApiController::userPassesAuthorize()` (lines 271-300).

---

### 6. API plugin (grav-plugin-api) integration

#### 6.1 Route registration

The router fires `onApiRegisterRoutes` while building the FastRoute dispatcher: `classes/Api/ApiRouter.php:1033-1037`

```php
protected function registerPluginRoutes(RouteCollector $r): void {
    $event = new Event(['routes' => new ApiRouteCollector($r)]);
    $this->container->fireEvent('onApiRegisterRoutes', $event);
}
```

`ApiRouteCollector` (`classes/Api/ApiRouteCollector.php:24-78`): methods `get|post|patch|put|delete(string $route, array $handler)`, `addRoute(string|array $methods, ...)`, `group(string $prefix, callable)`. Handler = `[ControllerClass::class, 'method']`. Routes are relative to `/api/v1` (config `plugins.api.route` default `/api`, `version_prefix` default `v1`, `ApiRouter.php:190-193`). FastRoute placeholders: `/redirects/{id}`, `/x/{route:.+}`.

Real plugin example (`grav-plugin-email/email.php:32, 39-44`):
```php
'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
public function onApiRegisterRoutes(Event $event): void {
    $routes = $event['routes'];
    $routes->post('/email/send', [\Grav\Plugin\Email\EmailApiController::class, 'send']);
}
```

Docs: https://learn.getgrav.org/2/plugins/plugin-api-integration and `grav-plugin-api/README.md:913-942`.

**Gotcha: route cache.** The dispatcher is a FastRoute `cachedDispatcher` written to `cache://api/route.<fingerprint>.cache` (`ApiRouter.php:602-621`). `onApiRegisterRoutes` only fires when the table is (re)built. The fingerprint is a hash of the enabled plugin set plus each plugin's `blueprints.yaml` mtime (`ApiRouter.php:643-668`, doc comment lines 626-642). So: adding or changing routes in a plugin update takes effect only if `blueprints.yaml` changes (a version bump does that) or the cache is cleared. The cache is disabled when `system.debugger.enabled` is true (line 604).

#### 6.2 Middleware and auth chain (`ApiRouter::process`, lines 159-390)

Order: JSON body parse -> CORS -> method override (`POST` + `X-HTTP-Method-Override`) -> preflight -> `applyEnvironment` (`X-Grav-Environment`) -> public-route check -> `AuthMiddleware` -> `AdminProxy` registers `$grav['admin']` so core treats the request as admin scoped (line ~250) -> audit context -> demo gate -> rate limit -> dispatch.

Auth methods (`README.md:86-165`): API key (`X-API-Key`, or `?api_key=`), JWT (`X-API-Token`, or `Authorization: Bearer`), session cookie passthrough.

CSRF: no nonce. Header-borne credentials (key/JWT) are not forgeable cross-site. Session-cookie-only writes (POST/PUT/PATCH/DELETE) must pass `SameOriginGuard` (`classes/Api/Auth/SameOriginGuard.php:19-70`, used in `Middleware/AuthMiddleware.php:32-40, 78-95`): `Origin`/`Referer` host must match this host or `cors.origins`; with neither header the request needs a JSON content type or a custom header (`X-Requested-With`, `X-API-Token`, `X-API-Key`, `X-HTTP-Method-Override`). Refusal is a 403 `ForbiddenException`. A plugin controller gets this for free; a plugin does not add nonce handling.

Public routes: plugins can add unauthenticated routes through `onApiCollectPublicRoutes` (`ApiRouter.php:195-207`; payload `api_base`, `prefixes` (prefix match), `exact`; entries may be method-scoped `"GET /api/v1/foo"`; example `grav-plugin-sync/sync.php:226, 280`). Public routes still get optimistic auth (caller attached when credentials exist). Rate limiting applies to public routes too. Do not add auth-required routes here (review skill line 30-31).

Rate limiting: default 120 req / 60 s per user or IP, headers `X-RateLimit-*` (`README.md:786-810`).

#### 6.3 Controller base class and helpers

`Grav\Plugin\Api\Controllers\AbstractApiController` (`classes/Api/Controllers/AbstractApiController.php`). The router instantiates it as `new $controllerClass($this->container, $this->config)` (`ApiRouter.php:578-600`), constructor with promoted readonly props (lines 39-42):

```php
public function __construct(protected readonly Grav $grav, protected readonly Config $config) {}
protected function getUser(ServerRequestInterface $request): UserInterface       // line 47, attribute 'api_user'
protected function requirePermission(ServerRequestInterface $request, string $permission): void   // line 59
protected function requireSuper(ServerRequestInterface $request): void           // line 110
protected function hasPermission(UserInterface $user, string $permission): bool  // line 245
protected function getRequestBody(ServerRequestInterface $request): array        // line 495 (attr json_body / parsed body)
protected function getRouteParam(ServerRequestInterface $request, string $name): ?string  // line 668
protected function getPagination(ServerRequestInterface $request, ?int $defaultPerPage = null): array  // 714: page, per_page, offset, limit
protected function getSorting(ServerRequestInterface $request, array $allowedFields = []): array   // 734
protected function getFilters(ServerRequestInterface $request, array $allowedFilters = []): array   // 757
protected function validateEtag(ServerRequestInterface $request, string $currentHash): void        // 788 (If-Match -> 409)
protected function generateEtag(mixed $data): string                                               // 826
protected function respondWithEtag(mixed $data, int $status = 200, array $invalidates = [], ?string $etag = null, ?array $meta = null): ResponseInterface  // 874
protected function requireFields(array $body, array $fields): void              // 941 -> ValidationException (422)
protected function fireEvent(string $name, array $data = []): Event            // 962
protected function getApiBaseUrl(): string                                      // 931
```

`requirePermission` order (lines 59-108): API-key scope cap -> demo write-lock -> super admin passes -> requires `api.access` -> requires the named permission (walk-up resolution). Params from routes are already `rawurldecode`d (`ApiRouter.php:586-593`).

Responses: `Grav\Plugin\Api\Response\ApiResponse` static helpers (`classes/Api/Response/ApiResponse.php`): `create($data, $status=200, $headers=[], $meta=null)` (line 66), `paginated(...)` (104), `ok` (163), `created($data, $location, ...)` (171), `noContent(...)` (179), `parts(...)` (86). Errors: `ErrorResponse::create(int $status, string $title, string $detail, array $headers=[], ?array $toast=null)` (`ErrorResponse.php:56`) and typed exceptions thrown from controllers become RFC 7807 bodies: `NotFoundException` 404, `ValidationException` 422 (with `errors` array), `ForbiddenException` 403, `ConflictException` 409, `UnauthorizedException`, `TooManyRequestsException`, base `ApiException(int $statusCode, string $errorTitle, string $detail='', array $headers=[], ...)` (`classes/Api/Exceptions/ApiException.php:11-19`; doc https://learn.getgrav.org/2/plugins/plugin-api-integration "Exception Handling").

Envelope: `{data, meta?, links?}` on success; errors `{status, title, detail, errors?}` (`README.md:725-775`).

Example controller from the docs:
```php
namespace Grav\Plugin\Redirects;   // your namespace
class RedirectsApiController extends AbstractApiController {
    public function index(ServerRequestInterface $request): ResponseInterface {
        $this->requirePermission($request, 'api.redirects.read');
        $p = $this->getPagination($request);
        return ApiResponse::paginated($rows, $total, $p['page'], $p['per_page'], $this->getApiBaseUrl() . '/redirects');
    }
}
```
Plugin must autoload the controller (`composer.json` PSR-4 `classes/`, `autoload()` method).

#### 6.4 Events fired when pages change via the API (exact names and payloads)

Source: `classes/Api/Controllers/PagesController.php`; documented in `grav-plugin-api/README.md:1219-1233` and https://learn.getgrav.org/2/api/events (page events). Payload arrays are wrapped in `RocketTheme\Toolbox\Event\Event` (`AbstractApiController::fireEvent`, line 962). Listeners read `$event['page']`.

| Event | Fired at (PagesController.php) | Payload |
|---|---|---|
| `onApiBeforePageUpdate` | 856; batch 2890 | `page` (object BEFORE changes), `data` (request body, by reference), batch adds `method:'batch'` |
| `onApiPageUpdated` | 960; batch 2898 | `page` (saved object); `previous_template` only when template changed; batch adds `route`, `method:'batch'`. **No old route.** |
| `onApiBeforePageDelete` | 999 (per-language), 1020, batch 2910 | `page`; `lang` (single translation); batch `method:'batch'` |
| `onApiPageDeleted` | 1005 (per-language), 1028, batch 2915 | `route` ('/' . route as sent in URL), optional `lang`, batch `method:'batch'`. **No page object.** |
| `onApiPageMoved` | 1127-1131 | `page` (page at new location, may be null), `old_route`, `new_route` |
| `onApiPageMoved` (bulk) | 2190-2196 | same + `method:'reorganize'` (one per op that really renamed something) |
| `onApiBeforePagesReorganize` / `onApiPagesReorganized` | 2112 / 2176 | `operations` (resolved list) |
| `onApiBeforePagesReorder` / `onApiPagesReordered` | 1739 / 1802 | `parent`, `order` (slug list; folder number prefixes change, URLs do not) |
| `onApiBeforePageCreate` / `onApiPageCreated` | 637 / 687, 1208 (copy), 1914 | `route`, `header`, `content`, `template`, `lang`; created: `page`, `route`, `lang`; copy adds `source_route`, `method` |
| `onApiPageTranslated`, `onApiPageLanguageAdopted`, `onApiPageSynced` | 1344, 1451, 1616 | language variants |

Move is `POST /pages/{route}/move` with body `{parent, slug?, order?}` (`PagesController.php:1040-1131`); the move renames the page folder (`Folder::move`, line 1112) and re-inits pages before firing.
`$newRoute` is computed as `parent/newSlug` (line 1123). Descendants are not reported individually: only the moved root's `old_route`/`new_route`. A plugin must derive descendant redirects by prefix (`/old/*` -> `/new/*`) or enumerate `$page->children()`.

Admin-compat events also fired by API controllers via `fireAdminEvent()` (`AbstractApiController.php:1148-1170`), which also sets `$grav['page']` to the page: `onAdminSave` (before write; `object`, `page` by ref), `onAdminAfterSave` (`object`, `page`), `onAdminAfterDelete` (`object`, `page`), `onAdminAfterSaveAs` (`path` = new folder path, after move, line 1120), `onAdminCreatePageFrontmatter`. These let 1.x-era plugins keep working; prefer the `onApi*` events in new code.

Patterns a redirect manager needs (derived from the payloads above, not from a doc):
- **Slug/route change through update:** `PATCH /pages/{route}` merges `header` into the page (`PagesController.php:867-891`); a changed `header.slug` changes the public URL but `onApiPageUpdated` carries only the new page. Capture `$page->route()` (and `rawRoute()`) in `onApiBeforePageUpdate` (page still in old state) and compare in `onApiPageUpdated`.
- **Delete:** `onApiPageDeleted` has only a route string; capture `$page->route()` plus descendants' routes in `onApiBeforePageDelete` if you want 410/redirect suggestions for the whole subtree. Note that deleting removes the folder (`Folder::delete`, line 1024), so the page is gone by the "after" event.
- **Move:** fully covered by `old_route`/`new_route` for the moved root. `route` in the event is the *public route as addressed by the API* (`resolvePageByRoute` also accepts the structural `rawRoute`, `AbstractApiController.php:685-711`); for sites with `system.home.hide_in_urls` the public route and `rawRoute` differ. Normalise through `$pages->find()`.
- Changes made outside the API (filesystem/FTP, Flex Objects pages, other plugins) fire none of these. Pages are `type: regular` by default (`system/config/system.yaml:41`, "flex" is EXPERIMENTAL); the API `PagesController` uses the regular `$grav['pages']`.
- `onApiWebhook*`: the webhook dispatcher subscribes to `onApiPageCreated|Updated|Deleted|Moved|Translated|PagesReordered` + media/user/config/package events (`classes/Api/Webhooks/WebhookDispatcher.php:32-46`).

Other useful API events: `onApiConfigUpdated` (`scope`, `data`), `onBeforeCacheClear`.

#### 6.5 Admin 2 UI integration events (for a redirect-manager admin page)

From `grav-plugin-api/README.md:944-1052` and `grav-skills/skills/grav-plugin-review/SKILL.md:25-35`:
- `onApiSidebarItems`: `$event['items']` append `{id, plugin, label, icon, route: '/plugin/<slug>', priority, badge, authorize}` (consent example, `consent.php:487-505`).
- `onApiPluginPageInfo`: set `$event['definition']` with `page_type: 'blueprint'` (form driven by a blueprint + `data_endpoint`/`save_endpoint`/`actions`) or `'component'` (custom web component `admin-next/pages/{slug}.js`, tag `grav-{slug}--page`, reads `window.__GRAV_PAGE_TAG`). Files are served by `GET /gpm/plugins/{slug}/page-script` (`GpmController.php:1613-1760` for the file conventions: `admin-next/pages/{slug}.yaml`, `admin-next/pages/{slug}.js`, `admin-next/fields/{type}.js`, `admin-next/widgets/{slug}.js`).
- Also existing per the review skill: `onApiAdminSettingsPanels`, `onApiFloatingWidgets`, `onApiMenubarItems`, `onApiContextPanels`, `onApiGenerateReports`, `onApiBlueprintResolved`, `onApiDashboardWidgets`.
- Warning from the same skill: the generic `/config/plugins/<slug>` endpoint returns raw plugin config to the browser; do not keep secrets there.
- Admin 2 components send `X-API-Token`; use `window.__GRAV_DIALOGS.confirm()`, never native dialogs.

#### 6.6 OpenAPI

`openapi.yaml` in the API plugin covers **core routes only** and is enforced by `tests/Unit/OpenApiCoverageTest.php` ("Plugin routes (onApiRegisterRoutes) belong to their own plugins and are not checked here", lines 13-17). There is no event to inject paths into it. Plugins document their endpoints in an `api-docs/` folder with `api-endpoint` template pages and a Postman collection (docs: https://learn.getgrav.org/2/plugins/plugin-api-integration "API Documentation"; real example `grav-plugin-license-manager/api-docs/`). Machine-readable exposure to AI clients goes through `mcp.yaml` (section 7).

---

### 7. MCP

- The official server is `getgrav/grav-mcp` (npm `grav-mcp`), a standalone Node.js app, **not** a Grav plugin. It calls the API plugin over HTTP with an API key (`grav-mcp/README.md:1-60`; docs https://learn.getgrav.org/2/advanced/mcp-server).
- **Plugins can add MCP tools without writing MCP code**, via a manifest `mcp.yaml` in the plugin root next to `blueprints.yaml`/`permissions.yaml`, or in PHP via the `onApiMcpTools` event. Contract: `grav-mcp/docs/plugin-tools-spec.md`; user docs `grav-plugin-api/README.md:1054-1213`; implementation `grav-plugin-api/classes/Api/Mcp/McpManifestLoader.php` (reads `plugins://{slug}/mcp.yaml`, line 150-158) and `McpToolCollector.php`; endpoint `GET /mcp/tools` (permission `api.access`; `grav-mcp/src/tools/plugin-tools.ts:96-97`).
- Manifest fields: `version` (1 or 2), optional `prefix` (default slug with `-` -> `_`; tool name `{prefix}_{name}`, regex `^[a-z][a-z0-9_]*$`, max 64 chars), `tools[]: name, title, description, method, path, permission, annotations{readOnly,destructive,idempotent}, input (JSON Schema subset), query, body (v2)`. Path placeholders must be plain `{id}` (no FastRoute regex like `{id:\d+}`).
- Minimal example for this project:
```yaml
version: 1
prefix: redirects
tools:
  - name: list_rules
    description: List redirect rules with paging and search.
    method: GET
    path: /redirects
    permission: api.redirects.read
    input:
      type: object
      properties:
        q: { type: string }
        page: { type: integer, minimum: 1, default: 1 }
  - name: create_rule
    description: Create a redirect rule.
    method: POST
    path: /redirects
    permission: api.redirects.write
    input:
      type: object
      required: [source, target]
      properties:
        source: { type: string }
        target: { type: string }
        code:   { type: integer, enum: [301, 302, 307, 308, 410] }
```
- Schema subset allowed: `type, description, default, enum, nullable, minimum, maximum, minLength, maxLength, pattern, format(date|date-time|email|uri), items, properties/required/additionalProperties`. Disallowed: `$ref, oneOf, anyOf, allOf, not, if/then, tuple items, patternProperties, const, dependencies`. Multipart routes are out of scope. Bad entries are dropped with a warning in `GET /mcp/tools` (`warnings[]`), the file never breaks the endpoint.
- Runtime tools: `$event['tools']->add('slug', [...])` in `onApiMcpTools` (README lines 1151-1177).
- Visibility: only enabled plugins are read; a tool whose `permission` the caller lacks is omitted; super admins see all (README line 1207-1213). `fingerprint` (also the `ETag`) hashes the enabled-plugin set plus manifest mtimes.
- grav-mcp registers plugin tools **at startup** (`grav-mcp/src/index.ts`: `loadPluginTools` with a 5 s timeout, `PLUGIN_TOOLS_TIMEOUT_MS = 5000`); env `GRAV_MCP_PLUGIN_TOOLS=all|none|slug,slug` (`grav-mcp/README.md` config table). A new or changed manifest needs the MCP server (re)started; `plugin-tools.ts:41-42, 78` also exposes a reconcile function, so a live refresh path exists in the code, but I did not trace when it is triggered.
- The permission on the tool is enforced twice: by grav-mcp (`client.checkPermission(def.permission)`, `plugin-tools.ts:227`) and by the route's controller.
- The built-in tool `plugin_action`/`discover_plugins` also exist (docs mcp-server "Plugin Discovery"), unrelated to `mcp.yaml`.

---

### 8. Scheduler

- Event: `onSchedulerInitialized` with payload `['scheduler' => Scheduler]` (`system/src/Grav/Common/Scheduler/Scheduler.php:189-210`). In Grav 2 it is **lazy**: the web-request `SchedulerProcessor` no longer builds the scheduler (`Processors/SchedulerProcessor.php:34-38`); jobs initialise on first real use (CLI `bin/grav scheduler`, webhook, health check, job listing). So you cannot rely on it firing on normal page requests, and any listener must not do work outside adding jobs.
- Registration pattern (docs https://learn.getgrav.org/2/advanced/scheduler "Plugin-provided Jobs", identical shape in core `Common/Cache.php:829-855` and `grav-plugin-api/classes/Api/Demo/DemoManager.php:349-364`):
```php
public static function getSubscribedEvents(): array {
    return ['onSchedulerInitialized' => ['onSchedulerInitialized', 0]];
}
public function onSchedulerInitialized(Event $e): void {
    $scheduler = $e['scheduler'];
    $job = $scheduler->addFunction('Grav\Plugin\RedirectsPlugin::purgeJob', [], 'redirects-purge');
    $job->at('0 3 * * *');
    $job->output('logs/redirects-purge.out');
    $job->backlink('/plugins/redirects');
}
public static function purgeJob(): void {   // static, string callable "Class::method": runs in a fresh process
    $grav = \Grav\Common\Grav::instance();
}
```
- `Scheduler::addFunction(callable $fn, $args = [], $id = null)` (line 283), `addCommand($command, $args = [], $id = null)` (line 299). Job id is hyphenized (`Job.php:123-136`). Scheduling helpers: `at('cron expr')`, `everyMinute()`, `hourly($minute)`, `daily($hour,$minute)` (`Scheduler/IntervalTrait.php:27-69`), `output($file)`, `backlink($link)`, `onlyOne()`, `maxAttempts()`, `retryDelay()`, `timeout()`, `withTags()` (`Job.php`).
- Enable/disable state lives in `user/config/scheduler.yaml` `status: {job-id: enabled|disabled}` (`Job.php:139-141`; docs). A plugin can also ship a command job that calls `bin/plugin redirects <cmd>` (the 2.2.2 changelog: scheduler commands like a plugin's `bin/plugin` worker now inherit the scheduler's environment, `CHANGELOG.md:9`).
- `SchedulerController` in the API plugin lists/runs jobs (`classes/Api/Controllers/SchedulerController.php`); permissions `api.scheduler.read|write`.
- Environment caveat (docs): cron `bin/grav scheduler` resolves environment `cli` unless pinned with `--env=<host>` or `GRAV_ENVIRONMENT`.
- Jobs given as closures run in the scheduler process; use `Class::method` strings when background/fresh-process execution matters (comment in `DemoManager.php:366-369`).

---

### 9. Plugin CLI (`bin/plugin <slug> <command>`)

- Loader: `system/src/Grav/Console/Application/CommandLoader/PluginCommandLoader.php:34-71`. It scans `plugins://<slug>/cli/` for files matching `/([A-Z]\w+Command\.php)$/` (line 40-42), `require_once`s them, and instantiates class `Grav\Plugin\Console\<FileName>` (line 55). Commands are keyed by `getName()` plus aliases. The plugin must be **enabled** (`PluginApplication.php:106-114`).
- Entry: `bin/plugin` -> `Grav\Console\Application\PluginApplication` (`bin/plugin:42`, argv[1] = slug, `PluginApplication.php:68-81`); `bin/plugin <slug>` with no command defaults to `list`.
- Base class: `Grav\Console\ConsoleCommand extends Symfony\Component\Console\Command\Command` (`system/src/Grav/Console/ConsoleCommand.php:20-48`). `execute()` is final-ish glue (calls `setupConsole()` then `serve()`); you implement **`protected function serve()`** (return int; 0 = success, default returns 1). Symfony 7: `configure(): void` with return type, `serve(): int`.
- Symfony console is `^7.0` (`composer.json:36`). `ConsoleTrait::addOption()` has the 7.x signature with `Closure|array $suggestedValues` and silently ignores `env`/`lang` (`ConsoleTrait.php:91-97`). `$this->input` and `$this->output` (SymfonyStyle) are set in `setupConsole()`.
- Helpers in `ConsoleTrait`: `initializePlugins()` (calls `$grav['plugins']->init()` and fires `onPluginsInitialized`, lines 173-190), `initializeThemes()`, `initializePages()`, `clearCache()`, `getIO()`. Real 2.x commands call `$this->initializePlugins()` and `include __DIR__ . '/../vendor/autoload.php';` inside `serve()` because the loader does not run the plugin autoloader (`grav-plugin-api/cli/KeysGenerateCommand.php:29-36`, `grav-plugin-problems/cli/CheckCommand.php:39-46`).
- Skeleton (matches `grav-plugin-api/cli/DemoBaselineCommand.php:16-54`):
```php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

class ImportCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('import')->setAliases(['redirects:import'])
             ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'CSV file')
             ->setDescription('Import redirects')->setHelp('...');
    }
    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();
        $grav = Grav::instance();
        return 0;
    }
}
```
- Reserved names: `help`, `list`, `-h -q -v -n --ansi --no-ansi` (docs https://learn.getgrav.org/2/cli-console/grav-cli-plugin).
- `Grav::redirect()/close()` throw in CLI instead of exiting (`Grav.php:508-520`), so plugin init code that redirects must guard `isCli()`.

---

### 10. PHP / Symfony / Twig facts relevant to plugin code (Grav 2.2.2)

- `composer.json:14-16`: `"php": "^8.3"`; docs say 8.3+ with full support for 8.4/8.5 (developer upgrade guide).
- Symfony 7 components (`symfony/cache|yaml|console|event-dispatcher|var-exporter|process|http-client ^7.0`), Twig `3.x-dev` fork (`getgrav/twig`), Monolog `^3.0`, `rockettheme/toolbox ^2.0`, `nyholm/psr7`, PSR-15 middleware, `getgrav/cache ^2.0`, Symfony Cache replaces Doctrine Cache (`composer.json:29-42`).
- Monolog 3: `addInfo()/addError()` removed; use `$this->grav['log']->info()/error()` (`docs/migration__developer-upgrade-guide.txt`). If a plugin ships its own `vendor/`, do not pin `psr/log` 1.x; add `"replace": {"psr/log": "*"}`.
- PHP 8.2+ deprecates undeclared dynamic properties; plugin classes should declare properties. Nullable typing `?Type` instead of `Type $x = null`.
- Removed: classic admin and all its `onAdmin*` UI events, Doctrine Cache drivers (APC, WinCache, XCache, Memcache), `system.umask_fix`, Twig `undefined_functions/undefined_filters` auto-allow. Twig content sandbox (`onBuildTwigSandboxPolicy`) restricts what authors can call inside page content.
- Grav's own site-wide `Utils::isAdminPlugin()` (backing `Plugin::isAdmin()`) is only true after `AdminProxy` registers, which happens at API dispatch time (section 2).
- Logging: `$this->grav['log']` is Monolog 3; log lines go to `logs/grav.log`.
- Env: native `.env` support in core (developer upgrade guide "Highlights").

---

### 11. Flex Objects (storage option for the rule set)

- Pages are `type: regular` by default; "flex" pages are marked EXPERIMENTAL in `system/config/system.yaml:41`.
- Custom Flex directories: blueprint in `plugins/<slug>/blueprints/flex-objects/<type>.yaml` (docs https://learn.getgrav.org/2/plugins/plugin-flex, "IMPORTANT ... must be in blueprints/flex-objects/"). Storage types: `simple` (one file), `file`, `folder` (docs generator prompt). The Flex Objects plugin provides the REST endpoints `GET|POST|PATCH|DELETE /flex-objects/{type}/{key}` through the API plugin (docs https://learn.getgrav.org/2/api/endpoints/flex-objects; the MCP manifest `body` key was designed for such routes, README line 1122-1140).
- Flex events are documented at https://learn.getgrav.org/2/advanced/flex/php-and-events (saved as `docs/advanced__flex__php-and-events.txt`, not analysed in detail here).
- Trade-off for this project (my judgement, not from the docs): a redirect table needs a fast lookup on every request. Loading a Flex directory or a large YAML on each request costs more than an indexed store; the API plugin's audit and popularity features use SQLite (`AuditStore::available()`, `api.php:641`), which shows a Team Grav precedent for `pdo_sqlite` storage guarded by an availability check.

---

### 12. Doc pages consulted (saved as text in `docs/`)

All at `https://learn.getgrav.org/2/...`:
`plugins/event-hooks`, `plugins/grav-lifecycle`, `plugins/plugin-api-integration`, `plugins/plugin-compatibility`, `plugins/plugin-basics`, `plugins/plugin-flex`, `plugins/plugin-tutorial`, `advanced/scheduler`, `advanced/mcp-server`, `advanced/plugin-prioritization`, `advanced/groups-and-permissions`, `advanced/flex/php-and-events`, `cli-console/grav-cli-plugin`, `migration/developer-upgrade-guide`, `api/developer-guide`, `api/events`, `api/endpoints/mcp`, `api/endpoints/flex-objects`, `api/endpoints/admin-integration`, `api/authentication`, `api/getting-started`, `content/routing`, `cookbook/plugin-recipes`, `admin-panel`.
`docs/fetch.py` is the converter used. Pages listed in the site navigation but not read: `security/*`, `advanced/php-reference`, `cookbook/admin-recipes`.

Docs vs code discrepancies found:
- `plugins/grav-lifecycle` still says "Check PHP version to ensure we're running at least version 7.1.3" and lists `onPageNotFound` under `Twig.php`; the code requires PHP 8.3 (`composer.json:15`) and only fires it in `PagesProcessor.php:79`.
- The same page places the trailing-slash redirect under "Uri ... Handle redirect" before "Run Plugins Processor"; this matches the code (`InitializeProcessor.php:117-125`).

---

### 13. Open questions / not found

1. **Behaviour of `$grav->close()` / `getRedirectResponse()` during `PluginsLoadedEvent`.** `getRedirectResponse()` reads `$this['uri']->rootUrl()` (`Grav.php:621+`) and `close()` reads `$this['request']`, `$this['session']`, `$this['debugger']`, `$this['messages']`. `Uri::init()` has not run at that point (`InitializeProcessor.php:114`), so a redirect at `PluginsLoadedEvent` would need a hand-built `Nyholm\Psr7\Response`. Not tested. This is the only window before the core trailing-slash redirect and before the session starts (the API plugin uses it to switch the session off, `api.php:85-94`).
2. **Does `$event->getRoute()->getRoute()` in `onRequestHandlerInit` include or exclude the language prefix and the base path?** The API plugin compares it against `/api...` without stripping the base (`api.php:596-608`) but strips the base elsewhere (`api.php:257-270`). Route built from `Uri::getCurrentRoute()` (`RequestProcessor.php:57`). Needs a runtime check on a multilingual site and a subfolder install. Docs say `site.redirects` rules apply to the slug path after the language part (https://learn.getgrav.org/2/content/routing).
3. **`Uri::uri(false)` content** (used as the redirect source string in `Pages::dispatch`, line ~1400): the docs' escape tip implies the query string is included; I did not run it. Check with a request like `/index.php?id=3`.
4. **No documented hook to invalidate or extend `site.redirects`/`site.routes` at runtime.** A plugin can inject entries by `$config->set('site.redirects', ...)` before `PagesProcessor` (for example in `onPluginsInitialized`), and core would apply them. I did not test the precedence, and it inherits the "only when no routable page" limitation (`Pages.php:1361-1388`).
5. **onApiPageUpdated old route:** confirmed absent; the capture-in-Before approach is my recommendation, unverified in a running site. Same for descendants on delete and for `header.slug` edits made through admin blueprint forms (blueprint field names are `header.*`, `PagesController.php:3374`).
6. **Filesystem/Flex page changes outside the API** fire no `onApi*` events. I did not find a core "page saved" event for the regular pages type other than the `onAdmin*` events fired by the API controllers. Flex objects fire `onFlexObjectBeforeSave|AfterSave|BeforeDelete|AfterDelete` (docs advanced/flex/php-and-events) but pages default to regular.
7. **`grav-plugin-consent` ships `permissions.yaml` but I found no registration handler.** Either an oversight or registration by another mechanism; not resolved.
8. **Admin 2 UI conventions in depth.** I have not read `grav-skills/skills/grav-api-admin-next-integration/SKILL.md` (1653 lines) or `grav-admin-next/`; only the summary in the plugin-review skill and the README. Read it before building the admin page.
9. **grav-mcp live refresh.** `plugin-tools.ts` has a reconcile function (lines 41-42, 78-131) that compares definition fingerprints, but I did not trace what triggers it; assume a restart of the MCP server after changing `mcp.yaml`.
10. **Rate limiting and MCP:** a bulk import through MCP tools counts against the 120/60 s default limit (`README.md:786-810`); no bulk endpoint exists for third-party plugins other than what the plugin builds itself.
11. **`develop` vs `master`:** I read `master` (tag 2.2.2). `develop` is the default branch and was at 3d3cbbd when listed; it also reports 2.2.2, differences not diffed.
12. **PHP deprecation list for plugins is not exhaustive.** Only the items called out in the developer upgrade guide and `composer.json` were checked; no full PHPStan/Rector run.
13. **Grav GPM submission requirements** (`plugins/gpm-submission` doc) were not read.

## Appendix B: Admin 2 integration

Date: 2026-09-29. Line references correspond to the cloned commits below.

### Cloned repos (shallow, depth 1)

| Folder | Source | Commit | Version |
|---|---|---|---|
| `getgrav/grav-admin-next@230ed21:` | github.com/getgrav/grav-admin-next (SvelteKit SPA source) | 230ed21d01350c7811ec1cebaefafee2fbab3b12 (2026-09-28) | - |
| `getgrav/grav-plugin-admin2@eb09166:` | getgrav/grav-plugin-admin2 (PHP wrapper + built `app/`) | eb0916677ecf188bb32b239b444c442defad2548 (2026-09-28) | 2.1.25 |
| `getgrav/grav-plugin-api@e220660:` | getgrav/grav-plugin-api | e220660c235d8cee70f3cfbe44f3aeb8265537f3 (2026-09-28) | 1.0.42 |
| `getgrav/grav-plugin-consent@a619530:` | getgrav/grav-plugin-consent | a619530e0b5431069f895c84c0a2252e4b0ca8a3 (2026-09-16) | 1.0.2 |
| `getgrav/grav-plugin-license-manager@1fa21d1:` | getgrav/grav-plugin-license-manager | 1fa21d189bc8bedc09d78d623a647d52b3176499 (2026-09-11) | 2.0.5 |
| `getgrav/grav-plugin-data-manager@6c3735c:` | getgrav/grav-plugin-data-manager | 6c3735cb37ba7d913c20c23e1137d80e9d7307be (2026-09-18) | - |
| `getgrav/grav-plugin-problems@a1c53eb:` | getgrav/grav-plugin-problems (Report component) | a1c53eb476bcb239c2767174f395dd8c5c98b0f0 | - |
| `getgrav/grav-plugin-sync@95b28d2:` | getgrav/grav-plugin-sync | 95b28d2971d2bb4eaeeafc4688f7cb762688b325 | - |
| `getgrav/grav-skills@eab567e:` | getgrav/grav-skills (official Claude skills, including `grav-api-admin-next-integration`, `grav-admin-ui-polish`) | eab567eefd77c5af9c253c8f273f4cb55c0a30bf (2026-09-26) | - |
| `trilbymedia/grav-plugin-views@fa07d49:` | github.com/trilbymedia/grav-plugin-views (Trilby Media = Grav core team) | fa07d498f71338a85bdd11172cf8edb1843af4a9 (2026-09-09) | 1.3.4 |
| `trilbymedia/grav-plugin-git-sync@4714066:` | trilbymedia/grav-plugin-git-sync | 471406676b9b6957001f55e301c6041bf741f417 (2026-09-18) | - |
| `trilbymedia/grav-plugin-tntsearch@c363cb4:` | trilbymedia/grav-plugin-tntsearch | c363cb49d65db7a52b084a0e52b89fb8972f0416 (2026-09-22) | - |
| `trilbymedia/grav-plugin-flex-objects@31654b5:` | trilbymedia/grav-plugin-flex-objects | 31654b5e1a013f8693fdb64f88c01f63733834e0 (2026-09-22) | - |

Not found: `grav-plugin-page-insights`, `grav-plugin-seo-magic`, `grav-plugin-git-sync` under `getgrav/` (404). git-sync is located under `trilbymedia/`. page-insights, seo-magic, editor-pro, ai-pro are apparently premium/private and not publicly cloneable. `gh` is not logged in; all operations used `git clone https://...` and unauthenticated GitHub API.

The source of truth for the Extension API is additionally `getgrav/grav-skills@eab567e:/skills/grav-api-admin-next-integration/SKILL.md` (1653 lines, by Grav core team). I have verified every claim made there against the code (admin-next + api).

---

### Architecture in one paragraph

Admin2 is a static SvelteKit 5 SPA (`grav-admin-next/README.md:9-13`), built with adapter-static and Tailwind 4, delivered by `grav-plugin-admin2` from `app/` (`grav-plugin-admin2/admin2.php:12-31`). It speaks exclusively with the API plugin under `/api/v1` (`README.md:7`). A plugin extends Admin2 not through Svelte components in the SPA build, but through standalone Custom Elements (Web Components) as simple `.js` files under `admin-next/` in the plugin. The API plugin recognizes them by file convention, delivers them authenticated, the SPA loads them at runtime via `import()` of a Blob URL and mounts the tag. PHP side: `onApi*` events of the API plugin.

---

### 1. Own page: sidebar entry + route + bundle

#### Mechanism (binding)

Three components, all in the plugin, no `admin2:` key in `blueprints.yaml`, no manifest:

1. **Sidebar entry**: event `onApiSidebarItems`. Item format documented in `getgrav/grav-plugin-api@e220660:/classes/Api/Controllers/SidebarController.php:17-39`, event fired at line 65.
2. **Page definition**: event `onApiPluginPageInfo` (guard: `$event['plugin'] === '<slug>'`), fired in `GpmController.php:1642` (`resolvePluginPageDefinition`). Alternative without event via file convention (`discoverPluginPage`, `GpmController.php:1877`): `admin-next/pages/<slug>.yaml` (definition) or just `admin-next/pages/<slug>.js` (then component mode with title derived from slug, lines 1898-1906).
3. **Bundle**: `admin-next/pages/<slug>.js` in the plugin folder. Route `GET /gpm/plugins/{slug}/page-script` (`ApiRouter.php:862`) delivers the file (`GpmController::customPageScript`, line 1746). Permission `api.access`, plugin must be enabled (`requireEnabledPlugin`).

The SPA route is always `/plugin/<slug>` (`getgrav/grav-admin-next@230ed21:/src/routes/plugin/[slug]/+page.svelte`, `slug = page.params.slug`, line 40). One page per plugin. Multiple subpages are handled by the plugin itself via hash router within the Web Component (`settings_route: '#/settings'`, see `docs/blueprint-form-element.md`). Whether `route` in the sidebar item may contain a hash (`/plugin/x#/log`) is not explicitly documented; the link is simply rendered as `href="{base}{item.route}"` (`AppShell.svelte:314`). Inference, not tested.

#### PHP registration (actual code, consent.php:35-50, 487-524)

```php
public static function getSubscribedEvents(): array
{
    return [
        'onPluginsInitialized' => [['autoload', 100000], ['onPluginsInitialized', 0]],
        // Static, NEVER behind isAdmin(): on the admin-next path,
        // $grav['admin'] does not exist yet during onPluginsInitialized.
        'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
        'onApiSidebarItems'   => ['onApiSidebarItems', 0],
        'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
    ];
}

public function onApiSidebarItems(Event $event): void
{
    $items = $event['items'] ?? [];
    $items[] = [
        'id' => 'consent', 'plugin' => 'consent',
        'label' => 'PLUGIN_CONSENT.TITLE',          // i18n key, API translates (SidebarController.php:73-75)
        'icon' => 'fa-cookie-bite',                  // Font Awesome
        'route' => '/plugin/consent',
        'priority' => 3,                             // higher = further up
        'authorize' => ['admin.super', 'api.consent.read'],  // string or any-of array
    ];
    $event['items'] = $items;
}

public function onApiPluginPageInfo(Event $event): void
{
    if ($event['plugin'] !== 'consent') return;
    $event['definition'] = [
        'id' => 'consent', 'plugin' => 'consent',
        'title' => 'Plain text, no i18n key',   // Title rendered verbatim (consent.php:509-511)
        'icon' => 'fa-cookie-bite',
        'page_type' => 'component',
    ];
}
```

Additional sidebar item fields: `badge` (static), `badgeEndpoint` (API path returns `{count:N}`, live), push via `window.dispatchEvent(new CustomEvent('grav:sidebar:badge', {detail:{id,count}}))` (`SidebarController.php:26-33`, `AppShell.svelte:170`). `authorize` is evaluated server-side and removed (`SidebarController.php:68-72`).

#### Definition: fields (PluginPageDefinition)

`id, plugin, title, icon, page_type ('component'|'blueprint'), blueprint, data_endpoint, save_endpoint, actions[], settings_route, settings_page`. The API controller adds `has_custom_component` (`GpmController.php:1664`).

`actions[]` entries (toolbar top right, `+page.svelte:409-520`): `id, label, icon, primary, endpoint, method, confirm, download, upload, navigate, children[]`.
- Action with `endpoint`: SPA calls it and shows toast (`doExecuteAction`, line 248-322).
- Component mode, action without `endpoint`: SPA fires on the element a `CustomEvent('page-action', {detail:{id,label}})` (line 238-246).
- Blueprint mode, action without `endpoint`: `window` `CustomEvent('grav:plugin-page-action', {detail:{plugin, action}})` (line 222-225). git-sync uses that for its wizard.
- `primary` action in component mode stays **disabled until the component sends `page-state {dirty:true}`** (line 482-485).

#### Bundle format (binding)

- Pure JS file, loaded as **ES module via Blob URL** (`PluginPageComponent.svelte:58-63`):
  ```js
  const blob = new Blob([`window.__GRAV_PAGE_TAG = ${JSON.stringify(tagName)};\n${code}`], {type:'application/javascript'});
  await import(/* @vite-ignore */ URL.createObjectURL(blob));
  await customElements.whenDefined(tagName);
  ```
- Tag name is fixed: `grav-<slug>--page` (line 25). The file must do `const TAG = window.__GRAV_PAGE_TAG; customElements.define(TAG, Klasse)`. Never hardcode a tag.
- The file must be **self-contained**. Relative imports do not work (Blob has no base URL). This is an inference from the loading mechanism; in Grav core team plugins it is practice: all bundles are handwritten vanilla-JS single files without imports (`trilbymedia/grav-plugin-git-sync@4714066:/admin-next/widgets/git-sync.js`, 921 lines, `getgrav/grav-plugin-data-manager@6c3735c:/admin-next/pages/data-manager.js`, 530 lines, `getgrav/grav-plugin-consent@a619530:/admin-next/pages/consent.js`, 354 lines).
- Caching: server sends mtime+size ETag, `Cache-Control: private, no-cache` (`GpmController.php:1840-1870`); the SPA keeps scripts in `localStorage` and revalidates via `If-None-Match` (`getgrav/grav-admin-next@230ed21:/src/lib/api/client.ts:289-325`). Do not append cache-buster query.
- Route cache trap: `onApiRegisterRoutes` fires only when building the FastRoute dispatcher. Fingerprint: enabled plugins + mtime of each `blueprints.yaml` (`ApiRouter.php:628-660`). New route in development: `touch` `blueprints.yaml` or `rm cache/api/route.cache` (Skill `SKILL.md:1631`).

#### Mount and context

`mountElement()` (`PluginPageComponent.svelte:77-88`): `containerEl.innerHTML=''`, `document.createElement('grav-<slug>--page')`, listener for `page-state`, `appendChild`. **No props or attributes are set.** All context comes via `window` globals (see table below). The element lives in the Light DOM of the admin; most plugins use `attachShadow({mode:'open'})` within it.

Globals (defined in `src/app.d.ts:13-88`, set in `src/routes/+layout.svelte:360-403`, `auth.svelte.ts:101`):

| Global | Content |
|---|---|
| `__GRAV_API_SERVER_URL` | Server base URL |
| `__GRAV_API_PREFIX` | `/api/v1` |
| `__GRAV_API_TOKEN` | JWT access token (rotated every ~60 min; `FloatingWidgetLoader.svelte:187-190` keeps globals current via `$effect`, always mounted in AppShell, `AppShell.svelte:520`) |
| `__GRAV_ENVIRONMENT` | `default` or environment name; when writing config send as `X-Grav-Environment` AND `X-Config-Environment` (`docs/blueprint-form-element.md`, last paragraph) |
| `__GRAV_PAGE_TAG` / `_FIELD_TAG` / `_WIDGET_TAG` / `_REPORT_TAG` / `_MODAL_TAG` / `_PANEL_TAG` | Tag name the file must define |
| `__GRAV_I18N` | `{t, tHtml, has, locale, dir, subscribe}` (read-only, `i18n.svelte.ts:508-560`) |
| `__GRAV_TOAST` | `{success,error,info,warning}(msg, opts)` (`+layout.svelte:376-381`) |
| `__GRAV_DIALOGS` | `{confirm, form, open}` (`+layout.svelte:360-370`) |
| `__GRAV_NAVIGATE(url, opts)` | SPA navigation via `goto` (line 390) |
| `__GRAV_ADMIN_BASE` | Admin route base, e.g. `/admin` |
| `__GRAV_CONTENT_LANG`, `__GRAV_PAGE_ROUTE`, `__GRAV_PAGE_MEDIA()`, `__GRAV_MEDIA_PICKER()` | Context of the page editor (only set there) |

`window.__GRAV_CONFIG__` (boot config, injected by `admin2.php:550` into `<head>`) is SPA-internal; plugins should use the globals listed above.

Useful addition: `<grav-blueprint-form plugin="my-plugin">` (defined by Admin) renders the real plugin settings within your own page (attributes `plugin|theme|filter|hide-toolbar|hide-fields|tab`, events `blueprint-ready|-dirty|-saved|-error`, methods `save() reload() dirty`). `settings_route` in the definition redirects `/plugins/<slug>` to `/plugin/<slug><route>`. Complete in `getgrav/grav-admin-next@230ed21:/docs/blueprint-form-element.md`.

Other extension points, same mechanism (file + event):

| Purpose | PHP event | File | Tag |
|---|---|---|---|
| Sidebar | `onApiSidebarItems` | - | - |
| Page | `onApiPluginPageInfo` | `admin-next/pages/<slug>.js` | `grav-<slug>--page` |
| Floating widget (FAB) | `onApiFloatingWidgets` | `admin-next/widgets/<slug>.js` | `grav-<slug>--widget` |
| Dashboard widget | `onApiDashboardWidgets` | same `widgets/<slug>.js` via `scriptUrl` | `grav-widget-<id-slug>` |
| Context panel | `onApiContextPanels` | `admin-next/panels/<slug>.js` | `grav-<slug>--panel`? (`__GRAV_PANEL_TAG`) |
| Modal | (call via JS/menubar) | `admin-next/modals/<id>.js` | `grav-<slug>--modal-<id>` |
| Report | `onApiGenerateReports` | `admin-next/reports/<id>.js` | `grav-<slug>--<id>` |
| Custom field | (blueprint `type:`) | `admin-next/fields/<type>.js` | `__GRAV_FIELD_TAG` |
| Menubar button | `onApiMenubarItems` + `onApiMenubarAction` | - | - |
| Markdown toolbar button | `onApiMarkdownEditorButtons` | modals | - |

Routes in API: `ApiRouter.php:852-869, 1020, 1023-1030`. Only the panel tag form is not verified by me against the code (Skill says `grav-{slug}--…`).

---

### 2. Dashboard widgets

**Yes.** Binding:

- PHP: event `onApiDashboardWidgets`, append to `$event['widgets']` (`getgrav/grav-plugin-api@e220660:/classes/Api/Services/DashboardLayoutResolver.php:128-140`, call in `DashboardWidgetController` via `GET /dashboard/widgets`, `ApiRouter.php:893`).
- Entry format (normalized in `DashboardLayoutResolver.php:143-170`, type `WidgetDef` in `getgrav/grav-admin-next@230ed21:/src/lib/dashboard/types.ts:3-14`): `id` (required), `label`, `icon` (Lucide name, e.g. `Eye`), `sizes` from `xs|sm|md|lg|xl`, `defaultSize`, `authorize`, `priority`, **`scriptUrl`**, `dataEndpoint` (optional, set as `data-endpoint` attribute), `plugin`.
- Real reference `trilbymedia/grav-plugin-views@fa07d49:/views.php:171-185`:
  ```php
  $widgets[] = [
      'id' => 'views.top-pages', 'label' => 'Grav Views', 'icon' => 'Eye',
      'sizes' => ['sm','md'], 'defaultSize' => 'sm',
      'authorize' => 'api.reports.read', 'priority' => 55,
      'scriptUrl' => '/gpm/plugins/views/widget-script',   // = admin-next/widgets/views.js
  ];
  ```
  Subscription in `onPluginsInitialized` via `$this->enable()` and config switch (`views.php:93-104`).
- Loading (`getgrav/grav-admin-next@230ed21:/src/lib/components/dashboard/PluginWidgetLoader.svelte`): `api.fetchScript(scriptUrl)`, same blob import, `window.__GRAV_WIDGET_TAG = 'grav-widget-<id normalized>'`. Mount with **attributes** `size`, `data-endpoint`, `plugin`, `widget-id` (lines 55-63). This is the only place where the SPA gives attributes to a plugin element.
- User can show/hide widgets, reorder, resize; layout per user in `admin_next.dashboard` of account YAML, site default in `user/config/admin-next.yaml` (`DashboardLayoutResolver.php`).
- Gotcha: the file `admin-next/widgets/<slug>.js` is **the same** for floating widget and dashboard widget of a plugin (one script per slug and type, Skill `SKILL.md:1639`). The defined tag depends on who loads it, so always use `window.__GRAV_WIDGET_TAG`.

`trilbymedia/grav-plugin-views@fa07d49:/admin-next/widgets/views.js` (151 lines) is the shortest real example: shadow DOM, `fetch(${server}${prefix}/views/top-pages?limit=6, {headers:{'X-API-Token': token}})`, colors via `var(--card)`, `var(--border)`.

---

### 3. Plugin config blueprints in Admin2

- Config page per plugin `/plugins/<slug>`: the SPA calls `GET /blueprints/plugins/{slug}` (`src/lib/api/endpoints/blueprints.ts:238`) and renders the resolved `blueprints.yaml` (`form:` part) with `BlueprintForm` → `FieldRenderer.svelte`. Uses Grav blueprint resolution server-side, so `config-default@:`, `data-options@`, translation keys pass unchanged (`SKILL.md:1005-1030`).
- Own blueprint page (`page_type: 'blueprint'`): `GET /blueprints/plugins/{slug}/pages/{pageId}` reads `admin/blueprints/<pageId>.yaml` in the plugin, falls back to `blueprints.yaml` when `pageId === slug` (`BlueprintController.php:593-617`). Data via `data_endpoint`, save via `save_endpoint` (PATCH). Reference: `getgrav/grav-plugin-license-manager@1fa21d1:/license-manager.php:89-125` and `trilbymedia/grav-plugin-git-sync@4714066:/git-sync.php:331-383`.

#### Supported field types (from `FieldRenderer.svelte:194-690`)

`text` (+ `email url tel number date time month week color`), `password`, `textarea`, `markdown`/`editor`, `select` (single/multiple, `data-options@`), `selectize`, `toggle`/`switch`, `checkbox`, `checkboxes`, `radio`, `range`, `datetime`, `dateformat`, `colorpicker`, `array`, `list` (nested), `tabs`/`tab`, `section`/`fieldset`, `columns`/`column`, `spacer`, `display`, `folder-slug`, `pages`/`parents`, `taxonomy`, `pagemedia`, `media`, `filepicker`/`mediapicker`/`pagemediaselect`, `file` (Uppy upload), `cron`, `cronstatus`, `multilevel`, `themeselect`, `elements`/`element`, `conditional`, `frontmatter`/`codemirror`, `iconpicker`, `permissions`/`acl_picker`, `webhook-status`, `page-exists`. Suppressed: `order`, `blueprint`, `hidden`, `backupshistory`, fields named `enabled` etc. (`FieldRenderer.svelte:171-183`). So the `enabled` toggle is drawn by Admin2 itself. The field in `blueprints.yaml` does not hurt, just is not shown.
Earlier `FIELD-AUDIT.md` (2026-03) is outdated; authoritative is the code. **Unknown type without custom field registration falls back to raw JSON editor** (line 703-710).

#### Custom field types: yes

- File `admin-next/fields/<type>.js` in the plugin (or theme) = field type `<type>`. API scan: `GpmController.php:1446-1478` (`discoverCustomFields`), list `GET /custom-fields` (line 1494, `ApiRouter.php:852`), scripts bundled `GET /gpm/{plugins|themes}/{slug}/fields` → JSON `{type: code}` (line 1564) or individually `/field/{type}` (line 1536; also delivered for disabled plugins).
- In blueprint: `type: <filename>`, other keys of the field are readable as `this.field` in the element.
- Contract (`CustomFieldWrapper.svelte:140-200`): SPA sets properties `el.field = <blueprint field object>` and `el.value = <current value>` **before** attaching (value is reset on changes); the component reports changes via `this.dispatchEvent(new CustomEvent('change', {detail: newValue, bubbles: true}))`. Only events with `e.target === el` count (line 176-184), native `change` events of inner inputs in Light DOM are ignored. Optional `commit` event. Collaboration: `yFragment/yAwareness/yUser` properties.
- Each field file is evaluated separately with its own `__GRAV_FIELD_TAG`, so **no shared top-level variables, no `import` between field files** (`SKILL.md:617-618`).
- `<grav-blueprint-form>` supports plugin-owned field types as well (`docs/blueprint-form-element.md`).

Example real field (read-only display): `getgrav/grav-plugin-license-manager@1fa21d1:/admin-next/fields/products-status.js` (`const TAG = window.__GRAV_FIELD_TAG;`, setter `field`/`value`, Light DOM with inline `<style>`, `fetch(... {headers:{'X-API-Token': token}})`).

---

### 4. Design tokens

Source: `getgrav/grav-admin-next@230ed21:/src/routes/layout.css` (428 lines), `src/lib/stores/theme.svelte.ts`, `src/app.html`.

- **Stack**: Tailwind 4 (`@import 'tailwindcss'`, `@theme` mapping line 111-144), shadcn/ui pattern, `bits-ui` (headless), **icons: `lucide-svelte` (core UI, floating widgets, context panels) + Font Awesome 7 free (sidebar, menubar, plugin page icons `fa-…`)** (`package.json`: `lucide-svelte ^1.0.1`, `@fortawesome/fontawesome-free ^7.2.0`). `IconSpec` for extension APIs: strings `clock`, `fa:clock`, `fa-regular:clock`, `fa-brands:github`, `class:ti ti-user`, or `{type:'svg', viewBox, path|elements}` (no raw SVG) (`docs/IconSpec.md`).
- **Dark mode toggle**: **class `dark` on `<html>`** (`theme.svelte.ts:62-63`; Tailwind `@custom-variant dark (&:where(.dark, .dark *))`, `layout.css:7`). `<html data-theme="grav">` is constant (`app.html:4`). `color-scheme: light|dark` is also set (`layout.css:15-22, 55-56`). Mode: `''` (system via `prefers-color-scheme`), `light`, `dark` (`theme.svelte.ts:40-60`). Toggle is live; plugin must react to `document.documentElement.classList.contains('dark')` (MutationObserver) if it does not just use CSS variables. Variables toggle automatically.
- **Accent**: `--primary` and `--ring` are overridden inline on `<html>` (`hsl(<hue> <sat>% 40%|65%)`), `theme.svelte.ts:72-73`. So never hardcode primary.
- **Inheritance in shadow DOM**: custom properties cross shadow boundaries (`getgrav/grav-skills@eab567e:/.../host-tokens.md:5`), Tailwind classes do not.

CSS custom properties (light / dark), `layout.css:15-85`:

| Purpose | Property | Light | Dark |
|---|---|---|---|
| Page background | `--background` | `hsl(0 0% 100%)` | `hsl(240 6% 10.6%)` |
| Text | `--foreground` | `hsl(240 10% 3.9%)` | `hsl(0 0% 98%)` |
| Card | `--card`, `--card-foreground` | white / like foreground | `hsl(240 4.5% 13%)` / `hsl(0 0% 98%)` |
| Popover | `--popover`, `--popover-foreground` | white | `hsl(240 4.5% 14.5%)` |
| Primary | `--primary`, `--primary-foreground` | `hsl(221 83% 53%)` (default, user accent overrides) / white | `hsl(217 91% 60%)` |
| Secondary/muted/accent | `--secondary`, `--muted`, `--accent` (+ `-foreground`) | `hsl(240 4.8% 95.9%)` | `hsl(240 3.7% 15.9%)` |
| Muted text | `--muted-foreground` | `hsl(240 3.8% 46.1%)` | `hsl(240 5% 64.9%)` |
| Danger | `--destructive`, `--destructive-foreground` | `hsl(0 84.2% 60.2%)` / `hsl(0 0% 98%)` | `hsl(0 62.8% 30.6%)` (fill color, never as text) |
| Success | `--success`, `--success-foreground` | `hsl(142 71% 40%)` | `hsl(142 60% 45%)` |
| Warning | `--warning`, `--warning-foreground` | `hsl(38 92% 48%)` | `hsl(38 90% 55%)` |
| Border | `--border` | `hsl(240 5.9% 90%)` | `hsl(240 4% 20%)` |
| Input border | `--input` | `hsl(240 5.9% 90%)` | `hsl(240 4% 22%)` |
| Focus ring | `--ring` | like primary | like primary |
| Sidebar | `--sidebar`, `--sidebar-foreground`, `--sidebar-border`, `--sidebar-accent`, `--sidebar-accent-foreground` | | |
| Radius | `--radius: 0.5rem`; `--radius-sm` = -4px, `-md` = -2px, `-lg` = `--radius`, `-xl` = +4px (`layout.css:141-144`) | | |
| Font | `--font-sans` (Google Sans self-hosted, fallback Inter Variable/system); user font size via `html {font-size: var(--app-font-size, 16px)}` | | |
| Spacing | Tailwind 4 `--spacing: .25rem` | | |

Focus style in host: `focus-visible:ring-1 focus-visible:ring-ring`, in plugins `box-shadow: 0 0 0 1px var(--ring)` (`host-tokens.md`). Button `h-9 text-sm`, small `h-8 text-xs`; card `rounded-lg border bg-card shadow-sm`; tabs: underline instead of segments; table head `bg-muted/30 text-xs` without uppercase. The file `getgrav/grav-skills@eab567e:/skills/grav-admin-ui-polish/references/host-tokens.md` is the most compact summary (Grav core team) and matches `layout.css`.
Direction: `<html dir="rtl">` set; use logical CSS properties (`docs/RTL.md`, Skill line 1414-1493).

---

### 5. Toasts and i18n

**Toasts**: yes. `window.__GRAV_TOAST.success|error|info|warning(message, options)` (`+layout.svelte:376-381`; svelte-sonner, `options` passed through, e.g. `{duration: 6000}`). Always call with `?.`. The server also controls toasts itself: a `save_endpoint`/action endpoint can return in the body `{toast:{message,type,duration,dismissible}}` or `{message:'…'}` (`src/lib/utils/toast-hint.ts`, `+page.svelte:150-156`), errors likewise in `ErrorResponse`.
**Dialogs**: `window.__GRAV_DIALOGS.confirm({title,message,confirmLabel,cancelLabel,variant:'destructive'|'default'}) → Promise<boolean>`; `.form({title,description,fields:[{name,type:'text|textarea|select|toggle|number',label,placeholder,help,required,value,options}],submitLabel,size}) → Promise<values|null>`; `.open({plugin,component,title,props,size,useStandardHeader}) → Promise<result|null>` (component fires `resolve`/`cancel` on its own element). Do not use native `confirm()/alert()` (`SKILL.md:702-740`, `consent.js:127-133`).

**i18n** (`getgrav/grav-admin-next@230ed21:/docs/i18n.md`, `i18n.svelte.ts:508-560`):
- Translations come from the normal Grav language system: `languages/<lang>.yaml` of the plugin, merged from core, delivered via `GET /api/v1/translations/{lang}`. No registration needed.
- Lookup order: first `ICU.<key>` (ICU MessageFormat: plural, select), then `<key>` raw, else humanize fallback ("Filter All"). Grav-1-compatible plugins supply both: flat block + `ICU:` block.
- In web components: `const {t, has, locale, dir, subscribe} = window.__GRAV_I18N;` `t(key, params)`, `subscribe(fn)` returns unsubscribe. The dictionary arrives **after** first paint and language can change live, so re-render (`consent.js:26-42`). **Call `has(key)` before `t(key)`** and fallback text in code, because `t()` humanizes unknown keys (`consent.js:148-168`).
- Sidebar labels and blueprint labels: keys are translated by the API/resolved by SPA; page title in `onApiPluginPageInfo` is **not** translated (`consent.php:509-511`), so translate server-side.
- Debug: `__GRAV_I18N_DEBUG.enable()`, then `report()` (`docs/i18n.md`).

---

### 6. Reference plugins (Grav core team, current)

| Plugin | What | Important files |
|---|---|---|
| **grav-plugin-consent** 1.0.2 (getgrav, Grav core team) | Component page (log/stats/CSV/purge), own API routes, own custom field (`consent-inventory`), sidebar, permissions | `consent.php:35-50, 472-524`, `admin-next/pages/consent.js`, `admin-next/fields/consent-inventory.js`, `classes/Api/ConsentApiController.php`, `permissions.yaml`, `languages/` |
| **tm-views** 1.3.4 (trilbymedia) | **Dashboard widget** + report component | `views.php:60-63, 93-104, 171-185`, `admin-next/widgets/views.js`, `admin-next/reports/views-report.js` |
| **tm-git-sync** (trilbymedia) | Blueprint page with toolbar actions, sidebar, menubar button, floating widget (`autoLoad`, without FAB) as wizard modal, custom field | `git-sync.php:46-52, 253-345, 331-383, 462-476`, `admin-next/widgets/git-sync.js`, `admin-next/fields/enc-password.js` |
| **license-manager** 2.0.5 (getgrav) | Blueprint page (`admin/blueprints/licenses.yaml`) + custom field | `license-manager.php:35-125`, `admin-next/fields/products-status.js` |
| grav-plugin-data-manager (getgrav) | Component page (table) | `data-manager.php:65-120`, `admin-next/pages/data-manager.js` |

Result on the build question: **none of these plugins use Vite, Svelte, or any build tool for `admin-next/`.** All handwritten vanilla-JS custom elements, checked in directly (sizes: 97 to 921 lines). The only `vite.config.ts` in the vicinity belongs to the admin SPA itself (`getgrav/grav-admin-next@230ed21:/vite.config.ts`). The old Webpack configs in tntsearch/git-sync belong to the classic admin UI (`app/`, `js/`), not `admin-next/`. No publicly accessible Grav core team plugin ships a compiled bundle. Editor Pro (TipTap, `admin/assets/editor-pro.js` per `SKILL.md:1490`) and AI Pro are premium/not cloneable. Whether a Vite bundle exists there is **open**.

#### PHP side details

- Custom route controllers inherit `Grav\Plugin\Api\Controllers\AbstractApiController` (`consent/classes/Api/ConsentApiController.php`), registration with `$event['routes']->get('/consent/inventory', [$controller,'inventory'])` (`consent.php:472-485`). Static routes before parameterized (FastRoute order). Responses `ApiResponse::create($data)` → `{data: ...}`, `ApiResponse::paginated(...)` → `{data:[…], meta:{total…}, links}`.
- Permissions: `permissions.yaml` (`api.consent: {type: access, actions: {read, write}}`), check in controller `$this->requirePermission($request, 'api.consent.read')` (`AbstractApiController.php:59-97`; super admin can do everything, else `api.access` + individual right). **`$user->authorize()` does not work in API context**, use `$user->get('access.…')` or `requirePermission` instead (`SKILL.md:1630`).
- **Never use `isAdmin()` in `onPluginsInitialized` as a switch for `onApi*`/write hooks** (`consent.php:41-44`, `SKILL.md:1528-1597`).

#### How bundles call the API (actual code, `consent.js:46-64`)

```js
_apiUrl(path) {
    return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
}
_headers(json = false) {
    const h = {};
    // X-API-Token, not Authorization: Bearer: FastCGI/MAMP may strip Authorization.
    if (window.__GRAV_API_TOKEN) h['X-API-Token'] = window.__GRAV_API_TOKEN;
    if (json) h['Content-Type'] = 'application/json';
    return h;
}
async _api(method, path) {
    const resp = await fetch(this._apiUrl(path), { method, headers: this._headers() });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const json = await resp.json();
    return { data: json.data ?? json, meta: json.meta };
}
```
No CSRF token, no `credentials: 'include'`. All four Grav core team plugin bundles use this pattern identically (`tm-views/.../views.js:24-29`, `tm-git-sync/.../git-sync.js:24-45`, `license-manager/.../products-status.js`).

---

### 7. Authentication and calling own routes

- **Login**: `POST /api/v1/auth/token` (`ApiRouter.php:705`) returns `{access_token, refresh_token, token_type:'Bearer', expires_in, user}` (`AbstractApiController.php:993-1005`). Default: access JWT 3600 s, refresh 604800 s (`api.yaml:12-13`, HS256, `JwtAuthenticator.php:30-56`). Refresh: `POST /auth/refresh` with `{refresh_token}` **rotates** the refresh token server-side (`AuthController.php:208-236`, comment `auth.svelte.ts:220-223`).
- **Storage in SPA**: `localStorage` under `scopedKey('grav_admin_auth')` (`auth.svelte.ts:8, 43, 163`), contains access, refresh, `expiresAt`, `serverUrl`, `apiPrefix`, `environment`. The SPA also writes the access token to `window.__GRAV_API_TOKEN` (see above). **Plugins should only read this global** and **read it fresh on every request** (token rotates hourly).
- **SPA headers** (`client.ts:355-400`): `X-API-Token: <jwt>`, `Accept: application/json`, `Content-Type: application/json`, `X-Grav-Environment` and `X-Config-Environment` (`default` = base). Server also accepts `Authorization: Bearer <jwt>` (`JwtAuthenticator.php:313-330`) and `X-API-Key` (`ApiKeyAuthenticator.php:107-108`).
- **Auth chain** in router: API key, JWT, then session cookie as last fallback (`AuthMiddleware.php:162-181`). Session cookie writes are checked against CSRF via `SameOriginGuard` (origin/referer or custom header) (`SameOriginGuard.php`); with `X-API-Token` they are exempt (header is proof). **No separate CSRF token for plugin calls.**
- **Call own routes**: register route in `onApiRegisterRoutes` under `/api/v1/<path>`, call from web component with the helper above. Responses in envelope `{data:…, meta?:…}`; errors as problem JSON `{errors:[{detail}]}`/`{detail}` (`git-sync.js:40-44`). Downloads: `fetch(...).blob()` + `<a download>` (`consent.js:106-122`), or as action `download:true` in definition. After changes the API can send `X-Invalidates: pages:update:/x, pages:list`, SPA updates affected views (`client.ts:406-420`).
- **Rate limit**: `/field`, `/fields`, `*-script` are exempt from limiter (`SKILL.md:620`; config `plugins.api.rate_limit.excluded_paths`, `RateLimitMiddleware.php:78`, default only `/sync/`, `/thumbnails/`, script exemption is elsewhere, not pursued further).
- **Demo mode** blocks writes server-side (`AbstractApiController.php:79`) and in SPA for action endpoints.

---

### Best practice: which reference to copy

1. **Own page with data, actions, permissions, i18n**: `getgrav/grav-plugin-consent@a619530:` (structure `consent.php` + `classes/Api/` + `admin-next/pages|fields` + `permissions.yaml` + `languages/`). Closest to a clean Grav 2.x plugin.
2. **Dashboard widget**: `trilbymedia/grav-plugin-views@fa07d49:` (`views.php:171-185` + `admin-next/widgets/views.js`).
3. **Settings page without JS**: `getgrav/grav-plugin-license-manager@1fa21d1:` or `trilbymedia/grav-plugin-git-sync@4714066:` (blueprint mode).

Convention layout (`SKILL.md:1497-1524`):

```
my-plugin/
  my-plugin.php  my-plugin.yaml  blueprints.yaml  permissions.yaml  composer.json
  admin/blueprints/<pageId>.yaml           // only for page_type blueprint
  admin-next/pages/my-plugin.js            // grav-my-plugin--page
  admin-next/widgets/my-plugin.js          // floating and dashboard widget
  admin-next/fields/<type>.js  admin-next/modals/<id>.js  admin-next/reports/<id>.js  admin-next/panels/my-plugin.js
  classes/Api/MyApiController.php
  languages/en.yaml de.yaml
```

`blueprints.yaml` needs `compatibility: grav: ['2.0']`, dependency `api` (consent: `dependencies: grav >=2.0.0, php >=8.3`).

Skeleton of a page component (from `consent.js`, reduced):

```js
const TAG = window.__GRAV_PAGE_TAG;                       // grav-<slug>--page
class MyPage extends HTMLElement {
  constructor(){ super(); this.attachShadow({mode:'open'}); }
  connectedCallback(){
    this._render(); this._load();
    this._unsub = window.__GRAV_I18N?.subscribe(() => this._render());
    this.addEventListener('page-action', e => { if (e.detail?.id === 'save') this._save(); });
  }
  disconnectedCallback(){ this._unsub?.(); }
  async _load(){ /* fetch with X-API-Token, then this.dispatchEvent(new CustomEvent('page-state',{detail:{dirty:false}})) */ }
}
customElements.define(TAG, MyPage);
```

If a build (Vite) is desired after all: the loading mechanism implies the output must be **a single ES file without chunks** (`build.lib` `formats:['es']` with `inlineDynamicImports`, CSS inline into shadow root, because blob import does not fetch `<link>`) and it must call `customElements.define(window.__GRAV_PAGE_TAG, …)` itself. This is inference from `PluginPageComponent.svelte:58-63`, **not** documented by Grav core team.

---

### Open questions / limitations

- **No Vite bundle example** in public Grav core team repos. Editor Pro, AI Pro, SEO Magic, Page Insights are private/premium (clone 404). Whether built bundles (TipTap etc.) use the same blob import is documented through citations (`SKILL.md:1490`, `README.md` "editor-pro, ai-pro" in `i18n.svelte.ts:503-505`), but build structure is not visible.
- **Blob import and relative imports / code splitting**: inferred from loading mechanism (blob URL without base), not tested. Before Vite setup in a dev Grav, test: single file, no `import.meta.url`, no dynamic imports.
- **CSP**: I found no Content-Security-Policy in the admin shell (`admin2.php`, API). If a host enforces `script-src` without `blob:`, all plugin components would fail. Not checked.
- **Sidebar `route` with hash** not explicitly documented (see above).
- **Panel tag** (`grav-<slug>--panel`?) and context panel contract not read in detail (`ContextPanelController.php`, `ContextPanelHost.svelte`); probably irrelevant for a typical plugin.
- **Rate limit order for `*-script`**: skill claims exemption, default config names only `/sync/`, `/thumbnails/`; not traced to source.
- **No live test**: none of this ran against a running Grav 2.1 instance. All statements come from source code at the commits listed above. Version drift possible (admin2 2.1.25, api 1.0.42).
- **`onApiPluginPageInfo` with `page_type: 'component'`** without `.js` file: `has_custom_component` becomes `false`, SPA loads `page-script` anyway and reports "Failed to load page component" (`PluginPageComponent.svelte:71-74`). File must exist.
- Page title in `onApiPluginPageInfo` is not translated (see consent comment), sidebar label is.
