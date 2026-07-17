import { animate, inView, stagger } from 'motion';

const prefersReducedMotion = window.matchMedia(
  '(prefers-reduced-motion: reduce)',
).matches;

if (!prefersReducedMotion) {
  const revealed = new WeakSet<Element>();

  inView(
    '[data-reveal]',
    (element) => {
      if (revealed.has(element)) return;
      revealed.add(element);

      animate(
        element,
        {
          opacity: [0, 1],
          transform: ['translateY(16px)', 'translateY(0)'],
        },
        { duration: 0.55, ease: [0.22, 1, 0.36, 1] },
      );
    },
    { margin: '0px 0px -8% 0px' },
  );

  const planRows = document.querySelectorAll('[data-plan-row]');
  if (planRows.length) {
    animate(
      planRows,
      { opacity: [0.35, 1], transform: ['translateX(10px)', 'translateX(0)'] },
      {
        delay: stagger(0.07, { startDelay: 0.18 }),
        duration: 0.45,
        ease: [0.22, 1, 0.36, 1],
      },
    );
  }

  const connectors = document.querySelectorAll('[data-connector]');
  if (connectors.length) {
    animate(
      connectors,
      { transform: ['scaleX(0)', 'scaleX(1)'] },
      {
        delay: stagger(0.08, { startDelay: 0.2 }),
        duration: 0.5,
        ease: [0.22, 1, 0.36, 1],
      },
    );
  }

  const stageSequence = document.querySelector<HTMLElement>(
    '[data-stage-sequence]',
  );
  const stageList =
    stageSequence?.querySelector<HTMLOListElement>('[data-stage-list]');
  const stageProgress = stageSequence?.querySelector<HTMLElement>(
    '[data-stage-progress]',
  );
  const canAnimateStages = window.matchMedia('(min-width: 64.01rem)');

  if (stageSequence && stageList && stageProgress) {
    let hasPlayed = false;

    inView(
      stageSequence,
      () => {
        if (hasPlayed || !canAnimateStages.matches) return;
        hasPlayed = true;

        const stageCount = stageList.children.length;
        const stageDuration = 2.2;
        stageList.dataset.activeStep = '0';

        animate(
          stageProgress,
          { transform: ['scaleX(0)', 'scaleX(1)'] },
          { duration: stageDuration * stageCount, ease: 'linear' },
        );

        Array.from({ length: stageCount - 1 }, (_, index) => index + 1).forEach(
          (step) => {
            window.setTimeout(
              () => {
                stageList.dataset.activeStep = String(step);
              },
              stageDuration * step * 1000,
            );
          },
        );
      },
      { margin: '0px 0px -18% 0px' },
    );
  }
}
