import { describe, expect, it } from 'vitest';
import { switchedUrl } from './previewSwitch';

/**
 * The url arithmetic behind entering and leaving a pretence.
 *
 * Small enough to look correct and wrong in one place that matters: "no
 * pretence" has to REMOVE the parameter, not set it empty, or every link the
 * page then renders carries `?as_role=` and the reader can never get back to
 * a clean url by clicking.
 */
const at = '/r/campo/ordenes';

describe('switching into a pretence', () => {
    it('puts the parameter on the url the reader is already on', () => {
        expect(switchedUrl(`https://x.test${at}`, 'as_role', 'tecnico')).toBe(
            `https://x.test${at}?as_role=tecnico`,
        );
    });

    it('keeps whatever else the url was carrying', () => {
        // A filtered, sorted, paginated list is where somebody realises they
        // want to see it as somebody else. Losing the query loses the question.
        expect(
            switchedUrl(
                `https://x.test${at}?estado=abierta&page=3`,
                'env',
                'demo',
            ),
        ).toBe(`https://x.test${at}?estado=abierta&page=3&env=demo`);
    });

    it('replaces a pretence rather than stacking a second one', () => {
        expect(
            switchedUrl(
                `https://x.test${at}?as_role=admin`,
                'as_role',
                'tecnico',
            ),
        ).toBe(`https://x.test${at}?as_role=tecnico`);
    });
});

describe('leaving one', () => {
    it('removes the parameter instead of emptying it', () => {
        // `?as_role=` would ride along on every link the page renders next.
        expect(
            switchedUrl(`https://x.test${at}?as_role=tecnico`, 'as_role', null),
        ).toBe(`https://x.test${at}`);
    });

    it('treats the empty string as leaving, because a <select> says it that way', () => {
        expect(
            switchedUrl(`https://x.test${at}?as_role=tecnico`, 'as_role', ''),
        ).toBe(`https://x.test${at}`);
    });

    it('leaves the rest of the query alone on the way out', () => {
        expect(
            switchedUrl(
                `https://x.test${at}?estado=abierta&as_role=tecnico`,
                'as_role',
                null,
            ),
        ).toBe(`https://x.test${at}?estado=abierta`);
    });
});
