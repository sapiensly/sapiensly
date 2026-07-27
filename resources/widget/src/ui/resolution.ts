import type { AppearanceConfig } from '../types';

/**
 * The "did this answer your question?" prompt.
 *
 * Two buttons rather than a star rating, and inline under the answer rather than
 * in a modal, because the point is the ANSWER RATE. `is_resolved` is the only
 * input to the owner's resolution metric and nothing was ever setting it — the
 * feedback endpoint existed, the client method existed, and no UI ever called
 * them. A five-star modal that 3% of visitors fill in would have left the metric
 * just as unusable.
 */
export function buildResolutionPrompt(
    appearance: AppearanceConfig,
    onAnswer: (resolved: boolean) => void,
): HTMLDivElement {
    const wrapper = document.createElement('div');
    wrapper.className = 'sapiensly-resolution';
    wrapper.setAttribute('data-resolution', 'true');

    const question = document.createElement('p');
    question.className = 'sapiensly-resolution-q';
    question.textContent =
        appearance.resolution_prompt ?? 'Did this answer your question?';
    wrapper.appendChild(question);

    const actions = document.createElement('div');
    actions.className = 'sapiensly-resolution-actions';

    const answer = (resolved: boolean, label: string) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sapiensly-resolution-btn';
        button.textContent = label;
        button.addEventListener('click', () => {
            // Replaced rather than disabled: the question is answered once, and
            // leaving dead buttons behind invites a second click that would
            // overwrite the first answer.
            wrapper.innerHTML = '';
            const thanks = document.createElement('p');
            thanks.className = 'sapiensly-resolution-q';
            thanks.textContent =
                appearance.resolution_thanks ?? 'Thanks for telling us.';
            wrapper.appendChild(thanks);

            onAnswer(resolved);
        });

        return button;
    };

    actions.appendChild(
        answer(true, appearance.resolution_yes ?? 'Yes, thanks'),
    );
    actions.appendChild(
        answer(false, appearance.resolution_no ?? 'Not really'),
    );
    wrapper.appendChild(actions);

    return wrapper;
}
