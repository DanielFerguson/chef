# Chef marketing site

Static Astro implementation of Chef's public marketing site. The approved paid
homepage in Paper and `docs/COMMERCIAL-STRATEGY.md` are the content and layout
sources of truth.

## Local development

```bash
bun install
bun run dev
```

The production gate is:

```bash
bun run format:check
bun run build
```

## Environment

Set these values in the deployment environment:

- `SITE_URL` — canonical public origin used by canonical links, structured data,
  robots, and sitemap generation. It defaults to `https://chef.example` so an
  unset production value is conspicuous.
- `PUBLIC_APP_URL` — application first-plan/onboarding URL.
- `PUBLIC_LOGIN_URL` — application login URL.

The current defaults deliberately use `.example` destinations. Replace them
before a public deployment; do not silently publish placeholder canonicals or
application links.

## Static and retrieval foundations

- Pages are statically generated and all meaningful homepage content exists in
  the initial HTML.
- Motion is progressive enhancement implemented with Motion and disabled for
  reduced-motion preferences.
- `robots.txt`, XML sitemaps, `llms.txt`, and `llms-full.txt` are generated from
  the canonical site URL.
- The homepage publishes visible, matching `Organization`, `WebSite`,
  `SoftwareApplication`, and `FAQPage` structured data.
- The social card source is `public/og/chef-home.svg`; the referenced production
  asset is `public/og/chef-home.png`.

## Publication gate

Before launch, verify the visible pricing, trial, billing, privacy, AI, retailer,
retention, export, and deletion statements against the application and final
provider terms. Replace illustrative product states with release-candidate
captures where the commercial strategy requires them.
