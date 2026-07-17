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

  const memoryProof = document.querySelector<HTMLElement>(
    '[data-memory-proof]',
  );
  const memoryTruths = memoryProof?.querySelectorAll('[data-memory-truth]');
  const memoryConnector = memoryProof?.querySelector('[data-memory-connector]');
  const memoryOutput = memoryProof?.querySelector('[data-memory-output]');

  if (memoryProof && memoryTruths?.length && memoryConnector && memoryOutput) {
    let hasExplainedMemory = false;

    inView(
      memoryProof,
      () => {
        if (hasExplainedMemory) return;
        hasExplainedMemory = true;

        animate(
          memoryTruths,
          {
            opacity: [0.4, 1],
            transform: ['translateX(-8px)', 'translateX(0)'],
          },
          {
            delay: stagger(0.1, { startDelay: 0.12 }),
            duration: 0.45,
            ease: [0.22, 1, 0.36, 1],
          },
        );

        animate(
          memoryConnector,
          { opacity: [0, 1] },
          { delay: 0.48, duration: 0.35 },
        );

        animate(
          memoryOutput,
          {
            opacity: [0, 1],
            transform: ['translateY(10px)', 'translateY(0)'],
          },
          {
            delay: 0.62,
            duration: 0.5,
            ease: [0.22, 1, 0.36, 1],
          },
        );
      },
      { margin: '0px 0px -14% 0px' },
    );
  }

  const decisionCheck = document.querySelector<HTMLElement>(
    '[data-decision-check]',
  );
  const decisionCheckPath = decisionCheck?.querySelector<SVGPathElement>(
    '[data-decision-check-path]',
  );

  if (decisionCheck && decisionCheckPath) {
    let hasChecked = false;

    inView(
      decisionCheck,
      () => {
        if (hasChecked) return;
        hasChecked = true;

        animate(
          decisionCheck,
          {
            backgroundColor: ['rgba(255, 255, 255, 0)', '#ffffff'],
            transform: ['scale(1)', 'scale(0.9)', 'scale(1)'],
          },
          {
            delay: 1.15,
            duration: 0.45,
            ease: [0.22, 1, 0.36, 1],
          },
        );

        window.setTimeout(() => {
          decisionCheck.classList.add('is-checked');
        }, 1320);
      },
      { margin: '0px 0px -18% 0px' },
    );
  }
}

const stageSequence = document.querySelector<HTMLElement>(
  '[data-stage-sequence]',
);
const stageList =
  stageSequence?.querySelector<HTMLOListElement>('[data-stage-list]');
const stageProgress = stageSequence?.querySelector<HTMLElement>(
  '[data-stage-progress]',
);
const stageStatus = stageSequence?.querySelector<HTMLElement>(
  '[data-stage-status]',
);
const stagePrompt = document.querySelector<HTMLElement>('[data-stage-prompt]');
const stageCheckboxes = stageSequence
  ? Array.from(
      stageSequence.querySelectorAll<HTMLInputElement>('[data-stage-checkbox]'),
    )
  : [];

if (stageList && stageProgress && stageCheckboxes.length) {
  const stageItems = Array.from(
    stageList.querySelectorAll<HTMLElement>('[data-stage-index]'),
  );

  stageCheckboxes.forEach((checkbox, index) => {
    checkbox.addEventListener('change', () => {
      if (!checkbox.checked) {
        checkbox.checked = true;
        return;
      }

      const item = stageItems[index];
      const nextItem = stageItems[index + 1];
      const nextCheckbox = stageCheckboxes[index + 1];
      const progress = (index + 1) / stageCheckboxes.length;

      checkbox.disabled = true;
      item.dataset.stageState = 'complete';

      if (nextItem && nextCheckbox) {
        nextItem.dataset.stageState = 'active';
        nextCheckbox.disabled = false;
        stageList.dataset.activeStep = String(index + 1);

        if (stageStatus) {
          const nextName = nextItem.querySelector('.stage-label')?.textContent;
          stageStatus.textContent = `${nextName?.trim() ?? 'Next step'} is ready to complete.`;
        }

        if (stagePrompt) {
          stagePrompt.textContent = `Check ${nextItem.dataset.stageName ?? 'the next step'} to keep moving.`;
        }
      } else {
        stageList.dataset.complete = 'true';
        if (stageStatus) {
          stageStatus.textContent =
            'The week is complete, from the first plan to tonight’s meal.';
        }
        if (stagePrompt) {
          stagePrompt.textContent =
            'All four steps complete — your week is ready.';
        }
      }

      if (prefersReducedMotion) {
        stageProgress.style.transform = `scaleX(${progress})`;
      } else {
        animate(
          stageProgress,
          { transform: `scaleX(${progress})` },
          { duration: 0.45, ease: [0.22, 1, 0.36, 1] },
        );

        animate(
          item,
          { backgroundColor: ['#fff7f3', '#ffffff'] },
          { duration: 0.55, ease: [0.22, 1, 0.36, 1] },
        );
      }
    });
  });
}

const trolleyDemo = document.querySelector<HTMLElement>('[data-trolley-demo]');
const retailerHeading = trolleyDemo?.querySelector<HTMLElement>(
  '[data-retailer-heading]',
);
const retailerAccount = trolleyDemo?.querySelector<HTMLElement>(
  '[data-retailer-account]',
);
const retailerButtons = trolleyDemo
  ? Array.from(
      trolleyDemo.querySelectorAll<HTMLButtonElement>('[data-retailer-button]'),
    )
  : [];
const trolleyState = trolleyDemo?.querySelector<HTMLElement>(
  '[data-trolley-state]',
);
const trolleyStatus = trolleyDemo?.querySelector<HTMLElement>(
  '[data-trolley-status]',
);
const trolleySteps = trolleyDemo
  ? Array.from(trolleyDemo.querySelectorAll<HTMLElement>('[data-trolley-step]'))
  : [];
const trolleyReviewToggle = trolleyDemo?.querySelector<HTMLButtonElement>(
  '[data-trolley-review-toggle]',
);
const trolleyReviewDetail = trolleyDemo?.querySelector<HTMLElement>(
  '[data-trolley-review-detail]',
);

if (
  trolleyDemo &&
  retailerHeading &&
  retailerAccount &&
  retailerButtons.length
) {
  const setRetailer = (retailer: string) => {
    retailerButtons.forEach((button) => {
      button.setAttribute(
        'aria-pressed',
        String(button.dataset.retailer === retailer),
      );
    });

    retailerHeading.textContent = `Preparing your ${retailer} trolley`;
    retailerAccount.textContent = `Your ${retailer} account keeps its points and rewards.`;

    if (trolleyStatus) {
      trolleyStatus.textContent = `${retailer} trolley preview selected.`;
    }

    if (!prefersReducedMotion) {
      animate(
        [retailerHeading, retailerAccount],
        { opacity: [0.35, 1] },
        { duration: 0.3, ease: [0.22, 1, 0.36, 1] },
      );
    }
  };

  retailerButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const retailer = button.dataset.retailer;
      if (!retailer || button.getAttribute('aria-pressed') === 'true') return;
      setRetailer(retailer);
    });
  });
}

if (
  trolleyDemo &&
  trolleyState &&
  trolleySteps.length &&
  !prefersReducedMotion
) {
  let hasPreparedTrolley = false;

  inView(
    trolleyDemo,
    () => {
      if (hasPreparedTrolley) return;
      hasPreparedTrolley = true;

      trolleyState.dataset.state = 'preparing';
      trolleyState.textContent = 'Preparing trolley';
      if (trolleyStatus) {
        trolleyStatus.textContent = 'Chef is preparing the example trolley.';
      }

      animate(
        trolleySteps,
        {
          opacity: [0.3, 1],
          transform: ['translateY(8px)', 'translateY(0)'],
        },
        {
          delay: stagger(0.42, { startDelay: 0.2 }),
          duration: 0.48,
          ease: [0.22, 1, 0.36, 1],
        },
      );

      window.setTimeout(() => {
        delete trolleyState.dataset.state;
        trolleyState.textContent = 'Ready for review';
        if (trolleyStatus) {
          trolleyStatus.textContent =
            'Trolley prepared. One substitution needs your review.';
        }

        animate(
          trolleyState,
          { transform: ['scale(0.94)', 'scale(1)'] },
          { duration: 0.32, ease: [0.22, 1, 0.36, 1] },
        );
      }, 1700);
    },
    { margin: '0px 0px -14% 0px' },
  );
}

if (trolleyReviewToggle && trolleyReviewDetail) {
  trolleyReviewToggle.addEventListener('click', () => {
    const willOpen =
      trolleyReviewToggle.getAttribute('aria-expanded') !== 'true';
    trolleyReviewToggle.setAttribute('aria-expanded', String(willOpen));
    trolleyReviewDetail.hidden = !willOpen;

    if (trolleyStatus) {
      trolleyStatus.textContent = willOpen
        ? 'Substitution details opened. Chef paused because the closest match has a different pack size.'
        : 'Substitution details closed.';
    }

    if (willOpen && !prefersReducedMotion) {
      animate(
        trolleyReviewDetail,
        {
          opacity: [0, 1],
          transform: ['translateY(-6px)', 'translateY(0)'],
        },
        { duration: 0.3, ease: [0.22, 1, 0.36, 1] },
      );
    }
  });
}

const shoppingCard = document.querySelector<HTMLElement>('.shopping-card');
const shoppingList = shoppingCard?.querySelector<HTMLElement>(
  '[data-shopping-list]',
);
const shoppingForm = shoppingCard?.querySelector<HTMLFormElement>(
  '[data-shopping-form]',
);
const shoppingInput = shoppingCard?.querySelector<HTMLInputElement>(
  '[data-shopping-input]',
);
const shoppingTotal = shoppingCard?.querySelector<HTMLElement>(
  '[data-shopping-total]',
);
const shoppingComplete = shoppingCard?.querySelector<HTMLElement>(
  '[data-shopping-complete]',
);
const shoppingStatus = shoppingCard?.querySelector<HTMLElement>(
  '[data-shopping-status]',
);

if (
  shoppingCard &&
  shoppingList &&
  shoppingForm &&
  shoppingInput &&
  shoppingTotal &&
  shoppingComplete
) {
  let addedItemIndex = 0;

  const updateShoppingCount = () => {
    const checked = shoppingList.querySelectorAll<HTMLInputElement>(
      '[data-shopping-checkbox]:checked',
    ).length;
    shoppingComplete.textContent = String(checked);
  };

  const bindShoppingCheckbox = (checkbox: HTMLInputElement) => {
    checkbox.addEventListener('change', () => {
      updateShoppingCount();

      const row = checkbox.closest<HTMLElement>('[data-shopping-row]');
      const itemName = row?.querySelector('strong')?.textContent?.trim();
      if (shoppingStatus && itemName) {
        shoppingStatus.textContent = checkbox.checked
          ? `${itemName} checked off.`
          : `${itemName} returned to the list.`;
      }

      if (!prefersReducedMotion && row) {
        animate(
          row,
          { transform: ['scale(0.99)', 'scale(1)'] },
          { duration: 0.24, ease: [0.22, 1, 0.36, 1] },
        );
      }
    });
  };

  shoppingList
    .querySelectorAll<HTMLInputElement>('[data-shopping-checkbox]')
    .forEach(bindShoppingCheckbox);

  shoppingForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const itemName = shoppingInput.value.trim();
    if (!itemName) {
      shoppingInput.focus();
      return;
    }

    const sourceRow = shoppingList.querySelector<HTMLElement>(
      '[data-shopping-row]',
    );
    if (!sourceRow) return;

    addedItemIndex += 1;
    const itemId = `shopping-item-added-${addedItemIndex}`;
    const row = sourceRow.cloneNode(true) as HTMLElement;
    const checkbox = row.querySelector<HTMLInputElement>(
      '[data-shopping-checkbox]',
    );
    const checkLabel = row.querySelector<HTMLLabelElement>('.shopping-check');
    const itemLabel = row.querySelector<HTMLLabelElement>(
      '.shopping-item-copy',
    );
    const name = row.querySelector<HTMLElement>('strong');
    const source = row.querySelector<HTMLElement>('small');
    const meta = row.querySelector<HTMLElement>('em');

    if (!checkbox || !checkLabel || !itemLabel || !name || !source || !meta) {
      return;
    }

    row.classList.remove('shopping-row--sage', 'shopping-row--oat');
    row.classList.add('shopping-row--new');
    checkLabel.classList.remove('shopping-check--sage', 'shopping-check--oat');
    checkbox.id = itemId;
    checkbox.checked = false;
    checkbox.setAttribute('aria-label', `Mark ${itemName} complete`);
    checkLabel.htmlFor = itemId;
    itemLabel.htmlFor = itemId;
    name.textContent = itemName;
    source.textContent = 'Household item · added just now';
    meta.textContent = 'Added manually';

    bindShoppingCheckbox(checkbox);
    shoppingList.append(row);

    const currentTotal = Number.parseInt(shoppingTotal.textContent ?? '18', 10);
    shoppingTotal.textContent = String(currentTotal + 1);
    shoppingForm.reset();
    shoppingInput.focus();

    if (shoppingStatus) {
      shoppingStatus.textContent = `${itemName} added to the shopping list.`;
    }

    if (!prefersReducedMotion) {
      animate(
        row,
        {
          opacity: [0, 1],
          transform: ['translateY(10px)', 'translateY(0)'],
        },
        { duration: 0.4, ease: [0.22, 1, 0.36, 1] },
      );
    }

    shoppingList.scrollTo({
      behavior: prefersReducedMotion ? 'auto' : 'smooth',
      top: shoppingList.scrollHeight,
    });
  });
}
