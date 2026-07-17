import { animate, inView, stagger } from 'motion';

const reduceMotion = window.matchMedia(
  '(prefers-reduced-motion: reduce)',
).matches;
const stageDurations = [2200, 2600, 3000] as const;
const initialDelay = 600;
const transitionAllowance = 320;
const easing = [0.22, 1, 0.36, 1] as const;

document
  .querySelectorAll<HTMLElement>('[data-hero-workflow]')
  .forEach((workflow) => {
    const panels = Array.from(
      workflow.querySelectorAll<HTMLElement>('[data-workflow-panel]'),
    );
    const controls = Array.from(
      workflow.querySelectorAll<HTMLButtonElement>('[data-workflow-control]'),
    );
    const playback = workflow.querySelector<HTMLButtonElement>(
      '[data-workflow-playback]',
    );
    const currentLabel = workflow.querySelector<HTMLElement>(
      '[data-workflow-current-label]',
    );
    const liveStatus = workflow.querySelector<HTMLElement>(
      '[data-workflow-status]',
    );

    if (
      panels.length !== 4 ||
      controls.length !== panels.length ||
      !playback ||
      !currentLabel ||
      !liveStatus
    ) {
      return;
    }

    let currentIndex = 0;
    let transitionToken = 0;
    let sequenceStarted = false;
    let autoplayActive = false;
    let advanceTimer: number | null = null;
    let advanceDeadline = 0;
    let remainingDelay = 0;
    let lastInteractionWasKeyboard = false;
    const pauseReasons = new Set<'focus' | 'hidden' | 'hover' | 'user'>();

    const updatePlayback = () => {
      if (reduceMotion) {
        playback.hidden = true;
        return;
      }

      playback.hidden = false;

      if (!autoplayActive) {
        playback.textContent = 'Replay';
        playback.setAttribute('aria-label', 'Replay workflow animation');
        return;
      }

      if (pauseReasons.has('focus') || pauseReasons.has('user')) {
        playback.textContent = 'Play';
        playback.setAttribute('aria-label', 'Play workflow animation');
        return;
      }

      playback.textContent = 'Pause';
      playback.setAttribute('aria-label', 'Pause workflow animation');
    };

    const updateStageState = (index: number, announce: boolean) => {
      controls.forEach((control, controlIndex) => {
        control.setAttribute('aria-pressed', String(controlIndex === index));
      });

      currentLabel.textContent =
        controls[index]?.dataset.stageTitle ?? 'Household workflow';

      if (announce) {
        const stageName = controls[index]?.dataset.stageLabel ?? 'Stage';
        liveStatus.textContent = `${stageName} selected.`;
      }
    };

    const animatePanelContents = (panel: HTMLElement, startDelay = 0.08) => {
      const items = panel.querySelectorAll<HTMLElement>('[data-workflow-item]');
      const progress = panel.querySelector<HTMLElement>(
        '[data-workflow-progress]',
      );

      if (items.length > 0) {
        animate(
          items,
          {
            opacity: [0.25, 1],
            transform: ['translateY(8px)', 'translateY(0)'],
          },
          {
            delay: stagger(0.07, { startDelay }),
            duration: 0.42,
            ease: easing,
          },
        );
      }

      if (progress) {
        animate(
          progress,
          { transform: ['scaleX(0)', 'scaleX(1)'] },
          { delay: startDelay + 0.18, duration: 0.7, ease: easing },
        );
      }
    };

    const showStage = async (
      index: number,
      options: { announce: boolean; transition: boolean; revealDelay?: number },
    ) => {
      const nextPanel = panels[index];
      const previousPanel = panels[currentIndex];

      if (!nextPanel || !previousPanel) {
        return;
      }

      const token = ++transitionToken;
      currentIndex = index;
      updateStageState(index, options.announce);

      if (previousPanel === nextPanel) {
        panels.forEach((panel, panelIndex) => {
          panel.hidden = panelIndex !== index;
          panel.style.opacity = '';
          panel.style.transform = '';
        });

        if (options.transition && !reduceMotion) {
          animatePanelContents(nextPanel, options.revealDelay ?? 0.08);
        }

        return;
      }

      if (!options.transition || reduceMotion) {
        panels.forEach((panel, panelIndex) => {
          panel.hidden = panelIndex !== index;
          panel.style.opacity = '';
          panel.style.transform = '';
        });
        return;
      }

      const exitAnimation = animate(
        previousPanel,
        {
          opacity: [1, 0],
          transform: ['translateX(0)', 'translateX(-8px)'],
        },
        { duration: 0.16, ease: easing },
      );

      await exitAnimation.finished;

      if (token !== transitionToken) {
        previousPanel.hidden = true;
        return;
      }

      previousPanel.hidden = true;
      nextPanel.hidden = false;

      animate(
        nextPanel,
        {
          opacity: [0, 1],
          transform: ['translateX(8px)', 'translateX(0)'],
        },
        { duration: 0.32, ease: easing },
      );
      animatePanelContents(nextPanel, options.revealDelay ?? 0.08);
    };

    const clearAdvanceTimer = (preserveRemaining: boolean) => {
      if (advanceTimer === null) {
        return;
      }

      if (preserveRemaining) {
        remainingDelay = Math.max(0, advanceDeadline - performance.now());
      }

      window.clearTimeout(advanceTimer);
      advanceTimer = null;
    };

    const finishAutoplay = () => {
      clearAdvanceTimer(false);
      autoplayActive = false;
      remainingDelay = 0;
      pauseReasons.clear();
      updatePlayback();
    };

    const advance = () => {
      advanceTimer = null;

      if (!autoplayActive || pauseReasons.size > 0) {
        return;
      }

      const nextIndex = currentIndex + 1;

      if (nextIndex >= panels.length) {
        finishAutoplay();
        return;
      }

      void showStage(nextIndex, { announce: false, transition: true });

      if (nextIndex === panels.length - 1) {
        finishAutoplay();
        return;
      }

      remainingDelay = (stageDurations[nextIndex] ?? 0) + transitionAllowance;
      advanceDeadline = performance.now() + remainingDelay;
      advanceTimer = window.setTimeout(advance, remainingDelay);
    };

    const armAdvanceTimer = () => {
      if (!autoplayActive || pauseReasons.size > 0 || advanceTimer !== null) {
        updatePlayback();
        return;
      }

      advanceDeadline = performance.now() + remainingDelay;
      advanceTimer = window.setTimeout(advance, remainingDelay);
      updatePlayback();
    };

    const pause = (reason: 'focus' | 'hidden' | 'hover' | 'user') => {
      if (!autoplayActive || pauseReasons.has(reason)) {
        return;
      }

      clearAdvanceTimer(true);
      pauseReasons.add(reason);
      updatePlayback();
    };

    const resume = (reason: 'focus' | 'hidden' | 'hover' | 'user') => {
      pauseReasons.delete(reason);
      armAdvanceTimer();
    };

    const startAutoplay = () => {
      clearAdvanceTimer(false);
      pauseReasons.clear();
      autoplayActive = true;
      remainingDelay = initialDelay + stageDurations[0];

      if (workflow.matches(':hover')) {
        pauseReasons.add('hover');
      }

      if (
        lastInteractionWasKeyboard &&
        workflow.contains(document.activeElement)
      ) {
        pauseReasons.add('focus');
      }

      void showStage(0, {
        announce: false,
        transition: true,
        revealDelay: initialDelay / 1000,
      });
      armAdvanceTimer();
    };

    const stopForManualControl = () => {
      clearAdvanceTimer(false);
      autoplayActive = false;
      remainingDelay = 0;
      pauseReasons.clear();
      updatePlayback();
    };

    controls.forEach((control, index) => {
      control.addEventListener('click', () => {
        stopForManualControl();
        void showStage(index, {
          announce: true,
          transition: !reduceMotion,
        });
      });
    });

    playback.addEventListener('click', () => {
      if (reduceMotion) {
        return;
      }

      if (!autoplayActive) {
        startAutoplay();
        return;
      }

      if (pauseReasons.has('focus') || pauseReasons.has('user')) {
        pauseReasons.delete('focus');
        pauseReasons.delete('user');
        armAdvanceTimer();
        return;
      }

      pause('user');
    });

    workflow.addEventListener('pointerenter', () => pause('hover'));
    workflow.addEventListener('pointerleave', () => resume('hover'));
    document.addEventListener('keydown', () => {
      lastInteractionWasKeyboard = true;

      if (workflow.contains(document.activeElement)) {
        pause('focus');
      }
    });
    document.addEventListener('pointerdown', () => {
      lastInteractionWasKeyboard = false;
    });
    workflow.addEventListener('focusin', (event) => {
      if (lastInteractionWasKeyboard && event.target instanceof HTMLElement) {
        pause('focus');
      }
    });

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        pause('hidden');
      } else {
        resume('hidden');
      }
    });

    if (reduceMotion) {
      void showStage(1, { announce: false, transition: false });
      updatePlayback();
      return;
    }

    inView(
      workflow,
      () => {
        if (sequenceStarted) {
          return;
        }

        sequenceStarted = true;
        startAutoplay();
      },
      { margin: '0px 0px -8% 0px' },
    );
  });
