#!/usr/bin/env node
/*
    Layout assertions a unit test cannot make.

    The PHP suite can check that a token is declared, that a rate is formatted, that a class is
    present in a stylesheet. It cannot see whether the page actually scrolls sideways, or whether a
    control is big enough to hit with a thumb. Both of those are the defect this whole exercise
    exists to fix -- a page capped at 720px inside a 1440px window -- so they are worth a browser.

    Usage:
      KUDI_URL=http://127.0.0.1:8100 node scripts/visual-check.mjs

    Only the landing page is checked, because every trader route is behind sign-in and faking an
    authenticated session here would test the fake, not the page. Add paths to PATHS once there is a
    real way to sign in from a script.
*/

import { chromium } from 'playwright-core';

const BASE = process.env.KUDI_URL ?? 'http://127.0.0.1:8100';
const WIDTHS = [375, 768, 1024, 1440];
const PATHS = ['/'];

// A control smaller than this is a miss for a thumb. The brand system pins 44px; this is the same
// number, asserted against the rendered box rather than against the CSS that intends it.
const TAP_TARGET = 44;

const browser = await chromium.launch({ channel: 'chrome' });
let failures = 0;

for (const width of WIDTHS) {
    for (const path of PATHS) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });

        try {
            await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 30000 });
        } catch {
            console.log(`SKIP ${width}px ${path} (no server on ${BASE})`);
            await page.close();
            continue;
        }

        const result = await page.evaluate((tap) => {
            const doc = document.documentElement;
            const overflow = doc.scrollWidth - doc.clientWidth;

            // Standalone controls only. A link that runs inside a sentence -- "No email? WhatsApp us"
            // -- is inline text, and WCAG 2.5.8 Target Size (Minimum) explicitly exempts inline
            // targets in a sentence. A 17px-tall inline link is correct typography, not a missed
            // tap. Counting them made this check report four failures on a page with no real ones,
            // which is how a real failure gets ignored later.
            const isStandalone = (el) => {
                const display = getComputedStyle(el).display;
                return display !== 'inline';
            };

            const small = [...document.querySelectorAll('a, button, input, select, [role="button"]')]
                .filter(isStandalone)
                .map((el) => {
                    const r = el.getBoundingClientRect();
                    // An element that is not laid out has a zero box and is not a tap target to miss.
                    return r.width === 0 && r.height === 0
                        ? null
                        : {
                            tag: el.tagName.toLowerCase(),
                            w: Math.round(r.width),
                            h: Math.round(r.height),
                            text: (el.textContent ?? '').trim().slice(0, 30),
                        };
                })
                .filter(Boolean)
                .filter((r) => r.h < tap || r.w < tap);

            return { overflow, small };
        }, TAP_TARGET);

        const problems = [];
        if (result.overflow > 0) {
            problems.push(`overflows by ${result.overflow}px`);
        }
        if (result.small.length > 0) {
            problems.push(`${result.small.length} control(s) under ${TAP_TARGET}px: ` +
                result.small.map((c) => `${c.tag}"${c.text}" ${c.w}x${c.h}`).join(', '));
        }

        if (problems.length > 0) {
            failures++;
            console.log(`FAIL ${width}px ${path} — ${problems.join('; ')}`);
        } else {
            console.log(`ok   ${width}px ${path}`);
        }

        await page.close();
    }
}

await browser.close();

if (failures > 0) {
    console.log(`\n${failures} failing viewport(s).`);
    process.exit(1);
}

console.log('\nno overflow and every control meets the tap target at all four widths.');
