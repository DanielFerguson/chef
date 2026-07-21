import type { Browserbase } from '@browserbasehq/sdk';

// Laravel owns Browserbase lifecycle calls today. Keeping this SDK boundary in
// the worker package makes a future typed lifecycle command possible without
// giving the browser worker database or policy authority.
export type BrowserbaseSessionsSdk = Browserbase['sessions'];
