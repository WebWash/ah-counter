---
name: Ah Counter
description: Seconds Grid. A Speech drawn as one cell per second, with Fillers filling their cells.
colors:
  plane: "#e8401c"
  plane-tint: "#ffe1d6"
  paper: "#fff"
  ink: "#111"
  soft: "#4d4d4d"
  off: "#8c8c8c"
  grid: "#c9d8f2"
  played: "#dfe8f8"
typography:
  display:
    fontFamily: "Doto, ui-monospace, monospace"
    fontSize: "72px"
    fontWeight: 900
    lineHeight: "72px"
    letterSpacing: "-0.04em"
  display-compact:
    fontFamily: "Doto, ui-monospace, monospace"
    fontSize: "56px"
    fontWeight: 900
    lineHeight: "60px"
    letterSpacing: "-0.04em"
  headline:
    fontFamily: "Hanken Grotesk, ui-sans-serif, system-ui, sans-serif"
    fontSize: "44px"
    fontWeight: 800
    lineHeight: "48px"
    letterSpacing: "-0.03em"
  title:
    fontFamily: "Hanken Grotesk, ui-sans-serif, system-ui, sans-serif"
    fontSize: "28px"
    fontWeight: 800
    lineHeight: "36px"
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Hanken Grotesk, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: "24px"
  reading:
    fontFamily: "Hanken Grotesk, ui-sans-serif, system-ui, sans-serif"
    fontSize: "20px"
    fontWeight: 400
    lineHeight: "40px"
  label:
    fontFamily: "Hanken Grotesk, ui-sans-serif, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 800
    lineHeight: "24px"
  caption:
    fontFamily: "Hanken Grotesk, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 600
    lineHeight: "24px"
rounded:
  none: "0px"
spacing:
  unit: "24px"
  half: "12px"
  gutter: "48px"
  gutter-narrow: "20px"
  cell: "48px"
  cell-small: "24px"
  label-margin: "72px"
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    rounded: "{rounded.none}"
    padding: "10px 24px"
    height: "48px"
  button-primary-hover:
    backgroundColor: "{colors.plane}"
    textColor: "{colors.ink}"
  button-primary-active:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
  button-open:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "10px 24px"
    height: "48px"
  button-open-hover:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
  button-confirm:
    backgroundColor: "{colors.plane}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "10px 24px"
    height: "48px"
  button-confirm-hover:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
  button-small:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    rounded: "{rounded.none}"
    padding: "4px 18px"
    height: "36px"
  button-big:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    rounded: "{rounded.none}"
    padding: "10px 36px"
    height: "72px"
  field:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "10px 14px"
    height: "48px"
  notice:
    backgroundColor: "{colors.plane-tint}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "10px 16px"
  plane:
    backgroundColor: "{colors.plane}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "48px"
    width: "600px"
  bar:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    height: "48px"
  cell:
    backgroundColor: "{colors.paper}"
    rounded: "{rounded.none}"
    size: "48px"
  cell-sound:
    backgroundColor: "{colors.ink}"
    rounded: "{rounded.none}"
    size: "48px"
  cell-hover:
    backgroundColor: "{colors.plane-tint}"
  cell-played:
    backgroundColor: "{colors.played}"
  state-cell:
    backgroundColor: "{colors.paper}"
    rounded: "{rounded.none}"
    size: "16px"
---

# Design System: Ah Counter

## Overview

**Creative North Star: "The Seconds Grid"**

A Speech is time you can count. The system draws that time on graph paper: white paper under a faint blue 24px construction grid, one orange-red plane that holds the title and the headline figures, and ink for everything that is said or measured. The Recording appears as one cell per second, and a Filler fills the cell of the second it fell in. Pressing a filled cell plays that Filler, so the picture and the evidence are the same object.

The look is a printed specimen sheet: square corners, 2px ink rules, flat colour, no depth. It is dense where the speaker reads (the Filler list, the Transcript) and loud in exactly one place (the plane). The world was built to refuse the stat-tile dashboard with a decorative waveform; the waveform here is a narrow strip under the grid, and the figures sit on the plane, not in tiles.

Every state in the product is shown as a cell fill, so nothing depends on colour: solid, diagonal half, dashed, or crossed. The same four fills run through the seconds grid, the key, the Filler list, the Fillers by type runs, the Transcript, the status of a Speech and the processing steps.

**Key Characteristics:**
- One cell is one second; the seconds grid is the signature and the first thing drawn on every Speech page.
- State is a cell fill, never colour alone.
- One accent: the orange-red plane and its tint. Text on it is ink.
- Cell-built numerals (Doto) for headline figures only; Hanken Grotesk for everything else.
- Square corners, 2px ink rules, no shadows.
- Motion is stepped, cell by cell, and is off under reduced motion.
- Held to WCAG 2.2 AA.

## Colors

White paper, near-black ink, a faint blue construction grid, and one orange-red plane.

### Primary
- **Plane Orange-Red** (#e8401c): the plane behind the title and figures of every Speech page and the recorder heading; the ring around the playing cell and playing Filler; the hover fill of the primary button; the confirm button for Delete; the fill of the last 30 seconds of the recorder clock; the waveform cursor and the text caret.
- **Plane Tint** (#ffe1d6): hover and "now playing" backgrounds (an empty cell under the pointer, the current Filler row, the current Transcript word, a Speech row) and the background of a notice.

### Neutral
- **Paper** (#fff): the page, the top bar, the sheet below the plane, every unfilled cell.
- **Ink** (#111): all text, including text on the plane; 2px rules and borders; filled cells; the primary button.
- **Soft Ink** (#4d4d4d): secondary text on paper: hints, column headings, dates, key labels, a judge's reason, upcoming steps.
- **Off Grey** (#8c8c8c): dashed borders only (cells outside the Speech, not-counted and pending cells, the empty list) and the waveform bars. Never text.
- **Construction Blue** (#c9d8f2): the 1px lines of the 24px construction grid, and the 1px dividers between list and table rows.
- **Played Blue** (#dfe8f8): the tint left on seconds that have already been played.

### Named Rules
**The One Plane Rule.** There is one accent hue, the plane and its tint. Blue is paper structure and the played trail, never a second accent. A new signal is a new cell fill, not a new colour.

**The Ink On Orange Rule.** Text on the plane is ink. White on #e8401c fails AA contrast; ink passes. The same holds for a button whose fill is the plane colour.

**The Fill Rule.** State is a cell fill, never colour alone. Solid: Filler Sound, or done. Diagonal half: Filler Phrase, or in progress. Dashed: checked and not counted, pending, or not analysed. Crossed on the plane colour: failed. A second that holds both kinds of Filler gets ink corners with a paper band between them.

## Typography

**Display Font:** Doto (with ui-monospace, monospace)
**Body Font:** Hanken Grotesk (with ui-sans-serif, system-ui, sans-serif)

**Character:** A plain, heavy grotesque does all the reading and labelling; a numeral face built from square cells does the counting. The numerals look like the grid they sit beside. Both are self-hosted variable fonts (`assets/fonts/`), and all numbers are tabular.

### Hierarchy
- **Display** (Doto 900, 72px/72px, -0.04em; 56px/60px at 560px and under): the headline figures on the plane (Fillers per minute, Fillers, Length) and the recorder clock.
- **Headline** (800, 44px/48px, -0.03em; 28px/36px at 1000px and under): the Speech title and the recorder heading on the plane. The most frequent Filler Type is set in the same voice at 44px (28px on a phone), because it is a word, not a figure.
- **Title** (800, 28px/36px, -0.02em): the heading of a state ("Analysing your Speech…", "No Fillers counted") and the Fillers per minute figure in the Speech list.
- **Body** (400, 16px/24px): running text, table cells, list items. Hints are 15px in Soft Ink, capped at 68ch.
- **Reading** (400, 20px/40px): the Transcript, capped at 68ch. The tall line makes room for boxed Fillers. The Coaching summary is 20px/28px.
- **Label** (800, 15px/24px, sentence case): section headings, usually above a 2px ink rule; grid row labels; times; button text (800, 16px; 20px on the big Record and Stop buttons).
- **Caption** (600, 14px): figure labels on the plane, the key, status, column headings, dates.

### Named Rules
**The Figures Only Rule.** Doto is for headline figures at 56px and up. The single exception is the recorder clock, which drops to 44px at 352px wide and under so its eleven characters fit. Small numbers (times, counts, the rate in the Speech list) and every word, including the most frequent Filler Type, are Hanken Grotesk.

**The Square Point Rule.** Doto's own "." and ":" read as plus signs, so inside a figure they are drawn by CSS as single square cells. Always set a figure through the `figure` macro in `templates/_figure.html.twig`; the real characters stay in the text for screen readers.

**The Sentence Case Rule.** Labels and headings are sentence case at weight 800 or 600. No uppercase, no tracked-out small caps; weight carries the hierarchy.

## Layout

Everything sits on a 24px unit. The frame is at most 1440px wide and paints the construction grid from its own top-left corner, so modules can land on its lines; above 1442px the frame gains 2px ink rules left and right. Spacing steps are 6, 12, 24, 36, 48 and 72px.

A page is three bands. A 48px top bar on paper with a 2px ink rule under it: wordmark, Speeches, and New Speech pushed to the right. Then the top band, split into the plane (600px wide, 48px padding) and the work column, which is bare grid. Then, under another 2px ink rule, a paper sheet for lists and reading, with a 48px gutter. On a Report the sheet is two columns with a 48px gap: the Filler list and the Transcript on the left, and a 432px side column (Fillers by type, Coaching, Checked and not counted) on the right. The Report ends with a row of actions: New Speech as the primary button, Delete as the open button beside it. A Speech with no Report yet has no sheet; the plane and work column fill the screen.

The work column has a 72px left margin, and content in it is capped at 720px. Row labels of the seconds grid hang in that margin, so the cells themselves start on a grid line.

**The seconds grid.** One cell per second, in groups of ten. A Speech of up to 60 seconds lays out 10 cells to a line at 48px; up to 150 seconds, 20 to a line at 24px; longer, 30 to a line at 24px. Cell width is rounded down to a whole pixel so every line stays crisp. Only the first group on each line carries a time label. Seconds past the end of the Speech are drawn dashed to complete the last line. The recorder clock is the same grid fixed at 300 cells, 30 to a line at 24px.

**Narrow screens.** At 1000px and under the plane stacks above the work column, the Report becomes one column, the gutter drops to 20px, and grid row labels move into a column before the cells. At 560px and under a Report's grid always uses 10 cells to a line and grows taller, so a Filler's fill stays readable; the recorder clock keeps 30 small cells to a line. Play, the waveform and the time become a bar fixed to the bottom of the screen with a 2px ink rule above it, so Play stays in reach while a tall grid scrolls. The big Record and Stop buttons go full width.

### Named Rules
**The One Second Rule.** A cell in the seconds grid always means one second. Never resample it to fit a width; change how many cells sit on a line instead.

**The On The Lines Rule.** Blocks in the work column are sized in multiples of 24px (a one-line notice is 48px tall, a button 48px, a big button 72px) so what follows stays on the construction grid.

## Elevation & Depth

Flat. There are no cast shadows, no blur, no gradients for depth and no layering. Separation comes from 2px ink rules, the edge of the plane, and paper against grid. The only thing that overlaps the page is the phone transport bar, and it is marked by a 2px ink rule, not a shadow.

`box-shadow` appears in the code only as a hard, unblurred ring or rule: the 2px paper inset that keeps neighbouring Filler cells separate, the 4px plane ring on the playing cell or Filler, the 3px rule under the current nav link and the current Transcript word.

### Named Rules
**The Ring Not Shadow Rule.** Emphasis is a hard ring in the plane colour (4px) or an ink rule. If a shadow value has a blur radius or an offset that reads as depth, it does not belong here.

## Shapes

Every corner is square (0px radius): buttons, fields, cells, notices, the plane, list bullets, the stop mark, the favicon. The basic shape is the cell, a square with an ink border, and larger shapes are rectangles made of cells.

Rules have fixed weights. 2px ink: the bar, the band edges, section heading rules, buttons, fields, notices, state cells, boxed Fillers in the Transcript. 1px ink: the lines of the seconds grid, where cells share borders. 1px Construction Blue: dividers between rows. Dashed Off Grey (1px in the grid, 2px on a state cell): something that exists but is not counted or not there yet.

## Components

### Buttons
Blocks of ink, 48px tall, that flip between two flat colours in two steps.
- **Shape:** square, 2px ink border, 10px 24px padding, weight 800, at least 48px tall.
- **Primary:** ink fill, paper text. Hover: plane fill, ink text. Pressed: paper fill, ink text. Used for Play, Analyse, Retry, Record, Stop, and New Speech.
- **Open:** paper fill, ink text and border. Hover: ink fill, paper text. Used for the quieter action beside a primary one: Delete, Discard, Play in the recorder review.
- **Confirm:** plane fill, ink text. Hover: ink fill, paper text. Only for the final step of a destructive action ("Yes, delete it"), inside a 2px ink bordered panel that opens in place under Delete. No browser dialog.
- **Sizes:** small (36px tall, 4px 18px) in the top bar; big (72px tall, 20px text, 36px side padding) for Record and Stop, with a 26px inline SVG microphone or a 20px ink square.
- **Motion:** background and text colour change over 120ms in `steps(2)`; no transition under reduced motion.
- **Focus (all controls):** 3px ink outline, 3px offset.

### State cells
The legend made into a part. A 16px square with a 2px ink border, sitting on the text baseline, always next to its label: solid, half, dashed (Off Grey dashed border) or crossed (ink cross on the plane colour). Used in the key, the status of a Speech, the Type column of the Filler list, and as runs in Fillers by type, where one cell is one Filler. In the processing steps the same cell is 48px with a 1px border: solid for done, half for now, dashed for next.

### Inputs / Fields
- **Style:** paper fill, 2px ink border, square, 48px tall, 16px text. A weight-800 label sits above it.
- **Focus:** the shared 3px ink outline; the caret is the plane colour.

### Navigation
A 48px paper bar with a 2px ink rule beneath. The wordmark is text (800, 20px, -0.02em; 16px on a phone). Links are weight 600 in Soft Ink; hover and the current page turn ink with a 3px ink rule along the bottom. New Speech is a small primary button at the right edge.

### The plane
The orange-red block that heads every Speech page and the recorder. It holds the title, a line with the date, length and status, and the figures as a two-by-two definition list: a 14px weight-600 label above each 72px Doto figure, 24px apart. Everything on it is ink.

### The seconds grid
The signature component (layout rules are in Layout). Cells share 1px ink borders.
- **Empty second:** paper. Hover: Plane Tint. Played: Played Blue.
- **Filler second:** a real button with a spoken label; ink fill (Filler Sound), diagonal half (Filler Phrase) or ink corners with a paper band (both), with a 2px paper inset so neighbours stay separate cells. Pressing it plays that Filler.
- **Hover or playing:** a 4px inset ring in the plane colour.
- **Outside the Speech, or not analysed:** 1px dashed Off Grey, no fill, not interactive.
- **Processing:** one lit ink cell steps across the empty grid (`steps(1)`, 0.1s per cell). When the length is not known, a ten-cell ticker fills one cell a second (`steps(10)`).
- **Recorder clock:** cells fill with ink once a second while recording; the last 30 fill with the plane colour as the warning. A notice gives the same warning in words.
- Under reduced motion nothing sweeps or ticks.

### Transport and waveform
Play (primary button), a 48px waveform strip and the time in weight 800, in one row under the grid. Waveform bars are Off Grey, the played part ink, the cursor the plane colour. Each Filler is marked by a 12px state cell on a 2px ink stem, using the same fills. The waveform is an enhancement; if it cannot draw, a hint says Play and every link still work.

### Lists and rows
- **Section heading:** a Label above a 2px ink rule.
- **Filler list:** a table with 14px Soft Ink column headings and 1px Construction Blue dividers. Time is an underlined weight-800 button; hover inverts it to ink. The whole row is pressable; hover and the playing row take Plane Tint.
- **Speech list:** a row per Speech: title (800, 20px, one line), date, the Fillers per minute figure (Hanken 800, 28px) and the status cell with its label. Hover: Plane Tint.
- **Checked and not counted:** time, the words in weight 800, and the judge's reason in Soft Ink, always visible as text.
- **Empty list:** a 1px dashed Off Grey box with Soft Ink text.

### Transcript words
Every word is pressable and plays from there. A Filler Sound is an ink box with paper text. A Filler Phrase is a paper box with a 2px ink border and a small half cell before the words. A Candidate that was checked and not counted is Soft Ink with a 2px dashed underline. The playing word takes Plane Tint with a 3px plane rule beneath; a playing Filler takes the 4px plane ring.

### Notice
A 2px ink bordered box on Plane Tint with weight-600 ink text, for the 30-seconds-left warning and for errors. Capped at 68ch.

## Do's and Don'ts

### Do:
- **Do** show every state as a cell fill with a text label beside it: solid, diagonal half, dashed, or crossed on the plane colour.
- **Do** keep one cell equal to one second, in groups of ten, at a whole number of pixels (48px or 24px at full width).
- **Do** size and place blocks in multiples of 24px so they sit on the construction grid, which is painted from the frame.
- **Do** set headline figures through the `figure` macro so "." and ":" are drawn as square cells.
- **Do** use ink for all text on the plane and on any plane-coloured fill.
- **Do** use 2px ink rules for structure, 1px ink for shared cell lines and 1px Construction Blue for row dividers.
- **Do** step motion with `steps()`, cell by cell, and switch it off under `prefers-reduced-motion`.
- **Do** make everything the grid and waveform offer reachable by keyboard and readable as text (the Filler list, the Transcript, the visible reasons), to WCAG 2.2 AA.
- **Do** use the GLOSSARY terms in labels: Speech, Filler, Filler Sound, Filler Phrase, Filler Type, Report, Coaching.

### Don't:
- **Don't** tell Filler Sound from Filler Phrase, a counted Filler from a rejected Candidate, or one status from another by colour alone.
- **Don't** put white or paper-coloured text on the plane colour; it fails contrast.
- **Don't** add a second accent hue. Blue is the construction grid, the row dividers and the played trail only.
- **Don't** use Doto for words, for small numbers or below 56px (the 48px recorder clock at 352px and under is the only exception).
- **Don't** round a corner.
- **Don't** add a blurred or offset shadow, or a gradient for depth. A hard ring in the plane colour or an ink rule does that job.
- **Don't** ease or slide. No smooth transitions between states; they step.
- **Don't** put the headline figures in bordered stat tiles, or enlarge the waveform into decoration. The figures live on the plane and the waveform stays a strip.
- **Don't** use Off Grey for text; it is for dashed borders and waveform bars.
