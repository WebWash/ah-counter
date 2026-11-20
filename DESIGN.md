---
name: Ah Counter
description: Tally Sheet. Fillers counted the way a person counts them, in words and tally marks, on a white form with blue print and a yellow highlighter.
colors:
  form-blue: "#1f4fa0"
  form-blue-dark: "#173d7d"
  blue-tint: "#edf2fa"
  rule: "#d5deee"
  highlighter: "#ffe066"
  highlighter-edge: "#c9a400"
  paper: "#fff"
  ink: "#15181d"
  soft: "#545b66"
  wave-unplayed: "#b8c6e0"
  danger: "#b3261e"
  danger-tint: "#fbeeed"
typography:
  display:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "56px"
    fontWeight: 800
    lineHeight: 1.08
    letterSpacing: "-0.025em"
    fontFeature: "\"tnum\""
  headline:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "44px"
    fontWeight: 800
    lineHeight: 1.12
    letterSpacing: "-0.025em"
  headline-state:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "32px"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  facts:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "22px"
    fontWeight: 400
    lineHeight: 1.4
  transcript:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "21px"
    fontWeight: 400
    lineHeight: 2
  title:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "20px"
    fontWeight: 800
    lineHeight: 1.3
  section-heading:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 800
    lineHeight: 1.4
  body:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "17px"
    fontWeight: 400
    lineHeight: 1.5
    fontFeature: "\"tnum\""
  label:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 600
    lineHeight: 1.5
rounded:
  mark: "3px"
  control: "6px"
  panel: "8px"
spacing:
  xs: "6px"
  sm: "12px"
  md: "24px"
  lg: "40px"
  gutter: "64px"
  gap: "72px"
  side: "500px"
components:
  button-primary:
    backgroundColor: "{colors.form-blue}"
    textColor: "{colors.paper}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "0 22px"
    height: "48px"
  button-primary-hover:
    backgroundColor: "{colors.form-blue-dark}"
  button-open:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.form-blue}"
    rounded: "{rounded.control}"
    padding: "0 22px"
    height: "48px"
  button-open-hover:
    backgroundColor: "{colors.blue-tint}"
  button-big:
    backgroundColor: "{colors.form-blue}"
    textColor: "{colors.paper}"
    rounded: "{rounded.control}"
    padding: "0 32px"
    height: "64px"
  button-stop:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    rounded: "{rounded.control}"
  button-danger:
    backgroundColor: "{colors.danger}"
    textColor: "{colors.paper}"
    rounded: "{rounded.control}"
  button-bar:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.form-blue}"
    rounded: "{rounded.control}"
    padding: "0 18px"
    height: "40px"
  top-bar:
    backgroundColor: "{colors.form-blue}"
    textColor: "{colors.paper}"
    padding: "0 64px"
    height: "60px"
  field:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "10px 14px"
    height: "48px"
  notice:
    backgroundColor: "{colors.blue-tint}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "12px 16px"
  notice-alert:
    backgroundColor: "{colors.danger-tint}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "12px 16px"
  filler-sound:
    backgroundColor: "{colors.highlighter}"
    textColor: "{colors.ink}"
    rounded: "{rounded.mark}"
    padding: "2px 4px"
  filler-phrase:
    textColor: "{colors.ink}"
    padding: "2px 1px"
  candidate-not-counted:
    textColor: "{colors.soft}"
---

# Design System: Ah Counter

## Overview

**Creative North Star: "The Tally Sheet"**

Ah Counter is set as the sheet the Ah Counter at a club meeting would keep: a white form printed in one blue, filled in with ink, with the Fillers marked in yellow highlighter. The count is said as a sentence ("9 Fillers in 54 seconds") and drawn as tally marks, four strokes and a fifth across them, never as a stat tile. Everything the speaker needs to read is written in words; the marks only repeat what the words already say.

The page is a two-column form. What the page says sits in a 500px left column (the heading, the count, the tallies, Coaching); the work sits on the right (Listen, the Transcript, the Filler list, the next action). Section headings are blue words on a 2px blue rule, like the printed headings of a paper form. There are no cards, no shadows and no tinted panels around content; structure comes from rules, type weight and whitespace.

One typeface, Public Sans, sets everything, with tabular numbers throughout so times and counts line up. Weight carries the hierarchy: 800 for anything the eye should land on, 400 for reading, 600 for small labels. The highlighter is the only warm colour on the page and belongs to Fillers alone.

**Key Characteristics:**
- White paper, form-blue print (bar, section rules, buttons, tally marks), ink text, yellow highlighter on Fillers only.
- Counts said in words and drawn as tally marks gated in fives, never in charts or stat tiles.
- Kinds and statuses are always told apart by shape, icon and word, never by colour alone.
- Flat: rules and type do the structuring; no shadows, no cards.
- Public Sans only, tabular numerals, heavy 800 for emphasis.

## Colors

One print colour, one highlighter, ink on white, and a red kept for failure and destruction.

### Primary
- **Form Blue** (form-blue): The printed colour of the sheet. The 60px top bar, every section heading and its 2px rule, primary buttons, tally strokes in the By word list, time links, the played part of the waveform, the recorder's clock fill, the focus ring (3px outline, 3px offset) and the native accent colour.
- **Form Blue Dark** (form-blue-dark): Hover for primary buttons, time links and fold summaries.
- **Blue Tint** (blue-tint): The quiet state wash. Hover and current rows, the word being played in the Transcript, open-button hover, notice backgrounds and the empty track of the recorder clock.
- **Rule Blue** (rule): Hairline dividers between rows (1px), the dashed border of an empty state, the field border at rest, and the scrollbar.

### Secondary
- **Highlighter Yellow** (highlighter): Fillers and nothing else. The solid highlight on a Filler Sound, the fill of a Sound marker tab on the waveform, and text selection.
- **Highlighter Edge** (highlighter-edge): The thick underline on a Filler Phrase (0.22em, offset 0.18em, skip-ink off).

### Neutral
- **Paper** (paper): The page, the bar's text, open buttons, fields, and the fixed phone Play bar.
- **Ink** (ink): All reading text, the Report's total tally, the Stop button, and the waveform cursor and marker stems.
- **Soft Ink** (soft): Secondary text: the meta line, hints, Filler kind labels, counts in fold summaries, the "+N" tally cap, and Candidates that were checked and not counted.
- **Wave Unplayed** (wave-unplayed): The unplayed bars of every waveform, report and recorder alike.
- **Danger Red** (danger) and **Danger Tint** (danger-tint): Failure only. The alert notice border and wash, the failed-status icon, the recording dot, and the "Yes, delete it" button.

### Named Rules
**The Highlighter Belongs to Fillers Rule.** Yellow marks a counted Filler, in the Transcript, the Filler list, the key and the waveform tabs. It is never a button, a notice, a warning or decoration. Notices are blue-tint with a blue border; alerts are red tint with a red border; the recorder's last 30 seconds are hatched in blue.

**The Never Colour Alone Rule.** A Filler Sound is a solid highlight fitted to the word; a Filler Phrase is a thick underline; a Candidate checked and not counted is soft ink with a dotted underline. Each kind also carries its word ("Sound", "Phrase") and every status carries an icon and a label. Remove the colour and every distinction still reads.

## Typography

**Display Font:** Public Sans (with ui-sans-serif, system-ui, sans-serif)
**Body Font:** Public Sans
**Label/Mono Font:** Public Sans, tabular numerals

**Character:** A plain, civic grotesque, the face of a government form, made confident by setting the important things at weight 800 and tightening large sizes. Self-hosted as a variable font (100 to 900, Latin subset, OFL).

### Hierarchy
- **Display** (800, 56px, 1.08, -0.025em): The count as a sentence on the Report and the recorder clock. Steps to 48px below 1100px and 40px below 560px (the clock to 44px).
- **Headline** (800, 44px, 1.12, -0.025em): The home lead sentence, balanced. 36px below 860px, 30px below 560px.
- **Headline State** (800, 32px, 1.2): The title of a processing, uploading or failed state.
- **Facts** (400, 22px, 1.4, values in 800): The line under the count: rate and most frequent Filler Type. 19px below 560px.
- **Transcript** (400, 21px, line-height 2, 68ch): The Transcript, with room between lines for the highlight and underline marks. 19px below 560px.
- **Title** (800, 20px, 1.3): The Speech title, the Filler Type in the By word list, list titles on home, Coaching summary (400 at 20px).
- **Section Heading** (800, 18px, 1.4, form blue, 2px blue rule below): Every section's heading.
- **Body** (400, 17px, 1.5): Default reading text and buttons (buttons at 800).
- **Label** (600, 14 to 15px): Status labels, kind labels, table heads, counts, scale marks. Sentence case, never tracked uppercase.

### Named Rules
**The One Face Rule.** Public Sans sets every word and number. Emphasis is weight (800), never a second family, italics or uppercase tracking.

**The Tabular Numbers Rule.** `font-variant-numeric: tabular-nums` is set on the body so times, counts and rates align in every list.

## Layout

The page is a sheet: a two-column grid, `minmax(0, side) minmax(0, 1fr)`, centred at a 1440px maximum with 40px top and 72px bottom padding. The left column (side, 500px) holds the statement; the right holds the work. The column gap is 72px and the side gutter 64px.

- **Below 1100px:** side column 400px, gap 48px, gutter 40px.
- **Below 860px:** one column, gap 40px, gutter 20px. Home list rows reflow to title plus status, with the rate beneath.
- **Below 560px:** the bar hides the Speeches link (the wordmark already goes there); the Big button goes full width; Listen becomes a Play bar fixed to the bottom of the screen above a 2px blue rule, and the page gains 120px bottom padding so nothing hides behind it.

Sections in a column are 40px apart. Rows (tallies, steps, notes, Filler table, home list) are separated by 1px rule-blue hairlines, 56 to 60px tall. Reading measure is capped: 68ch for hints, notices, the Transcript and its key; 60ch for Coaching.

## Elevation & Depth

Flat. There are no shadows anywhere. Depth is replaced by rules (2px blue under headings and above the phone Play bar, 1px rule-blue between rows), by the blue-tint wash for hover and current state, and by the solid blue bar at the top. Pressed buttons move down 1px instead of changing a shadow.

### Named Rules
**The Printed Form Rule.** If a surface needs separating, draw a rule or leave space. Never lift it with a shadow or put it in a card.

## Shapes

Gently softened corners at three sizes: 3px on marks fitted to words (highlights, playing word), 6px on every control and notice (buttons, fields, notices, the clock meter, the favicon), 8px on the two panels (the dashed empty state and the delete confirmation). Tally strokes are 4px wide with 2px rounded ends, each tilted a few degrees and alternating in height by 2px so they read as hand-drawn; the fifth stroke crosses at -22deg. The recording dot is the one circle.

Icons are drawn on one 20px grid with a 2px round-capped, round-joined stroke (24px inside the Big button): done (tick), failed (cross), working (three dots), waiting (ring), play, pause, mic, stop. They are decorative; the word beside each carries the meaning. The wordmark's glyph is a tally gate in the same stroke language.

## Components

### Buttons
Solid, heavy and plain.
- **Shape:** softened corners (6px), 2px border matching the fill.
- **Primary:** form blue with white 800-weight text, 48px tall, 22px side padding, icon left with a 10px gap.
- **Hover / Focus:** fill and border darken to form-blue-dark over 120ms; focus is the 3px blue ring offset 3px (white ring on the blue bar); active moves down 1px.
- **Open:** white with blue text and blue border; hover washes to blue-tint. Used for secondary actions (Delete summary, Discard, Play in review).
- **Big:** 64px tall, 32px padding, 20px text, 24px icon. The recorder's Record and Stop; full width on phones.
- **Stop:** ink fill. **Danger:** red fill, only for the confirmed delete. **Bar:** white on the blue bar, 40px tall.
- **Disabled:** 50% opacity, not-allowed cursor.

### Tally Marks (signature)
Four blue strokes, then a fifth across them, gated in fives with 20px between gates. Strokes grow in on render (260ms, staggered 60ms; the strike follows at 240ms) and stand still under reduced motion. The total on the Report draws in ink at 40px tall and caps at 50 strokes; per-type tallies draw in blue at 34px and cap at 25. Past the cap, the marks stop and a soft "+N" says the rest. The number beside a tally is always the count; the marks are `aria-hidden`.

### Transcript Marks (signature)
Every word is a button that seeks to it. A counted Filler is 800 weight: a **Sound** gets a solid highlighter fitted to the word (line-height 1.25 inside the 2em line, 2px 4px padding, 3px corners); a **Phrase** gets a thick highlighter-edge underline. A **Candidate checked and not counted** stays visible in soft ink with a 2px dotted underline and its reason in the "Checked and not counted" list. Hovering a Filler draws a 2px blue outline; the Filler being played gets a 3px blue ring; the plain word being played gets the blue-tint wash. A one-line key under the Transcript names the three marks in words.

### Waveform
WaveSurfer bars 3px wide, 56px tall, unplayed in wave-unplayed, played in form blue, a 2px ink cursor. Each Filler is an ink stem topped by a 10px tab bordered in ink: solid highlighter for a Sound, half white and half highlighter for a Phrase. Below 240px of waveform, or where a tab would crowd the previous one, the Filler is a stem only; its kind is still in the Transcript and the tallies.

### Status
Icon plus label, never colour alone: ring for waiting (soft), three pulsing dots for working, tick for done, cross for failed (red). 15px, weight 600. Processing steps use the same icons in a list under a 2px blue rule, the current step in 800.

### Notices
Blue-tint wash, 1px blue border, 6px corners, weight 600, 68ch. An alert (`role="alert"`) swaps to red tint with a red border. The empty state is a 2px dashed rule-blue box with soft text.

### Inputs / Fields
- **Style:** white, 2px rule-blue border, 6px corners, 48px tall, up to 480px wide; placeholder in grey.
- **Focus:** the border turns form blue; no extra ring.
- **Label:** 800 above the field, "(optional)" in soft 400.

### Navigation
A 60px form-blue bar: the wordmark (tally glyph plus "Ah Counter", 21px 800), a Speeches link in pale blue at 600 (white with a 2px underline offset 8px when hovered or current), and the white New Speech button pushed right. Below 560px the wordmark drops to 18px and the Speeches link hides.

### Folds and Lists
A fold's summary is a section heading with a drawn chevron (2.5px strokes, turning 90deg over 160ms) and a soft count at the right. On the Report, "Every Filler with its time" sits in a closed fold; "Checked and not counted" is always open. List rows lead with a blue underlined time link (800, 2px underline offset 4px) that plays from that moment; the playing row gets the blue-tint wash and a 3px underline.

### Recorder Clock
The time at display size over a 12px pill meter: blue-tint track, blue fill for time used, and the last 30 seconds (from 90%) hatched in blue diagonals. A scale beneath marks 0:00, 4:30 and 5:00 (5:00 hidden on phones). A blinking red dot and the word "Recording" show capture is live.

## Do's and Don'ts

### Do:
- **Do** say every count as a sentence first ("9 Fillers in 54 seconds") and draw it as tally marks second, with the number always beside the marks.
- **Do** put every section under an 18px 800 blue heading on a 2px blue rule.
- **Do** mark a Filler Sound with a solid highlight fitted to the word and a Filler Phrase with a thick underline, and keep checked-and-not-counted Candidates visible in soft ink with a dotted underline.
- **Do** pair every status and kind with an icon or a word: "Sound", "Phrase", "Done", "Failed".
- **Do** use the blue-tint wash for hover, current and playing states, and the 3px blue ring for the Filler being played.
- **Do** call an untitled Speech "Untitled Speech" on its Report and in home's subline, keeping the date as home's main line.
- **Do** keep the reduced-motion fallback: tally strokes, pulses and chevrons stand still.

### Don't:
- **Don't** use highlighter yellow for anything that is not a Filler: not for warnings, notices, buttons or emphasis.
- **Don't** tell kinds or statuses apart by colour alone.
- **Don't** show a count as a stat tile, chart or dashboard card.
- **Don't** add shadows, cards or tinted panels around content; separate with rules and space.
- **Don't** add a second typeface, italics for emphasis, or tracked uppercase labels.
- **Don't** use red except for failure, the live recording dot and the confirmed delete.
- **Don't** use text glyphs or an icon font for icons; draw them on the 20px, 2px-stroke grid.
