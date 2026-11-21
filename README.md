# Ah Counter

Ah Counter counts your filler words. Record a short English speech in the browser and you get a report with every "um", "uh", "like" and "you know", when you said it, how many per minute, and a few tips on cutting them. Claude Code and Claude Desktop can read the same reports through an MCP server, compare two speeches and write you a practice drill.

It is named after the Toastmasters meeting role that counts a speaker's "ums" and "ahs". Built for the Symfony AI & MCP Hackathon (November 2026) with Symfony 8.1 and Symfony AI 1.0.0-BETA1.

## How it works

1. You record a speech of 10 seconds to 5 minutes in the browser.
2. Deepgram Nova-2 transcribes it, keeping the filler sounds and a timestamp for every word.
3. Plain code finds the filler sounds ("um", "uh", "hmm"). Words like "like", "so" and "you know" are only fillers some of the time ("I like pizza" isn't one), so Claude Haiku reads each one in its sentence and decides.
4. Claude Sonnet writes short coaching based on the fillers it found.
5. The report shows the counts, a waveform and transcript with each filler marked, and the coaching. Click a filler to hear it.

## Setup

You need:

- PHP 8.4 or newer with `pdo_sqlite`, `ctype`, `iconv` and `fileinfo` (`php -m` lists them)
- [Composer](https://getcomposer.org)
- The [Symfony CLI](https://symfony.com/download)
- Chrome or Safari, for recording
- A [Deepgram](https://console.deepgram.com) API key and an [Anthropic](https://console.anthropic.com) API key

No Node, Docker or database server. The data lives in a SQLite file.

Install:

```bash
git clone <this repository> um-tracker
cd um-tracker
composer install
bin/console doctrine:migrations:migrate -n
bin/console tailwind:build
```

`tailwind:build` downloads the Tailwind binary the first time, so it needs internet once.

Add your keys to a new `.env.local` file in the project folder:

```dotenv
DEEPGRAM_API_KEY=your-deepgram-key
ANTHROPIC_API_KEY=your-anthropic-key
```

Check the Anthropic key works with `bin/console app:ai-smoke`.

Run it:

```bash
symfony serve -d
```

Open the URL it prints (usually <http://127.0.0.1:8000>). This also starts the background worker that processes speeches and the Tailwind watcher. Stop everything with `symfony server:stop`.

### Without the Symfony CLI

Use two terminals:

```bash
# Terminal 1: the web server, with a bigger upload limit for 5-minute recordings
php -d upload_max_filesize=25M -d post_max_size=30M -S 127.0.0.1:8000 -t public

# Terminal 2: the worker that transcribes and analyses speeches
bin/console messenger:consume async
```

## Use it from Claude (MCP)

`bin/console mcp:server` runs an MCP server over STDIO with five tools:

| Tool | What it does |
|---|---|
| `list_speeches` | Your recent speeches with filler count and fillers per minute |
| `get_speech_report` | One speech's report: stats, coaching and the transcript with fillers in `[brackets]` |
| `list_fillers` | Every filler with its time and context, plus the ones the AI decided weren't fillers and why |
| `compare_speeches` | How a later speech did against an earlier one, overall and per filler |
| `generate_practice_exercise` | A short speaking drill for your most common filler |

### Claude Code

The repo ships a `.mcp.json`, so Claude Code finds the server on its own. Start `claude` in the project folder, approve the `ah-counter` server when asked (or enable it with `/mcp`), then ask something like *"Compare my last two speeches and give me an exercise for my worst filler."*

### Claude Desktop

Open Settings → Developer → Edit Config and add:

```json
{
    "mcpServers": {
        "ah-counter": {
            "command": "/opt/homebrew/bin/php",
            "args": ["/absolute/path/to/um-tracker/bin/console", "mcp:server"]
        }
    }
}
```

Claude Desktop needs absolute paths. Replace `/opt/homebrew/bin/php` with the output of `which php`, and `/absolute/path/to/um-tracker` with the output of `pwd` in the project folder. Restart Claude Desktop.

## Built with

- `symfony/ai-platform` with the Deepgram bridge for transcription
- `symfony/ai-bundle` with the Anthropic bridge for the three Claude calls, each returning a PHP object through structured output
- `symfony/mcp-bundle` for the MCP server, with `#[McpTool]` on service methods
- Symfony Messenger to process speeches in the background, AssetMapper and Tailwind for the front end

## Privacy

Everything runs on your machine, but a speech's audio goes to Deepgram and its transcript goes to Anthropic. There are no accounts, so anyone who can reach the server sees every speech. Run it locally only.

## Tests

```bash
php bin/phpunit
```

The tests run offline and need no API keys.

## License

[MIT](LICENSE)
