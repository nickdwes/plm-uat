import { describe, expect, it } from "vitest";
import { excerpt, hasText, safeHtml } from "./richText";

/*
 * Loaded through Vite rather than the filesystem, so the same import works in
 * the test run and the type check — reading them with node:fs meant the build
 * needed Node types it has no other use for.
 */
import worstFixture from "./__fixtures__/worst-description.html?raw";
import wordPasteFixture from "./__fixtures__/word-paste.html?raw";

/**
 * The sanitiser between imported content and the page.
 *
 * 46,977 comments and 8,722 task descriptions came out of a system that took
 * whatever Outlook and Word pasted into it. Most of what follows is about what
 * must not survive that journey.
 */
describe("safeHtml", () => {
  // ── What must not get through ──────────────────────────────────────────────

  it("drops a script tag and its contents", () => {
    const out = safeHtml('<p>Hello</p><script>alert(1)</script>');

    expect(out).toContain("Hello");
    expect(out).not.toContain("script");
    expect(out).not.toContain("alert");
  });

  it("drops event handlers", () => {
    const out = safeHtml('<p onclick="steal()">Click</p><img src=x onerror="steal()">');

    expect(out).not.toContain("onclick");
    expect(out).not.toContain("onerror");
    expect(out).not.toContain("steal");
  });

  it("refuses a javascript: link but keeps the text", () => {
    const out = safeHtml('<a href="javascript:alert(1)">Report</a>');

    expect(out).toContain("Report");
    expect(out).not.toContain("javascript:");
  });

  /**
   * Style is the attribute a Word paste is made of, and the one that lets
   * content position itself over the rest of the page.
   */
  it("strips every style attribute", () => {
    const out = safeHtml(
      '<p style="position:fixed;top:0;left:0;width:100vw;height:100vh">Overlay</p>',
    );

    expect(out).toContain("Overlay");
    expect(out).not.toContain("style");
    expect(out).not.toContain("position");
  });

  it("removes an iframe outright", () => {
    expect(safeHtml('<iframe src="https://elsewhere.test"></iframe>')).not.toContain("iframe");
  });

  // ── What must survive ──────────────────────────────────────────────────────

  /** The structure in a scope document is the part worth keeping. */
  it("keeps paragraphs, lists and emphasis", () => {
    const out = safeHtml(
      "<p>Scope includes:</p><ul><li>Installation of a <strong>modular DC</strong></li>"
      + "<li>Civil works</li></ul>",
    );

    expect(out).toContain("<p>Scope includes:</p>");
    expect(out).toContain("<li>");
    expect(out).toContain("<strong>modular DC</strong>");
  });

  /**
   * A real Word paste: a tenth content, the rest inline styling.
   *
   * This is the shape that made one project card a thousand pixels tall.
   */
  it("turns a word paste into plain structure", () => {
    const pasted =
      '<p class="p1" style="margin-right: 0px; font-variant-east-asian: normal; '
      + 'font-family: \'times new roman\'; font-size: 14.6667px">'
      + '<span style="color: rgb(68,68,68)">TENDER NO. MICDE/1/2024</span></p>';

    const out = safeHtml(pasted);

    expect(out).toContain("TENDER NO. MICDE/1/2024");
    expect(out).not.toContain("font-variant-east-asian");
    expect(out).not.toContain("rgb(68,68,68)");
    expect(out).not.toContain("class=");
  });

  /** Links go out to a new tab and cannot reach back through window.opener. */
  it("hardens the links it keeps", () => {
    const out = safeHtml('<a href="https://example.test/tender">The tender</a>');

    expect(out).toContain('href="https://example.test/tender"');
    expect(out).toContain('rel="noopener noreferrer nofollow"');
    expect(out).toContain('target="_blank"');
  });

  it("has nothing to say about nothing", () => {
    expect(safeHtml(null)).toBe("");
    expect(safeHtml(undefined)).toBe("");
    expect(safeHtml("")).toBe("");
  });
});

describe("excerpt", () => {
  it("reads as a sentence, not as markup", () => {
    const out = excerpt("<p>UNOC are looking to set up SDWAN across their regional branches</p>");

    expect(out).toBe("UNOC are looking to set up SDWAN across their regional branches");
  });

  it("decodes the entities a reader would otherwise see", () => {
    expect(excerpt("<p>Design&nbsp;&amp;&nbsp;Build</p>")).toBe("Design & Build");
  });

  it("collapses the whitespace a pasted document arrives with", () => {
    expect(excerpt("<div>Line one</div>\n\n   <div>Line   two</div>")).toBe("Line one Line two");
  });

  /**
   * Paragraphs are word boundaries.
   *
   * Without a space where each block ended, a forwarded email reads
   * "Khamis,Kindly advise" — welded together at every break.
   */
  it("does not weld one paragraph onto the next", () => {
    expect(excerpt("<p>Khamis,</p><p>Kindly advise on the below</p>"))
      .toBe("Khamis, Kindly advise on the below");
    expect(excerpt("<ul><li>One</li><li>Two</li></ul>")).toBe("One Two");
    expect(excerpt("Line one<br>Line two")).toBe("Line one Line two");
  });

  /** A card gets a sentence however long the description is. */
  it("cuts a long description down and says it did", () => {
    const out = excerpt(`<p>${"word ".repeat(400)}</p>`, 60);

    expect(out.length).toBeLessThanOrEqual(61);
    expect(out.endsWith("…")).toBe(true);
  });

  /** Script contents must not reappear as text once the tags are gone. */
  it("does not leak the body of a script into the excerpt", () => {
    expect(excerpt("<p>Fine</p><script>alert(1)</script>")).toBe("Fine");
  });
});

describe("hasText", () => {
  /**
   * Markup that says nothing should not open an empty block on screen.
   *
   * The old editor left plenty of it — a paragraph containing one non-breaking
   * space is a common way for a description to be "not empty" and yet blank.
   */
  it("sees through markup that carries no words", () => {
    expect(hasText("<p></p>")).toBe(false);
    expect(hasText("<p>&nbsp;</p>")).toBe(false);
    expect(hasText("<div><br></div>")).toBe(false);
    expect(hasText(null)).toBe(false);
    expect(hasText("<p>Something</p>")).toBe(true);
  });
});

/**
 * The two worst things actually in the database.
 *
 * Held as fixtures rather than described, because the value of these is that
 * nobody made them up: one is the 57KB task description that would have made a
 * card taller than the screen, the other the Word paste that broke the
 * projects grid.
 */
describe("against the real imported content", () => {
  const worst = worstFixture;
  const wordPaste = wordPasteFixture;

  it("reduces the longest description in the system to a card line", () => {
    const out = excerpt(worst);

    expect(worst.length).toBeGreaterThan(50_000);
    expect(out.length).toBeLessThanOrEqual(221);

    /*
     * No tags — but an angle bracket on its own is fine and expected. This
     * description is a forwarded email thread, so it reads "Elizabeth Maina
     * <lisa@kns.co.ke>", and that came through as `&lt;` and was decoded. A
     * reader should see it.
     */
    expect(out).not.toMatch(/<\/?[a-z][a-z0-9]*(\s[^>]*)?\/?>/i);
  });

  it("keeps the Word paste readable and throws its styling away", () => {
    const out = safeHtml(wordPaste);

    expect(out).toContain("MICDE");
    expect(out).not.toContain("font-variant-east-asian");
    expect(out).not.toContain("style=");
    // Far smaller than it arrived: the styling was most of it.
    expect(out.length).toBeLessThan(wordPaste.length / 2);
  });

  it("finds no script, style or event handler left in either", () => {
    for (const source of [worst, wordPaste]) {
      const out = safeHtml(source);

      expect(out).not.toMatch(/<script/i);
      expect(out).not.toMatch(/<style/i);
      expect(out).not.toMatch(/\son\w+=/i);
      expect(out).not.toMatch(/javascript:/i);
    }
  });
});
