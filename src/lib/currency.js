/**
 * The currency picker's options, shared by Settings → General and the
 * setup wizard's Business step so both offer the same list.
 */
import { locale } from './i18n';

// The currencies most sites use. A site's own saved code is always added
// on top (the field used to be free text, so an existing value must never
// disappear) — see currencyOptions().
export const COMMON_CURRENCIES = ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'JPY', 'CNY', 'HKD', 'SGD', 'INR', 'BDT', 'PKR', 'LKR', 'NPR', 'IDR', 'MYR', 'THB', 'PHP', 'VND', 'AED', 'SAR', 'QAR', 'TRY', 'ILS', 'EGP', 'NGN', 'KES', 'ZAR', 'GHS', 'MAD', 'BRL', 'MXN', 'ARS', 'CLP', 'COP', 'RUB', 'UAH', 'KRW'];

export function currencyOptions(current) {
  const code = (current || '').toUpperCase();
  return code && !COMMON_CURRENCIES.includes(code) ? [code, ...COMMON_CURRENCIES] : COMMON_CURRENCIES;
}

// "USD — US Dollar" where the browser knows the name, just the code otherwise.
export function currencyLabel(code) {
  try {
    const name = new Intl.DisplayNames(locale, { type: 'currency' }).of(code);
    return name && name !== code ? `${code} — ${name}` : code;
  } catch {
    return code;
  }
}
