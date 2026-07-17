export const site = {
  name: 'Chef',
  locale: 'en_AU',
  language: 'en-AU',
  title: 'Chef — A shared meal plan for the household',
  description:
    'Plan the week once. Chef turns one household conversation into a shared meal plan, shopping list, and a Woolworths or Coles trolley ready for your review.',
  shortDescription:
    'A shared meal planner that carries the week through to a ready-to-review grocery trolley.',
  country: 'Australia',
  currency: 'AUD',
  monthlyPrice: 9,
  annualPrice: 89,
  annualSaving: 19,
  trialDays: 14,
} as const;

export const primaryNavigation = [
  { label: 'How it works', href: '/#how-it-works' },
  { label: 'Meal planning', href: '/#meal-planning' },
  { label: 'Shopping', href: '/#shopping' },
  { label: 'Pricing', href: '/#pricing' },
] as const;

export const faqs = [
  {
    question: 'Is the price for the whole household?',
    answer:
      '$9 monthly or $89 annually covers one household, including invited members and the people represented in meal plans.',
  },
  {
    question: 'What does unlimited include?',
    answer:
      'Unlimited household members, meal plans, recipes, and shopping lists. Fair use applies only to unusually intensive AI, voice, or retailer automation—not ordinary household planning.',
  },
  {
    question: 'What happens after the 14-day trial?',
    answer:
      'Nothing is charged because no card is required. Choose monthly or annual billing only if you want to continue.',
  },
  {
    question: 'Do I need to use voice?',
    answer:
      'No. The complete planning workflow remains available through typing and direct controls.',
  },
  {
    question: 'Will Chef place or pay for my grocery order?',
    answer:
      'No. Chef can prepare and reconcile a Woolworths or Coles trolley in your own retailer account. You review the trolley and remain responsible for delivery details, checkout, and payment.',
  },
  {
    question: 'Can I cancel or change billing periods?',
    answer:
      'Yes. Cancel any time and keep access until the end of the paid period. Billing-period changes take effect at the next renewal.',
  },
  {
    question: 'What happens to my household data?',
    answer:
      'Export or delete your household data regardless of billing status. The security and privacy page explains provider, retention, and retailer-access boundaries.',
  },
] as const;
