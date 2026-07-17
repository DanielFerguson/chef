const placeholderHost = /(^|\.)(localhost|example|invalid|test)(\.|$)/i;

export function validateProductionConfig(environment) {
  const siteUrl = productionUrl('SITE_URL', environment.SITE_URL, true);
  const appUrl = productionUrl('PUBLIC_APP_URL', environment.PUBLIC_APP_URL);
  const loginUrl = productionUrl(
    'PUBLIC_LOGIN_URL',
    environment.PUBLIC_LOGIN_URL,
  );

  if (appUrl.origin !== loginUrl.origin) {
    throw new Error(
      'PUBLIC_APP_URL and PUBLIC_LOGIN_URL must share one origin.',
    );
  }

  if (appUrl.pathname !== '/start' || loginUrl.pathname !== '/login') {
    throw new Error(
      'PUBLIC_APP_URL must end in /start and PUBLIC_LOGIN_URL must end in /login.',
    );
  }

  return { siteUrl, appUrl, loginUrl };
}

function productionUrl(name, value, originOnly = false) {
  let url;

  try {
    url = new URL(value);
  } catch {
    throw new Error(`${name} must be set to an exact production HTTPS URL.`);
  }

  if (
    url.protocol !== 'https:' ||
    url.username ||
    url.password ||
    url.port ||
    url.search ||
    url.hash ||
    placeholderHost.test(url.hostname) ||
    (originOnly && url.origin !== value)
  ) {
    throw new Error(
      `${name} must be set to an exact non-placeholder production HTTPS URL.`,
    );
  }

  return url;
}
