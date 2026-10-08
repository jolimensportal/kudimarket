#!/usr/bin/env node
// Extract the brand decision documents into one plain-text packet for external review.
// Hand-written HTML, regular structure, no dependencies. Run: node scripts/make-review-packet.mjs
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const OUT = join(root, "review", "2026-10-06-claude-packet.md");

const DOCS = [
  ["brand/legal.html", "Document 1 of 5 — Regulatory brief and counsel questions"],
  ["brand/fiat-leg.html", "Document 2 of 5 — The fiat leg"],
  ["brand/oss-landscape.html", "Document 3 of 5 — Build or adopt: the open-source landscape"],
  ["brand/stack.html", "Document 4 of 5 — The system we build on"],
  ["brand/product.html", "Document 5 of 5 — The business, as decided"],
];

const decode = (text) =>
  text
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&middot;/g, "·")
    .replace(/&rsquo;/g, "’")
    .replace(/&hellip;/g, "…");

const inline = (text) =>
  decode(text)
    .replace(/<a\b[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/g, "$2 [$1]")
    .replace(/<em>/g, "*")
    .replace(/<\/em>/g, "*")
    .replace(/<strong>/g, "**")
    .replace(/<\/strong>/g, "**")
    .replace(/<br\s*\/?>/g, " ")
    .replace(/<[^>]+>/g, "")
    .replace(/[ \t]+/g, " ")
    .trim();

function convert(html) {
  let body = html
    .replace(/<style[\s\S]*?<\/style>/g, "")
    .replace(/<!--[\s\S]*?-->/g, "")
    .replace(/<(script|head)\b[\s\S]*?<\/\1>/g, "");

  const out = [];

  // Tables: one markdown table per <table>, rows collapsed onto single lines.
  body = body.replace(/<table>([\s\S]*?)<\/table>/g, (_, table) => {
    const rows = [];
    for (const row of table.matchAll(/<tr>([\s\S]*?)<\/tr>/g)) {
      const cells = [...row[1].matchAll(/<t[hd]\b[^>]*>([\s\S]*?)<\/t[hd]>/g)].map((cell) =>
        inline(cell[1]).replace(/\|/g, "/"),
      );
      rows.push(cells);
    }
    if (!rows.length) return "\n";
    const header = rows[0];
    const bodyRows = rows.slice(1);
    const lines = [
      `| ${header.join(" | ")} |`,
      `| ${header.map(() => "---").join(" | ")} |`,
      ...bodyRows.map((cells) => `| ${cells.join(" | ")} |`),
    ];
    return `\n\n${lines.join("\n")}\n\n`;
  });

  // Card headers become bold lines, list items become bullets with their marker kept.
  body = body
    .replace(/<div class="hd">([\s\S]*?)<\/div>/g, (_, text) => `\n\n**${inline(text)}**\n\n`)
    .replace(/<li\b[^>]*>\s*<i\b[^>]*>([\s\S]*?)<\/i>\s*<span>([\s\S]*?)<\/span>\s*<\/li>/g, (_, mark, text) => `\n- (${inline(mark)}) ${inline(text)}`)
    .replace(/<li\b[^>]*>([\s\S]*?)<\/li>/g, (_, text) => `\n- ${inline(text)}`)
    .replace(/<h1[^>]*>([\s\S]*?)<\/h1>/g, (_, text) => `\n# ${inline(text)}\n`)
    .replace(/<h2[^>]*>([\s\S]*?)<\/h2>/g, (_, text) => `\n## ${inline(text)}\n`);

  const text = decode(body)
    .replace(/<p[^>]*>/g, "\n\n")
    .replace(/<\/p>/g, "\n")
    .replace(/<span class="q">([\s\S]*?)<\/span>/g, "\n\n$1\n")
    .replace(/<div[^>]*>/g, "\n")
    .replace(/<\/div>/g, "\n")
    .replace(/<span class="k">([\s\S]*?)<\/span>/g, "\n\n**$1**\n")
    .replace(/<span[^>]*>/g, "")
    .replace(/<\/span>/g, "")
    .replace(/<nav[^>]*>[\s\S]*?<\/nav>/g, "")
    .replace(/<footer[^>]*>([\s\S]*?)<\/footer>/g, (_, text) => `\n\n---\n\n${inline(text)}\n`)
    .replace(/<[^>]+>/g, "")
    .replace(/\*\*\s*\*\*/g, "")
    .replace(/\n{3,}/g, "\n\n")
    .replace(/^\s+|\s+$/g, "");

  return text;
}

const BRIEF = `# KudiMarket — decision documents for review

Five documents, plain text, in the order they should be read. Every regulatory claim carries
its document, clause number and retrieval date. Where a fact could not be pinned to a primary
source, the document says so in those words.

## Verification note, please read first

The last review was checked clause by clause against a machine-assisted transcription of the SEC
guidelines. This version was then checked against the guidelines PDF itself, downloaded and
text-extracted directly (SEC/GUI/001/03/2026, 42 pages, 7 Oct 2026). Concretely:

- Clause numbers below were read off that extraction. Page numbers are deliberately not cited,
  because the transcription's pagination could not be relied on.
- Corrections from that pass are listed in the footer of Document 1, clause by clause, so you can
  see what moved rather than taking the current numbers on trust.
- One sourcing trap is flagged in Document 1's footer: a similarly titled 2025 virtual-assets
  statute with a licensing prohibition and an advertising ban belongs to a different jurisdiction.

## What changed since the last review

- Caps: the applicant-set user limits and transaction caps are 3(3)(b) in the eligibility
  section, not 4(3)(b); 4(3) is about containing the consequences of failure, and 4(2) only
  says who may apply. 9(1) and 9(2) are safeguards the SEC may impose, so Annex B is cited for
  the firmer ground. The Consumer Risk Statement is in Annex E even though 13(1) refers to an
  Annex D template, and the guidelines contradict themselves there.
- The custody model is an application requirement, not a later design choice: 3(3)(e)(i) and
  the Annex A-Addendum's independent third-party smart-contract audit. It now sits ahead of the
  ledger work.
- Pre-submission engagement with the SEC is mandatory (4(5)); the approval clock is ninety days
  from deemed completeness and lapse is not approval (6(1)); a licence lapses if business is not
  commenced within three months (11(a)); 3(3)(d) contemplates prior proof-of-concept testing.
- Capital adequacy, minimum paid-up capital and licence fees are relaxable inside the sandbox
  (Annex B), so "exchange-grade capital" overstates the sandbox burden; the bite is at exit.
- The register is rebuilt: twenty participants, six trading platforms (our likely category, five
  of them in the March cohort), five exchanges, five tokenisations, three brokerages, one
  real-world asset custodian.
- Annex D 2.1's category KPIs assume an order book: bid-ask spread ≤ 50bps, failed settlement
  ≤ 0.1%, and a suspicious-transaction target that reads literally as reporting every flagged
  transaction.
- The loss rule is a waterfall starting with the party that benefited, not a choice between three
  parties; release tiers gained step-down triggers and a cap on trade-size jumps between tiers.
- Four harms added to the fiat-leg model: tipping off versus transparency, stolen crypto in
  escrow and the omnibus-wallet freeze risk, money-mule structuring, and social engineering via
  fake support.
- Section 24(1) of the Data Protection Act permits retention required by law, so the claim that
  an append-only store cannot comply was overstated; the real conflict is AML record-keeping.

## What would be most useful to be told

- Any claim that still does not match the source cited beside it, especially clause numbers and
  quotations. If a clause reference is wrong, say which section of the PDF it sits in.
- Whether "the sandbox is the only route in" is adequately hedged. It is currently an inference
  drawn from the sandbox guidelines and a Bank of Ghana FAQ, because Act 1154's own
  licensing-prohibition section has not been obtained.
- Whether the waterfall is the right shape, and whether the tier rules close the farming route.
- Whether the custody-versus-contract decision is framed as an application prerequisite
  accurately enough, or whether it still hides a dependency.
- Anything in the harm catalogue that is still missing, given that it is built around traders
  deceiving each other, stolen funds, and social engineering rather than around the platform
  failing.
`;

const parts = [BRIEF, "---", ""];

for (const [file, heading] of DOCS) {
  parts.push(`# ${heading}`, "", "```", convert(readFileSync(join(root, file), "utf8")), "```", "", "---", "");
}

writeFileSync(OUT, `${parts.join("\n")}\n`);
console.log(`wrote ${OUT}`);