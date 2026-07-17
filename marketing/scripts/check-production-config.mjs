import { validateProductionConfig } from './production-config.mjs';

const { siteUrl, appUrl } = validateProductionConfig(process.env);

console.log(
  `Production marketing configuration is valid for ${siteUrl.origin} and ${appUrl.origin}.`,
);
