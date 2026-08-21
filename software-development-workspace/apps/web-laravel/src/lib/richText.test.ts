import { describe, expect, it } from "vitest";
import { excerpt, hasText, safeHtml, splitQuoted } from "./richText";

/*
 * Loaded through Vite rather than the filesystem, so the same import works in
 * the test run and the type check — reading them with node:fs meant the build
 * needed Node types it has no other use for.
 */
import worstFixture from "./__fixtures__/worst-description.html?raw";
import wordPasteFixture from "./__fixtures__/word-paste.html?raw";
import outlookFixture from "./__fixtures__/outlook-email.html?raw";

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

  /**
   * A real 56KB email off the service desk.
   *
   * Two thousand six hundred and nineteen of the desk's messages are HTML and
   * thirteen hundred carry a whole Outlook stylesheet. Escaped, that put
   * `@font-face {font-family:Calibri}` on screen where the customer's sentence
   * should have been.
   */
  it("reduces an Outlook email to what somebody wrote", () => {
    const out = safeHtml(outlookFixture);

    expect(outlookFixture).toContain("@font-face");
    expect(outlookFixture).toContain("MsoNormal");

    // The stylesheet has to go, and its *contents* with it — a removed tag
    // whose text survives just moves the CSS from markup into prose.
    expect(out).not.toContain("@font-face");
    expect(out).not.toContain("font-family:Calibri");
    expect(out).not.toContain("MsoNormal");
    expect(out).not.toMatch(/<style/i);

    // And what somebody actually wrote survives, structure included.
    expect(out).toContain("Hello Scovia");
    expect(out).toMatch(/<li>/);
  });

  /**
   * What is left is the conversation, not the styling.
   *
   * Worth stating, because the saving is smaller than it looks: a 56KB email
   * loses its stylesheet and is still 33KB, and nearly all of that is the
   * quoted history of everyone who has replied. The markup was the unreadable
   * part; the length is a separate problem.
   */
  it("throws the styling away and keeps the conversation", () => {
    const out = safeHtml(outlookFixture);

    expect(out.length).toBeLessThan(outlookFixture.length);
    expect(out).not.toMatch(/\{[^}]*font-family/);
    expect(out).not.toContain("mso-");
  });

  it("gives the desk a readable one-line preview of an email", () => {
    const line = excerpt(outlookFixture, 60);

    expect(line.length).toBeLessThanOrEqual(61);
    expect(line).not.toContain("@font-face");
    expect(line).not.toContain("{");
  });

  it("finds no script, style or event handler left in either", () => {
    for (const source of [worst, wordPaste, outlookFixture]) {
      const out = safeHtml(source);

      expect(out).not.toMatch(/<script/i);
      expect(out).not.toMatch(/<style/i);
      expect(out).not.toMatch(/\son\w+=/i);
      expect(out).not.toMatch(/javascript:/i);
    }
  });
});

/**
 * Separating what somebody wrote from what they quoted.
 *
 * An email carries every message before it, so a ticket with nine replies
 * shows the first one nine times. The newest sentence — the only part anybody
 * opened the ticket to read — ends up at the top of a page of history.
 */
describe("splitQuoted", () => {
  it("keeps the reply and folds what it quotes", () => {
    const { latest, quoted } = splitQuoted(
      "<p>Kindly confirm the subnets.</p>"
      + '<div id="divRplyFwdMsg"><b>From:</b> Gabriel<br><b>Sent:</b> Wednesday</div>'
      + "<p>The original question.</p>",
    );

    expect(latest).toContain("Kindly confirm the subnets.");
    expect(latest).not.toContain("The original question.");
    expect(quoted).toContain("The original question.");
  });

  it("splits on a reply header with no wrapper around it", () => {
    const { latest, quoted } = splitQuoted(
      "<p>Please see below.</p><b>From:</b> Ann<br><b>Sent:</b> Monday<br><p>Earlier.</p>",
    );

    expect(latest).toContain("Please see below.");
    expect(quoted).toContain("Earlier.");
  });

  it("splits on the old-style original message rule", () => {
    const { latest, quoted } = splitQuoted(
      "<p>Agreed.</p>-----Original Message-----<p>The first note.</p>",
    );

    expect(latest).toContain("Agreed.");
    expect(quoted).toContain("The first note.");
  });

  /** A first message quotes nothing, and must not be cut anywhere. */
  it("leaves a message that quotes nothing whole", () => {
    const { latest, quoted } = splitQuoted("<p>We have a fault on the link.</p>");

    expect(latest).toContain("We have a fault on the link.");
    expect(quoted).toBe("");
  });

  /**
   * A bare forward is all history and no note.
   *
   * Folding every word of it would leave a message showing nothing at all,
   * which reads as a bug rather than as a fold.
   */
  it("does not fold away a message that is entirely quoted", () => {
    const { latest, quoted } = splitQuoted(
      '<div id="divRplyFwdMsg"><b>From:</b> Ann</div><p>Forwarded content.</p>',
    );

    expect(latest).toContain("Forwarded content.");
    expect(quoted).toBe("");
  });

  /** And it sanitises on the way, so neither half can carry a script. */
  it("sanitises both halves", () => {
    const { latest, quoted } = splitQuoted(
      "<p onclick=\"x()\">Hello</p><blockquote><script>alert(1)</script>Old</blockquote>",
    );

    expect(latest).not.toContain("onclick");
    expect(quoted).not.toContain("script");
  });

  /**
   * The real email off the desk turns out to be all history.
   *
   * It opens with a couple of empty divs, a rule, and "From: … Sent: …" —
   * somebody forwarded a conversation without writing a word above it. The
   * marker is there, at the very start, and folding on it would leave a
   * message showing nothing. So it is kept whole, and the length is left to
   * the screen to deal with.
   */
  it("keeps a forwarded chain whole rather than folding it to nothing", () => {
    const { latest, quoted } = splitQuoted(outlookFixture);

    expect(quoted).toBe("");
    expect(latest).toContain("Hello Scovia");
  });
});
