function publicUrl(value: string | undefined, fallback: string, label: string) {
  const candidate = value ?? fallback;
  let url: URL;

  try {
    url = new URL(candidate);
  } catch {
    throw new Error(`${label} must be a valid absolute URL.`);
  }

  const localDevelopment =
    url.protocol === 'http:' && url.hostname === 'localhost';

  if (
    (url.protocol !== 'https:' && !localDevelopment) ||
    url.username ||
    url.password
  ) {
    throw new Error(`${label} must use HTTPS without embedded credentials.`);
  }

  return url.toString();
}

export const appUrl = publicUrl(
  import.meta.env.PUBLIC_APP_URL,
  'https://app.chef.example/start',
  'PUBLIC_APP_URL',
);
export const loginUrl = publicUrl(
  import.meta.env.PUBLIC_LOGIN_URL,
  'https://app.chef.example/login',
  'PUBLIC_LOGIN_URL',
);

if (new URL(appUrl).origin !== new URL(loginUrl).origin) {
  throw new Error('PUBLIC_APP_URL and PUBLIC_LOGIN_URL must share one origin.');
}
