import sitemap from '@astrojs/sitemap';
import { defineConfig } from 'astro/config';

const site = process.env.SITE_URL ?? 'https://chef.example';

export default defineConfig({
  site,
  output: 'static',
  trailingSlash: 'never',
  integrations: [
    sitemap({
      filter: (page) => !page.endsWith('/404'),
    }),
  ],
  build: {
    format: 'directory',
  },
});
