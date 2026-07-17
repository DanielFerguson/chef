import { animate, inView } from 'motion';

type PauseReason = 'focus' | 'hidden' | 'hover' | 'user';
type MealState = 'pending' | 'accepted' | 'rejected' | 'replaced';
type TrolleyState = 'matching' | 'complete' | 'attention';
type MotionControl = {
  cancel: () => void;
  pause: () => void;
  play: () => void;
  finished: Promise<unknown>;
};
type StageCue = { at: number; run: () => void };

const reduceMotion = window.matchMedia(
  '(prefers-reduced-motion: reduce)',
).matches;
const stageDurations = [5200, 8200, 5800] as const;
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
    const planThinking = workflow.querySelector<HTMLElement>(
      '[data-plan-thinking]',
    );
    const planResponse = workflow.querySelector<HTMLElement>(
      '[data-plan-response]',
    );
    const planContext = Array.from(
      workflow.querySelectorAll<HTMLElement>('[data-plan-context]'),
    );
    const planContextList = workflow.querySelector<HTMLElement>(
      '[data-plan-context-list]',
    );
    const mealsSummary = workflow.querySelector<HTMLElement>(
      '[data-meals-summary]',
    );
    const mealsSummaryDetail = workflow.querySelector<HTMLElement>(
      '[data-meals-summary-detail]',
    );
    const mealRows = Array.from(
      workflow.querySelectorAll<HTMLElement>('[data-meal-row]'),
    );
    const trolleyTitle = workflow.querySelector<HTMLElement>(
      '[data-trolley-title]',
    );
    const trolleyCount = workflow.querySelector<HTMLElement>(
      '[data-trolley-count]',
    );
    const trolleyProgress = panels[2]?.querySelector<HTMLElement>(
      '[data-workflow-progress]',
    );
    const trolleyRows = Array.from(
      workflow.querySelectorAll<HTMLElement>('[data-trolley-row]'),
    );
    const trolleyCheckout = workflow.querySelector<HTMLElement>(
      '[data-trolley-checkout]',
    );
    const cookingStep = workflow.querySelector<HTMLElement>(
      '[data-cooking-step]',
    );
    const cookingProgress = workflow.querySelector<HTMLElement>(
      '[data-cooking-progress]',
    );
    const cookingCurrent = workflow.querySelector<HTMLElement>(
      '[data-cooking-current]',
    );
    const cookingState = workflow.querySelector<HTMLElement>(
      '[data-cooking-state]',
    );
    const cookingInstruction = workflow.querySelector<HTMLElement>(
      '[data-cooking-instruction]',
    );
    const cookingInstructionText = workflow.querySelector<HTMLElement>(
      '[data-cooking-instruction-text]',
    );
    const cookingStrike = workflow.querySelector<HTMLElement>(
      '[data-cooking-strike]',
    );
    const cookingComplete = workflow.querySelector<HTMLElement>(
      '[data-cooking-complete]',
    );
    const cookingAction = workflow.querySelector<HTMLElement>(
      '[data-cooking-action]',
    );
    const timer = workflow.querySelector<HTMLElement>('[data-workflow-timer]');

    if (
      panels.length !== 4 ||
      controls.length !== panels.length ||
      mealRows.length !== 5 ||
      trolleyRows.length !== 3 ||
      planContext.length !== 3 ||
      !playback ||
      !currentLabel ||
      !liveStatus ||
      !planThinking ||
      !planResponse ||
      !planContextList ||
      !mealsSummary ||
      !mealsSummaryDetail ||
      !trolleyTitle ||
      !trolleyCount ||
      !trolleyProgress ||
      !trolleyCheckout ||
      !cookingStep ||
      !cookingProgress ||
      !cookingCurrent ||
      !cookingState ||
      !cookingInstruction ||
      !cookingInstructionText ||
      !cookingStrike ||
      !cookingComplete ||
      !cookingAction ||
      !timer
    ) {
      return;
    }

    let currentIndex = 0;
    let transitionToken = 0;
    let autoplayRun = 0;
    let sequenceStarted = false;
    let autoplayActive = false;
    let transitioning = false;
    let workflowVisible = false;
    let lastInteractionWasKeyboard = false;
    let stageTimelineActive = false;
    let stageElapsed = 0;
    let currentCueIndex = 0;
    let currentCues: StageCue[] = [];
    let timerActive = false;
    let timerSeconds = 480;
    let timerCarry = 0;
    let timerLastFrame: number | null = null;
    let lastFrame = performance.now();
    const pauseReasons = new Set<PauseReason>();
    const activeAnimations = new Set<MotionControl>();

    const isTimelinePaused = () =>
      document.hidden ||
      !workflowVisible ||
      (autoplayActive && pauseReasons.size > 0);

    const trackAnimation = (control: MotionControl) => {
      activeAnimations.add(control);

      if (isTimelinePaused()) {
        control.pause();
      }

      void control.finished
        .catch(() => undefined)
        .finally(() => activeAnimations.delete(control));

      return control;
    };

    const cancelAnimations = () => {
      const animations = Array.from(activeAnimations);
      activeAnimations.clear();
      animations.forEach((control) => control.cancel());
    };

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

    const syncPausedState = () => {
      const paused = isTimelinePaused();
      workflow.dataset.workflowPaused = String(paused);

      activeAnimations.forEach((control) => {
        if (paused) {
          control.pause();
        } else {
          control.play();
        }
      });

      updatePlayback();
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

    const clearMotionStyles = (element: HTMLElement) => {
      element.style.opacity = '';
      element.style.transform = '';
      element.style.backgroundColor = '';
    };

    const revealElement = (element: HTMLElement) => {
      element.hidden = false;
      element.dataset.sequenceVisible = 'true';
      element.setAttribute('aria-hidden', 'false');
      clearMotionStyles(element);

      if (!reduceMotion) {
        trackAnimation(
          animate(
            element,
            {
              opacity: [0, 1],
              transform: ['translateY(8px)', 'translateY(0)'],
            },
            { duration: 0.38, ease: easing },
          ),
        );
      }
    };

    const setSequenceVisible = (element: HTMLElement, visible: boolean) => {
      element.dataset.sequenceVisible = String(visible);
      element.setAttribute('aria-hidden', String(!visible));
      clearMotionStyles(element);
    };

    const animateStateChange = (element: HTMLElement) => {
      clearMotionStyles(element);

      if (!reduceMotion) {
        trackAnimation(
          animate(
            element,
            {
              opacity: [0.35, 1],
              transform: ['translateY(6px)', 'translateY(0)'],
            },
            { duration: 0.32, ease: easing },
          ),
        );
      }
    };

    const setMealStatus = (
      row: HTMLElement,
      state: MealState,
      animateChange = false,
    ) => {
      const status = row.querySelector<HTMLElement>('[data-meal-status]');
      const label = row.querySelector<HTMLElement>('[data-meal-status-label]');

      if (!status || !label) {
        return;
      }

      status.dataset.state = state;
      label.textContent =
        state === 'accepted'
          ? (row.dataset.approvedLabel ?? 'Approved')
          : state === 'rejected'
            ? (row.dataset.rejectedLabel ?? 'Rejected')
            : state === 'replaced'
              ? (row.dataset.replacedLabel ?? 'Replaced')
              : 'Checking';

      if (animateChange) {
        animateStateChange(status);
      } else {
        clearMotionStyles(status);
      }
    };

    const setMealCopy = (
      row: HTMLElement,
      title: string,
      meta: string,
      animateChange = false,
    ) => {
      const titleElement = row.querySelector<HTMLElement>('[data-meal-title]');
      const metaElement = row.querySelector<HTMLElement>('[data-meal-meta]');

      if (!titleElement || !metaElement) {
        return;
      }

      titleElement.textContent = title;
      metaElement.textContent = meta;

      if (animateChange) {
        animateStateChange(titleElement);
        animateStateChange(metaElement);
      } else {
        clearMotionStyles(titleElement);
        clearMotionStyles(metaElement);
      }
    };

    const resetPlan = (complete: boolean) => {
      planThinking.hidden = true;
      planResponse.hidden = !complete;
      planThinking.setAttribute('aria-hidden', 'true');
      planResponse.setAttribute('aria-hidden', String(!complete));
      planContextList.hidden = !complete;
      clearMotionStyles(planThinking);
      clearMotionStyles(planResponse);
      clearMotionStyles(planContextList);

      planContext.forEach((item) => setSequenceVisible(item, complete));
    };

    const resetMeals = (complete: boolean) => {
      mealsSummary.textContent = complete
        ? '5 dinners set'
        : 'Reviewing five dinners';
      mealsSummaryDetail.textContent = complete
        ? 'Review before confirming'
        : 'Checking household fit';

      mealRows.forEach((row) => {
        setMealCopy(
          row,
          complete
            ? (row.dataset.finalTitle ?? '')
            : (row.dataset.initialTitle ?? ''),
          complete
            ? (row.dataset.finalMeta ?? '')
            : (row.dataset.initialMeta ?? ''),
        );
        setMealStatus(row, complete ? 'accepted' : 'pending');
        setSequenceVisible(row, complete);
      });
    };

    const setTrolleyRow = (
      row: HTMLElement,
      state: TrolleyState,
      animateChange = false,
    ) => {
      const title = row.querySelector<HTMLElement>('[data-trolley-row-title]');
      const detail = row.querySelector<HTMLElement>(
        '[data-trolley-row-detail]',
      );

      if (!title || !detail) {
        return;
      }

      row.dataset.state = state;
      title.textContent =
        state === 'matching'
          ? (row.dataset.initialTitle ?? '')
          : (row.dataset.finalTitle ?? '');
      detail.textContent =
        state === 'matching'
          ? (row.dataset.initialDetail ?? '')
          : (row.dataset.finalDetail ?? '');

      if (animateChange) {
        animateStateChange(row);
      } else {
        clearMotionStyles(row);
      }
    };

    const setTrolleyProgress = (value: number, animateChange = false) => {
      if (!animateChange || reduceMotion) {
        trolleyProgress.style.transform = `scaleX(${value})`;
        return;
      }

      trackAnimation(
        animate(
          trolleyProgress,
          { transform: `scaleX(${value})` },
          { duration: 0.7, ease: easing },
        ),
      );
    };

    const resetTrolley = (complete: boolean) => {
      trolleyTitle.textContent = complete
        ? 'Ready for review'
        : 'Preparing your trolley';
      trolleyCount.textContent = complete ? '24 items' : 'Matching…';
      trolleyRows.forEach((row, index) => {
        setTrolleyRow(
          row,
          complete ? (index === 2 ? 'attention' : 'complete') : 'matching',
        );
      });
      setTrolleyProgress(complete ? 1 : 0);
      trolleyCheckout.hidden = !complete;
      clearMotionStyles(trolleyCheckout);
    };

    const renderTimer = () => {
      const minutes = Math.floor(timerSeconds / 60);
      const seconds = timerSeconds % 60;
      timer.textContent = `${String(minutes).padStart(2, '0')}:${String(
        seconds,
      ).padStart(2, '0')}`;
      timer.setAttribute(
        'aria-label',
        `${minutes} minute${minutes === 1 ? '' : 's'} and ${seconds} second${
          seconds === 1 ? '' : 's'
        } remaining`,
      );
    };

    const resetTonightTimer = () => {
      timerActive = false;
      timerSeconds = 480;
      timerCarry = 0;
      timerLastFrame = null;
      renderTimer();
    };

    const startTonightTimer = () => {
      resetTonightTimer();

      if (!reduceMotion) {
        timerActive = true;
      }
    };

    const cookingSteps = [
      {
        label: 'Step 2 of 5',
        progress: 0.4,
        instruction: cookingInstruction.dataset.stepTwo ?? '',
      },
      {
        label: 'Step 3 of 5',
        progress: 0.6,
        instruction: cookingInstruction.dataset.stepThree ?? '',
      },
      {
        label: 'Step 4 of 5',
        progress: 0.8,
        instruction: cookingInstruction.dataset.stepFour ?? '',
      },
    ] as const;

    const setCookingStep = (index: number, animateChange = false) => {
      const step = cookingSteps[index];

      if (!step) {
        return;
      }

      cookingStep.textContent = step.label;
      cookingInstructionText.textContent = step.instruction;
      cookingStrike.textContent = step.instruction;
      cookingStrike.style.clipPath = 'inset(0 100% 0 0)';
      cookingCurrent.dataset.state = 'current';
      cookingState.textContent = 'Current step';
      cookingComplete.hidden = true;
      cookingAction.dataset.state = 'current';
      cookingAction.innerHTML = 'Next step <i aria-hidden="true">→</i>';

      if (animateChange && !reduceMotion) {
        trackAnimation(
          animate(
            cookingProgress,
            { transform: `scaleX(${step.progress})` },
            { duration: 0.65, ease: easing },
          ),
        );
        animateStateChange(cookingStep);
        animateStateChange(cookingInstruction);
      } else {
        cookingProgress.style.transform = `scaleX(${step.progress})`;
        clearMotionStyles(cookingStep);
        clearMotionStyles(cookingInstruction);
      }
    };

    const completeCookingStep = () => {
      cookingCurrent.dataset.state = 'complete';
      cookingState.textContent = 'Step complete';
      cookingComplete.hidden = false;
      cookingAction.dataset.state = 'complete';
      cookingAction.innerHTML = 'Complete <i aria-hidden="true">✓</i>';
      trackAnimation(
        animate(
          cookingStrike,
          {
            clipPath: ['inset(0 100% 0 0)', 'inset(0 0% 0 0)'],
          },
          { duration: 0.85, ease: [0, 0, 1, 1] },
        ),
      );
      animateStateChange(cookingComplete);
    };

    const resetTonight = (complete: boolean) => {
      resetTonightTimer();
      setCookingStep(complete ? 2 : 0);
    };

    const resetStage = (index: number, complete: boolean) => {
      if (index === 0) resetPlan(complete);
      if (index === 1) resetMeals(complete);
      if (index === 2) resetTrolley(complete);
      if (index === 3) resetTonight(complete);
    };

    const resetAllStages = () => {
      panels.forEach((_, index) => resetStage(index, false));
    };

    const completeMeal = (index: number) => {
      const row = mealRows[index];
      if (row) setMealStatus(row, 'accepted', true);
    };

    const completeTrolleyRow = (
      index: number,
      state: TrolleyState,
      progress: number,
    ) => {
      const row = trolleyRows[index];
      if (row) setTrolleyRow(row, state, true);
      setTrolleyProgress(progress, true);
    };

    const stageCues: StageCue[][] = [
      [
        { at: 900, run: () => revealElement(planThinking) },
        {
          at: 2400,
          run: () => {
            planThinking.hidden = true;
            planThinking.setAttribute('aria-hidden', 'true');
            revealElement(planResponse);
          },
        },
        {
          at: 2900,
          run: () => {
            revealElement(planContextList);
            revealElement(planContext[0]);
          },
        },
        { at: 3200, run: () => revealElement(planContext[1]) },
        { at: 3500, run: () => revealElement(planContext[2]) },
      ],
      [
        { at: 120, run: () => revealElement(mealRows[0]) },
        { at: 300, run: () => revealElement(mealRows[1]) },
        { at: 480, run: () => revealElement(mealRows[2]) },
        { at: 660, run: () => revealElement(mealRows[3]) },
        { at: 840, run: () => revealElement(mealRows[4]) },
        { at: 1500, run: () => completeMeal(0) },
        { at: 2300, run: () => completeMeal(1) },
        { at: 3100, run: () => completeMeal(2) },
        {
          at: 4000,
          run: () => {
            const row = mealRows[3];
            if (!row) return;
            setMealCopy(
              row,
              row.dataset.initialTitle ?? 'Mushroom risotto',
              row.dataset.rejectedMeta ?? '',
              true,
            );
            setMealStatus(row, 'rejected', true);
          },
        },
        {
          at: 5500,
          run: () => {
            const row = mealRows[3];
            if (!row) return;
            setMealCopy(
              row,
              row.dataset.finalTitle ?? 'Quick beef rice bowls',
              row.dataset.finalMeta ?? '4 people · 20m',
              true,
            );
            setMealStatus(row, 'replaced', true);
          },
        },
        { at: 6500, run: () => completeMeal(3) },
        { at: 7100, run: () => completeMeal(4) },
        {
          at: 7400,
          run: () => {
            mealsSummary.textContent = '5 dinners set';
            mealsSummaryDetail.textContent = 'Review before confirming';
            animateStateChange(mealsSummary.parentElement ?? mealsSummary);
          },
        },
      ],
      [
        { at: 1200, run: () => completeTrolleyRow(0, 'complete', 0.58) },
        { at: 2500, run: () => completeTrolleyRow(1, 'complete', 0.86) },
        {
          at: 3800,
          run: () => {
            completeTrolleyRow(2, 'attention', 1);
            trolleyTitle.textContent = 'Ready for review';
            trolleyCount.textContent = '24 items';
            animateStateChange(trolleyTitle);
            animateStateChange(trolleyCount);
            revealElement(trolleyCheckout);
          },
        },
      ],
      [
        { at: 3000, run: completeCookingStep },
        { at: 4000, run: () => setCookingStep(1, true) },
        { at: 7000, run: completeCookingStep },
        { at: 8000, run: () => setCookingStep(2, true) },
      ],
    ];

    const beginStage = (index: number, runSequence: boolean) => {
      resetStage(index, !runSequence);
      stageElapsed = 0;
      currentCueIndex = 0;
      currentCues = stageCues[index] ?? [];
      stageTimelineActive = runSequence && currentCues.length > 0;

      if (index === 3) {
        startTonightTimer();
      }
    };

    const showStage = async (
      index: number,
      options: {
        announce: boolean;
        transition: boolean;
        runSequence: boolean;
      },
    ) => {
      const nextPanel = panels[index];
      const visiblePanel = panels.find((panel) => !panel.hidden) ?? panels[0];

      if (!nextPanel || !visiblePanel) {
        return false;
      }

      const token = ++transitionToken;
      cancelAnimations();
      stageTimelineActive = false;

      if (currentIndex === 3 && index !== 3) {
        resetTonightTimer();
      }

      currentIndex = index;
      updateStageState(index, options.announce);

      if (!options.transition || reduceMotion || visiblePanel === nextPanel) {
        panels.forEach((panel, panelIndex) => {
          panel.hidden = panelIndex !== index;
          clearMotionStyles(panel);
        });
        beginStage(index, options.runSequence && !reduceMotion);
        return true;
      }

      const exitAnimation = trackAnimation(
        animate(
          visiblePanel,
          {
            opacity: [1, 0],
            transform: ['translateX(0)', 'translateX(-8px)'],
          },
          { duration: 0.16, ease: easing },
        ),
      );

      await exitAnimation.finished.catch(() => undefined);

      if (token !== transitionToken) {
        return false;
      }

      visiblePanel.hidden = true;
      clearMotionStyles(visiblePanel);
      nextPanel.hidden = false;
      resetStage(index, false);

      const enterAnimation = trackAnimation(
        animate(
          nextPanel,
          {
            opacity: [0, 1],
            transform: ['translateX(8px)', 'translateX(0)'],
          },
          { duration: 0.32, ease: easing },
        ),
      );

      await enterAnimation.finished.catch(() => undefined);

      if (token !== transitionToken) {
        return false;
      }

      clearMotionStyles(nextPanel);
      beginStage(index, options.runSequence);
      return true;
    };

    const finishAutoplay = () => {
      autoplayActive = false;
      pauseReasons.clear();
      syncPausedState();
    };

    const advanceStage = async () => {
      if (transitioning || !autoplayActive || currentIndex >= 3) {
        return;
      }

      transitioning = true;
      stageTimelineActive = false;
      const run = autoplayRun;
      const nextIndex = currentIndex + 1;
      const shown = await showStage(nextIndex, {
        announce: false,
        transition: true,
        runSequence: true,
      });
      transitioning = false;

      if (!shown || !autoplayActive || run !== autoplayRun) {
        return;
      }

      if (nextIndex === 3) {
        finishAutoplay();
      }
    };

    const startAutoplay = () => {
      autoplayRun += 1;
      transitionToken += 1;
      transitioning = false;
      cancelAnimations();
      resetAllStages();
      pauseReasons.clear();
      autoplayActive = true;

      if (workflow.matches(':hover')) {
        pauseReasons.add('hover');
      }

      if (
        lastInteractionWasKeyboard &&
        workflow.contains(document.activeElement)
      ) {
        pauseReasons.add('focus');
      }

      syncPausedState();
      void showStage(0, {
        announce: false,
        transition: true,
        runSequence: true,
      });
    };

    const stopForManualControl = () => {
      autoplayRun += 1;
      transitionToken += 1;
      transitioning = false;
      autoplayActive = false;
      stageTimelineActive = false;
      pauseReasons.clear();
      cancelAnimations();
      syncPausedState();
    };

    const pause = (reason: PauseReason) => {
      if (!autoplayActive || pauseReasons.has(reason)) {
        return;
      }

      pauseReasons.add(reason);
      syncPausedState();
    };

    const resume = (reason: PauseReason) => {
      pauseReasons.delete(reason);
      syncPausedState();
    };

    controls.forEach((control, index) => {
      control.addEventListener('click', () => {
        stopForManualControl();
        void showStage(index, {
          announce: true,
          transition: !reduceMotion,
          runSequence: !reduceMotion,
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
        syncPausedState();
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
        pauseReasons.add('hidden');
      } else {
        pauseReasons.delete('hidden');
        timerLastFrame = null;
      }

      syncPausedState();
    });

    const updateTimer = (now: number) => {
      if (
        !timerActive ||
        timerSeconds <= 0 ||
        currentIndex !== 3 ||
        !workflowVisible ||
        document.hidden
      ) {
        timerLastFrame = null;
        return;
      }

      if (timerLastFrame === null) {
        timerLastFrame = now;
        return;
      }

      timerCarry += now - timerLastFrame;
      timerLastFrame = now;

      if (timerCarry < 1000) {
        return;
      }

      const elapsedSeconds = Math.floor(timerCarry / 1000);
      timerCarry -= elapsedSeconds * 1000;
      timerSeconds = Math.max(0, timerSeconds - elapsedSeconds);
      renderTimer();
    };

    const tick = (now: number) => {
      const delta = Math.min(100, Math.max(0, now - lastFrame));
      lastFrame = now;

      if (stageTimelineActive && !transitioning && !isTimelinePaused()) {
        stageElapsed += delta;

        while (
          currentCueIndex < currentCues.length &&
          stageElapsed >= (currentCues[currentCueIndex]?.at ?? Infinity)
        ) {
          currentCues[currentCueIndex]?.run();
          currentCueIndex += 1;
        }

        const duration = stageDurations[currentIndex];
        if (duration !== undefined && stageElapsed >= duration) {
          if (autoplayActive) {
            void advanceStage();
          } else {
            stageTimelineActive = false;
          }
        } else if (
          duration === undefined &&
          currentCueIndex >= currentCues.length
        ) {
          stageTimelineActive = false;
        }
      }

      updateTimer(now);
      window.requestAnimationFrame(tick);
    };

    resetAllStages();
    window.requestAnimationFrame(tick);

    if (reduceMotion) {
      panels.forEach((panel, index) => {
        panel.hidden = index !== 1;
      });
      currentIndex = 1;
      updateStageState(1, false);
      resetStage(1, true);
      syncPausedState();
      return;
    }

    inView(
      workflow,
      () => {
        workflowVisible = true;
        timerLastFrame = null;

        if (!sequenceStarted) {
          sequenceStarted = true;
          startAutoplay();
        }

        return () => {
          workflowVisible = false;
          timerLastFrame = null;
        };
      },
      { margin: '0px 0px -8% 0px' },
    );
  });
