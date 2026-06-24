# Layout contract

Rules for the global application shell. Breaking one of these is how the
"header/footer disappears / duplicates on some pages" bug comes back. The
Playwright smoke suite (`frontend/e2e/shell.smoke.spec.js`) enforces most of
this in CI — if you change the shell, run `npm run test:e2e`.

## The rules

1. **`AppLayout` owns the header and footer — nothing else does.**
   `AppHeader` and `AppFooter` are rendered once, by `AppLayout`. No page
   component may render `header.topbar`, `footer.site-footer`, or its own copy
   of the site nav. Page-specific *content* titles (e.g. `.study-head`,
   `.pastor-head`) are fine — they are not site chrome.

2. **One shell per route tree.** Exactly one `[data-layout="app"]` /
   `#app-shell` exists in the DOM at any time. Never nest an `AppLayout` inside
   a route's content. (Smoke test asserts `count === 1`.)

3. **Pages must not reintroduce global chrome.** If you need a back action or
   navigation, use the global nav. Don't add per-page "← Back to X" links that
   duplicate it.

4. **Full-width pages are allowed — by exception, intentionally.**
   The Bible reader, Admin console, and Worship radio are full-bleed working
   surfaces. The default intake/auth/account flow uses the centered `.shell`
   card (max-width 600). `AppLayout`/`.app-main` must not impose a global
   max-width that would constrain the full-width pages.

5. **Scroll reset rule.** On navigation, the page scrolls to top **only when
   the base route changes**. Same-page hash changes (e.g.
   `#pastor` → `#pastor?session=…`) must preserve scroll position. Logic lives
   in `App.vue`'s `hashchange` handler.

6. **Responsive nav rule.** On phones (≤640px) the brand collapses to the logo
   mark and the nav becomes a horizontally-scrollable strip so the theme toggle
   always stays visible. The page itself must never overflow horizontally — the
   nav scrolls internally, the document does not. (Smoke test asserts no
   horizontal overflow at 390px.)

7. **Sticky footer.** `.page` is a flex column and `.app-main` is `flex: 1`, so
   the footer sits at the bottom on short pages and after the content on long
   ones — never floating mid-viewport.

8. **Bottom nav is mobile-only and additive.** `BottomNav` is rendered once, by
   `AppLayout`, and is the primary navigation on phones (≤640px). It is
   `display:none` on ≥641px, where the `AppHeader` top-bar nav stays primary —
   the two never show at once, so there is no mixed-navigation regression. It
   has exactly four primary tabs (Home / Songs / Bible / Study) plus a "More"
   bottom sheet for every secondary, permission-gated destination. Page content
   reserves `--bottom-nav-h` of bottom padding on phones so nothing hides behind
   the fixed bar. Icons are Iconify (mdi) via `AppIcon` — no emojis. (Smoke test
   asserts one nav, 5 tabs at 390px, hidden at 1200px.)

## Adding a page

1. Add a route flag + `v-else-if` branch in `App.vue`.
2. Add one entry to `navItems` in `AppHeader.vue`.
3. Do **not** add a header/footer to the new component.
4. Run `npm run test:e2e` (and add the route to the `ROUTES` list in the spec).

## Debugging

In a dev build, set `window.__layoutDebug = true` (or append `?layoutDebug` to
the URL) to outline the header / main / footer regions and log scroll state.
The flag and its styles are stripped from production builds.
