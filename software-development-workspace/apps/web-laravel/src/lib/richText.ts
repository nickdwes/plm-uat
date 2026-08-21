/**
 * Text that came out of somebody else's editor.
 *
 * Every project, task, opportunity and comment brought over from the old PLM
 * is HTML — it had a rich-text editor, and a good deal of what people put in
 * it was pasted from Outlook and Word. 8,722 of 9,055 task descriptions carry
 * markup, the longest of them 57KB.
 *
 * Two jobs, and they are deliberately different:
 *
 *  - `excerpt` for cards and lists, which want a sentence of plain text. A
 *    card is not a document, so even well-formed HTML has no business
 *    rendering there.
 *  - `safeHtml` for the places somebody reads the whole thing, where the
 *    lists and emphasis in a scope document genuinely carry meaning.
 */
import DOMPurify from "dompurify";

/**
 * Structure is kept, presentation is thrown away.
 *
 * This is the whole trick. A Word paste is perhaps a tenth content and the
 * rest inline styling — `font-variant-east-asian: normal` repeated down the
 * page — and keeping it would drag Calibri, hard-coded black text and 14.6667px
 * font sizes into a themed application, where they clash and ignore dark mode.
 *
 * Dropping every `style`, `class`, `face` and `color` leaves the paragraphs,
 * lists and emphasis somebody actually meant, rendered in our own typography.
 * It also removes an entire class of injection: no `style` attribute means no
 * `expression()`, no `url(javascript:)`, nothing positioned over the page.
 */
const ALLOWED_TAGS = [
  "p", "br", "hr",
  "strong", "b", "em", "i", "u", "s", "sub", "sup",
  "ul", "ol", "li",
  "blockquote", "pre", "code",
  "h1", "h2", "h3", "h4", "h5", "h6",
  "table", "thead", "tbody", "tr", "th", "td",
  "a", "span", "div",
];

/*
 * `href` only, and only on links — which is why `target` and `rel` are added
 * afterwards rather than allowed through: a sanitiser that permits `target`
 * permits it everywhere, and `rel` has to be ours to be worth anything.
 */
const ALLOWED_ATTR = ["href", "title"];

let configured = false;

function purifier(): typeof DOMPurify {
  if (!configured) {
    /*
     * Links open in a new tab and cannot reach back through `window.opener`.
     * Added by hook rather than by allowing the attributes through, so a link
     * in imported content cannot arrive with a `rel` of its own choosing.
     */
    DOMPurify.addHook("afterSanitizeAttributes", (node) => {
      if (node.tagName === "A" && node.getAttribute("href")) {
        node.setAttribute("target", "_blank");
        node.setAttribute("rel", "noopener noreferrer nofollow");
      }
    });

    configured = true;
  }

  return DOMPurify;
}

/** Content safe to hand to `v-html`. */
export function safeHtml(value: string | null | undefined): string {
  if (!value) return "";

  return purifier().sanitize(value, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    // Only http(s) and mailto survive; javascript: and data: do not.
    ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|tel:|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
    FORBID_ATTR: ["style", "class", "face", "color", "bgcolor", "width", "height"],
  });
}

/**
 * The same content as one line of plain text.
 *
 * Sanitised first rather than stripped with a regular expression: a regex over
 * angle brackets gets `<script>alert(1)</script>` wrong in ways that are only
 * funny until somebody pastes one, and it leaves `&nbsp;` and `&amp;` sitting
 * in the output where a reader sees them.
 */
export function excerpt(value: string | null | undefined, limit = 220): string {
  if (!value) return "";

  /*
   * A space where each block ended, before the tags go.
   *
   * Stripping `<p>Khamis,</p><p>Kindly advise</p>` without this yields
   * "Khamis,Kindly advise" — two words welded together at every paragraph
   * break, which is most of what a pasted email is made of.
   */
  const spaced = value.replace(/<\/(p|div|li|tr|h[1-6]|blockquote|pre)\s*>/gi, " $& ")
    .replace(/<br\s*\/?>/gi, " ");

  const clean = purifier().sanitize(spaced, { ALLOWED_TAGS: [], ALLOWED_ATTR: [] });

  // The browser decodes the entities; nothing else reliably does.
  const el = document.createElement("textarea");
  el.innerHTML = clean;

  const text = (el.value || "").replace(/\s+/g, " ").trim();

  return text.length > limit ? `${text.slice(0, limit).trimEnd()}…` : text;
}

/**
 * The reply, and the conversation it quotes.
 *
 * An email carries every message before it. A ticket with nine replies shows
 * the first one nine times, and the newest sentence — the only part anybody
 * opened the ticket to read — sits at the top of a page of history that gets
 * longer with every exchange. The desk's longest message is 60KB and almost
 * none of it is new.
 *
 * Split on the markers mail clients leave when they quote, in the order a
 * client is likely to have used one. Nothing is thrown away: the history is
 * folded, not removed, because "what did we tell them in March" is a question
 * the thread is kept to answer.
 *
 * Deliberately conservative. Where no marker is found the whole message is
 * treated as new, because showing a little too much is a nuisance and hiding
 * something somebody wrote is a fault.
 */
const QUOTE_MARKERS: RegExp[] = [
  // Outlook inserts these around the quoted block, in this order of certainty.
  /<div\b[^>]*id=["']?divRplyFwdMsg/i,
  /<div\b[^>]*id=["']?appendonsend/i,
  /-{2,}\s*Original Message\s*-{2,}/i,
  /<blockquote/i,
  // A reply header: From, then Sent or Date, within a few hundred characters.
  /<hr[^>]*>\s*(?=(?:<[^>]+>\s*)*<b>\s*From:)/i,
  /(?=<b>\s*From:\s*<\/b>[\s\S]{0,400}?<b>\s*(?:Sent|Date):)/i,
];

export interface SplitMessage {
  /** What this person wrote. */
  latest: string;
  /** Everything they quoted, or empty when they quoted nothing. */
  quoted: string;
}

export function splitQuoted(value: string | null | undefined): SplitMessage {
  const html = safeHtml(value);

  if (!html) return { latest: "", quoted: "" };

  for (const marker of QUOTE_MARKERS) {
    const found = html.search(marker);

    /*
     * A marker at the very start means the whole message is quoted — a bare
     * forward with no covering note. Folding all of it would leave an empty
     * message, so it is left alone.
     */
    if (found > 0) {
      const latest = html.slice(0, found);

      // Nothing but tags before the marker is not a reply, it is a wrapper.
      if (hasText(latest)) {
        return { latest, quoted: html.slice(found) };
      }
    }
  }

  return { latest: html, quoted: "" };
}

/** True when there is something to show once the markup is gone. */
export function hasText(value: string | null | undefined): boolean {
  return excerpt(value, 1).length > 0;
}
