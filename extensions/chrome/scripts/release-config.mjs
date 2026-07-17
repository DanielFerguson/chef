export function validateOrigin(value) {
  let url;

  try {
    url = new URL(value);
  } catch {
    throw new Error('Pass an exact production origin with --origin=https://host.');
  }

  if (
    url.protocol !== 'https:' ||
    url.origin !== value ||
    url.port ||
    url.username ||
    url.password ||
    /(^|\.)(localhost|example|invalid|test)(\.|$)/i.test(url.hostname)
  ) {
    throw new Error(
      'The production extension origin must be an exact non-placeholder HTTPS origin.',
    );
  }

  return url.origin;
}

export function validateVersion(value) {
  if (!/^\d+\.\d+\.\d+$/.test(value ?? '')) {
    throw new Error('Pass a numeric extension version with --version=x.y.z.');
  }

  return value;
}
