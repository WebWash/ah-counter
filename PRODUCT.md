# Product

<!-- impeccable:product-schema 1 -->

Vocabulary follows [`GLOSSARY.md`](GLOSSARY.md). The technical design is in [`docs/SPEC.md`](docs/SPEC.md); this file records product truth only and defers to those two for terms and behaviour.

## Platform

web

## Users

**Primary: a public speaker practising alone.** Typically a Toastmasters member at their own desk, rehearsing a short talk before a meeting. They record a Speech in the browser, read its Report, and come back to see whether the next attempt has fewer Fillers. They are not technical and are not there to evaluate software.

**Secondary: a hackathon judge.** Someone seeing Ah Counter for the first time, through a 90-second video or a quick local run on the demo data, with no API keys and no Speech of their own. The interface must make sense to them on first sight, but when the two audiences conflict, the speaker wins.

A third reader is not a person: Claude Code and Claude Desktop read the same Reports through the MCP server.

## Product Purpose

Ah Counter helps a speaker notice and cut filler words. It analyses one short recorded English Speech and returns a Report: every Filler with its timestamp, Fillers per minute, a breakdown by Filler Type, a waveform and Transcript the speaker can click to hear each Filler, and short Coaching.

It does the job of the Toastmasters meeting role it is named after, the person who counts a speaker's "ums" and "ahs", for someone practising without a club in the room.

Success for the speaker is a lower Fillers per minute on the next Speech, and trusting the count enough to act on it. Success for the hackathon is a judge understanding the record → Report → ask Claude story within the demo.

## Positioning

- **Every Filler is checkable.** Each one has a timestamp and can be played back from the waveform, the Transcript or the Filler list. The speaker hears the evidence instead of taking a number on trust.
- **Filler Phrases are judged in context.** "Like", "so" and "you know" are only Candidates until an AI judge reads the sentence. Rejected Candidates stay visible, faded, with the judge's reason, so the speaker can disagree.
- **It prefers under-counting.** The judge is told to choose *not filler* when unsure, and the transcription tends to miss Fillers rather than invent them.
- **The same Reports are open to the speaker's AI tools.** Five MCP tools let Claude list Speeches, read a Report, list Fillers, compare two Speeches and write a Practice Exercise. Web pages and MCP tools share one code path, so they always agree.

## Operating Context

- The speaker is at a desktop or laptop with a microphone, in Chrome or Safari, somewhere they can talk aloud. Recording is 10 seconds to 5 minutes.
- The flow is: record with a live waveform and timer, listen back, optionally title it, press *Analyse*, wait while the status moves through transcribing → analysing → coaching, then read the Report. Waiting is part of every use.
- Reading a Report involves listening. The speaker clicks Fillers and Transcript words to hear them, so audio playback is a core action, not an extra.
- Use is repeated. The home page lists recent Speeches, and the point is comparing a later attempt with an earlier one.
- The judge's context is different: demo data, no keys, a first visit, often watching a screen recording at 1080p rather than using the app.
- Today it runs only on the owner's machine (`symfony serve`), with no accounts: anyone who can reach the server sees every Speech.
- A Speech's audio goes to Deepgram and its Transcript text to Anthropic. The demo data needs neither.

## Capabilities and Constraints

**Built**

- Browser recorder with live waveform, timer, warning at 4:30, automatic stop at 5:00, review before submit, optional title.
- Background processing with status polling, Retry after a failure, and delete (with an inline "Are you sure?" step, not a browser dialog).
- Report: Speech length, total Fillers, Fillers per minute, most frequent Filler Type, waveform with a marker per Filler, breakdown by Filler Type, Coaching (summary, patterns, tips), interactive Transcript, Filler list.
- Home page: recorder plus the latest 20 Speeches.
- MCP server over STDIO with five tools.
- Demo data commands (`app:load-demo`, `app:export-demo`).

**Constraints**

- English only, in both detection and the interface.
- Filler Sounds and Filler Phrases are different kinds and the Report distinguishes them everywhere they appear.
- Only Fillers with a final verdict of *filler* are counted. Rejected and unjudged Candidates are shown but never counted.
- Report statistics are computed on read, never stored.
- Use the `GLOSSARY.md` terms exactly, including its "avoid" lists (Speech, not recording or session; Report, not analysis or results; Coaching, not feedback or tips).
- No Node.js: Twig, Stimulus and AssetMapper, Tailwind through the standalone binary, WaveSurfer.js for waveforms.
- Stretch only, not built: repeated words as a Filler Type, and long pauses shown separately from Fillers.
- Out of scope for the hackathon build: other languages, authentication, payments, sharing, real-time transcription, audio editing, mobile apps, dashboards, cloud deployment, translating the interface, storing Practice Exercises, AI-written comparisons.

**Undecided**

- **Life after the hackathon (deadline 22 November 2026).** Ah Counter may become a hosted product with accounts and a wider audience. Nothing about that is decided: no audience, pricing, hosting or privacy model. Until it is, design for the local single-user app and do not add account, sharing, billing or marketing surfaces. Do not make choices that only work for a demo either.
- **Mobile use.** The recorder already handles WebKit, which covers iOS browsers, but no phone workflow has been specified or tested.

## Brand Commitments

- **Name:** Ah Counter, after the Toastmasters meeting role.
- **Coaching voice (agreed in the spec):** encouraging and Toastmasters-friendly. Every claim rests on the Fillers actually found. It never guesses at the speaker's personality, nerves or skill.
- **No visual identity exists.** There is no logo or mark, and the favicon is still the Symfony placeholder.
- **Toastmasters relationship:** none is recorded. The name of the organisation appears only to describe the audience and the role. Whether it may be used more prominently is undecided, so do not imply affiliation or endorsement.
- **Not yet confirmed:** whether the Coaching voice also governs the rest of the interface copy. Current interface copy is plain and direct.

## Evidence on Hand

- **Accuracy measurements** in `README.md` ("Accuracy") and `docs/adr/0001-transcription-provider.md`: three of the owner's own recordings compared across Deepgram Nova-3, Nova-2 and Whisper, plus the judge's verdicts with examples. Nobody hand-counted the clips, so these compare providers and verdicts with each other, not with a known truth. Do not present them as an accuracy rate.
- **The three clips** in `tests/accuracy/` are the owner's voice and are git-ignored. They are not publishable assets.
- **Demo Speeches:** `app:load-demo` expects two already-processed Speeches in `demo/` (a first attempt and an improved one). That folder does not exist yet; the owner still has to record and export them.
- **Demo video:** scripted in `docs/demo-script.md`, not recorded.
- **Absent, and not to be invented:** user testimonials, user counts, customer names, benchmark claims, pricing, any Toastmasters endorsement, and before/after improvement figures (the script's "from X to Y Fillers per minute" waits for the real demo data).

## Product Principles

1. **Show the evidence.** A count the speaker cannot hear for themselves is not worth showing. Every Filler leads back to its moment in the Recording.
2. **Be honest about doubt.** Keep rejected Candidates and the judge's reasons visible, and under-count rather than over-count. The speaker should be able to disagree with the machine.
3. **Coach from the data, kindly.** Say only what the Fillers in this Speech support, and never comment on the person.
4. **Serve the next attempt.** The Report matters because of what the speaker does in the following Speech, so progress between Speeches comes before detail within one.
5. **One truth for people and AI tools.** What the web pages show and what the MCP tools return come from the same code and use the same vocabulary.

## Accessibility & Inclusion

The interface is held to **WCAG 2.2 AA**.

Two product-specific points follow from how a Report works:

- **Never colour alone.** Filler Sound versus Filler Phrase, counted Filler versus rejected Candidate, and Speech status must each be distinguishable without colour. The Report currently tells the two kinds apart by colour in the waveform, bars and Transcript.
- **Audio is not the only route.** The waveform is an enhancement over a plain player; everything it offers must also be reachable by keyboard and readable as text (the Transcript and the Filler list with times). A judge's reason for rejecting a Candidate must not depend on hovering.

The recorder's time limits, warnings and errors must reach screen-reader users, and motion must respect the reduced-motion setting.
