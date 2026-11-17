# Ah Counter

Ah Counter analyses a short recorded English speech for filler words ("um", "uh", "like", "you know", …) and gives you a Report with Coaching. The same Reports are available to Claude Code and Claude Desktop through an MCP server.

## Use it from Claude (MCP)

`bin/console mcp:server` runs an MCP server over STDIO with five tools:

| Tool | What it does | Needs an API key |
|---|---|---|
| `list_speeches` | Your recent Speeches with status, Filler count and Fillers per minute | no |
| `get_speech_report` | One Speech's Report: statistics, breakdown by Filler Type, Coaching, and the Transcript with Fillers in `[brackets]` | no |
| `list_fillers` | Every Filler with its time and context; optionally the Candidates the AI judge rejected | no |
| `compare_speeches` | A later Speech against a baseline: change in Fillers per minute, overall and per Filler Type | no |
| `generate_practice_exercise` | A short speaking drill aimed at a Speech's most frequent Filler Type | `ANTHROPIC_API_KEY` |

### Claude Code

The repository ships a `.mcp.json`, so Claude Code finds the server by itself:

1. Start `claude` in the project folder.
2. Approve the `ah-counter` server when Claude Code asks (or run `/mcp` and enable it).
3. Ask, for example: *"Compare my last two speeches and give me an exercise for my worst filler."*

### Claude Desktop

Claude Desktop starts servers from its own folder with a minimal `PATH`, so give it absolute paths. Open **Settings → Developer → Edit Config** (`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS) and add:

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

- Replace `/absolute/path/to/um-tracker` with where you cloned the project (`pwd` in the project folder prints it).
- Replace `/opt/homebrew/bin/php` with the output of `which php` (PHP 8.4 or newer).
- If the file already has an `mcpServers` object, add the `ah-counter` entry to it.

Restart Claude Desktop. The tools appear under the tools (hammer/slider) icon in the chat box.
