# Accuracy clips

Recordings for `bin/console app:accuracy-check` (SPEC §10, ADR 0001).

- Save each Recording as `clip-N.webm` (or `.mp4`/`.ogg`). Audio is git-ignored: it is the owner's voice.
- Optionally add `clip-N.json` with the hand count per Filler Type, e.g. `{"um": 4, "uh": 6, "like": 2, "you know": 1}`. Without it, the expected column shows `?`.
- The check runs the app's own `FillerDetector`. Filler Phrases are only Candidates until judged, so without `--judge` their Detected column shows `n/a` and the Candidates column shows how many were found.
- `--judge` also sends the Candidates to the AI judge (`AI_JUDGE_MODEL`, needs `ANTHROPIC_API_KEY`) and counts the ones it calls Fillers. Add `-v` to see each verdict, its context and the judge's reason.
- Compare providers: `bin/console app:accuracy-check --provider=deepgram` and `--provider=whisper` (needs `OPENAI_API_KEY`). Add `-v` to see each transcript.
- `--save-fixtures` rewrites `tests/Fixtures/deepgram/clip-N.json` from Deepgram's raw responses.
