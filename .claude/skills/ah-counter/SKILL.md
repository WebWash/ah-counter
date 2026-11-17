---
name: ah-counter
description: Ah Counter project conventions. Use when touching the Speech processing pipeline (transcription, Filler detection, Candidate judging, Coaching, Reports), any Symfony AI or Deepgram call, the MCP server tools, or writing tests for any of these.
---

# Ah Counter

Vocabulary: use the terms in `GLOSSARY.md` exactly (**Speech**, **Recording**, **Transcript**, **Filler**, **Filler Sound**, **Filler Phrase**, **Candidate**, **Filler Type**, **Report**, **Fillers per minute**, **Coaching**, **Practice Exercise**) in code, comments, UI copy and commit messages. The design lives in `docs/SPEC.md`, and the transcription decision in `docs/adr/0001-transcription-provider.md`. Read the matching SPEC section before changing a component.

## Pipeline

`POST /speeches` saves the Recording and a `pending` Speech, then dispatches `ProcessSpeech(id)` to the `async` transport. `ProcessSpeechHandler` calls `SpeechProcessor::process()`, which runs the first step whose output is still `null`:

| Status | Step | Service (`src/`) | Output field |
|---|---|---|---|
| `transcribing` | Transcription | `Transcription\Transcriber` → `DeepgramTranscriber` + `DeepgramResponseNormaliser` | `transcript` |
| `analysing` | Detection, then judging | `Analysis\FillerDetector` (pure), then `Analysis\CandidateJudge` → `AiCandidateJudge` | `fillers` |
| `coaching` | Coaching | `Coaching\CoachingGenerator` → `AiCoachingGenerator` | `coaching` |

- Each step saves its output with its own short flush, and keeps every provider call outside a database transaction.
- `fillers` is saved once, after judging has finished.
- Apply the duration rule (9.0–301.0 s) and the no-speech rule before saving `transcript`.
- Report statistics are computed on read by `Report\ReportBuilder`, which the web and MCP both use. `Report\SpeechComparer` is pure maths.
- Failure classes: short-lived (429, 5xx, timeouts, malformed AI output) throw ordinary exceptions and Messenger retries them up to 3 times. Permanent (400/401/403, unreadable file, the duration and no-speech rules) throw `UnrecoverableMessageHandlingException`. `RecoverableMessageHandlingException` stays unused because it ignores `max_retries`.
- `SpeechFailureSubscriber` (priority below 100) marks the Speech `failed` once `willRetry()` is false.

## Symfony AI

- Every LLM call uses structured output: `response_format => SomeDto::class`, read through `asObject()`. DTOs are `readonly` with typed (and docblock-typed list) properties.
- Call Anthropic through `App\Ai\ClaudeClient::ask()`. It sorts failures into permanent and short-lived, and it makes you pass an output token budget: the bridge's default of 1000 truncates answers once the model's thinking is counted.
- Model names come from config (`AI_JUDGE_MODEL`, `AI_COACH_MODEL`, `DEEPGRAM_MODEL`), injected with `#[Autowire]`.
- Deepgram: build `new Audio(fn () => file_get_contents($path), $mimeType, $path)` with the stored MIME type. Call `$deferred->getResult()` first so HTTP errors surface, then read the words and duration from `getRawResult()->getData()`. The plain-text result loses every timestamp (ADR 0001).
- The Symfony AI packages are pinned betas. Confirm a class, option or config key in `vendor/` before you rely on it.

## MCP tools (`src/Mcp/`)

- A tool is a thin wrapper (`#[McpTool]`, `#[Schema]`) over a `src/` service. Business logic stays in the service.
- Every tool calls `EntityManager::clear()` before loading, because the worker updates Speeches from another process.
- An unknown ID, or a Speech that isn't `done`, throws `Mcp\Exception\ToolCallException` with a readable message, which the client receives as `isError: true`. Any other exception reaches the client as a bare "internal error".
- STDOUT carries JSON-RPC only. Logs go to a file or STDERR.

## Tests

- PHPUnit runs offline with no keys.
- AI adapters: test them with `Symfony\AI\Platform\Test\MockPlatformFactory`.
- `DeepgramTranscriber`: use mock HTTP responses.
- `SpeechProcessor`: use fakes of the `Transcriber`, `CandidateJudge` and `CoachingGenerator` interfaces.
- Real-API checks belong in `bin/console app:ai-smoke` and `app:accuracy-check`, which you run by hand.
