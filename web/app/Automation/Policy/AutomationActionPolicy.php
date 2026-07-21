<?php

namespace App\Automation\Policy;

use App\Automation\Data\PolicyAssessment;
use App\Enums\AutomationPolicyDecision;
use Illuminate\Support\Str;

class AutomationActionPolicy
{
    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $observation
     */
    public function assess(array $action, array $observation): PolicyAssessment
    {
        $type = is_string($action['type'] ?? null) ? $action['type'] : '';
        $allowed = ['screenshot', 'click', 'double_click', 'move', 'scroll', 'wait', 'keypress', 'type'];

        if (! in_array($type, $allowed, true)) {
            return $this->blocked('That browser action is not allowlisted.');
        }

        $url = is_string($observation['url'] ?? null) ? $observation['url'] : '';
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! in_array(Str::lower($host), ['woolworths.com.au', 'www.woolworths.com.au'], true)) {
            return $this->blocked('The current page is outside the Woolworths origin allowlist.');
        }

        $path = rtrim(Str::lower((string) parse_url($url, PHP_URL_PATH)), '/') ?: '/';
        if (($path !== '/shop/checkout/cart' && Str::contains($path, '/checkout'))
            || Str::contains($path, ['/securelogin', '/account', '/payment', '/address', '/delivery', '/pickup', '/orders'])) {
            return $this->intervention('The browser reached a sensitive or human-only Woolworths page.');
        }

        if ((bool) ($observation['bot_detected'] ?? false)) {
            return $this->intervention('Woolworths presented bot detection or a CAPTCHA.');
        }

        if ((bool) ($observation['sensitive_screen'] ?? false)) {
            return $this->intervention('The current page may expose account, address, fulfilment, or payment information.');
        }

        if ($type === 'type' && (bool) ($observation['sensitive_field'] ?? false)) {
            return $this->blocked('Typing into authentication or sensitive fields is never delegated to the model.');
        }

        if (in_array($type, ['click', 'double_click'], true)
            && is_array($action['keys'] ?? null)
            && $action['keys'] !== []) {
            return $this->blocked('Modifier-assisted clicks are outside Chef’s cart-preparation action policy.');
        }

        if ($type === 'keypress') {
            $keys = is_array($action['keys'] ?? null) ? $action['keys'] : [$action['key'] ?? null];
            $keys = array_map(fn ($key) => is_string($key) ? Str::upper($key) : '', $keys);
            $safeKeys = ['ENTER', 'ESCAPE', 'TAB', 'ARROWUP', 'ARROWDOWN', 'ARROWLEFT', 'ARROWRIGHT', 'BACKSPACE'];

            if (array_diff($keys, $safeKeys) !== []) {
                return $this->blocked('The requested keyboard shortcut is not allowlisted.');
            }
        }

        return new PolicyAssessment(AutomationPolicyDecision::Allowed, 'The action stays within the Woolworths cart-preparation boundary.');
    }

    private function blocked(string $reason): PolicyAssessment
    {
        return new PolicyAssessment(AutomationPolicyDecision::Blocked, $reason);
    }

    private function intervention(string $reason): PolicyAssessment
    {
        return new PolicyAssessment(AutomationPolicyDecision::RequiresIntervention, $reason);
    }
}
