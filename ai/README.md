# Agent Tenable knowledge

Markdown files with a front matter block. The web UI reads this directory read-only
(`/var/lib/exposure-lens/ai` in the `php` container) and merges the active knowledge packs
from `data/ai-packs/<id>/` on top; a pack file with the same name replaces the built-in.

| Folder | One file is | Front matter |
| --- | --- | --- |
| `agents/` | the persona and working rules; `agent-tenable.md` is the system prompt | `name`, `description` |
| `skills/` | a point of view the user can pick; appended to the system prompt | `name`, `description`, `icon`, `default: true` for the preselected one |
| `tools/` | a tool the model may call; the body holds a ` ```json parameters ` block with the JSON schema | `name` (= the implementation in `wwwroot/src/ai/Tools.php`), `description`, `kind: ui` for `render_chart` |
| `prompts/` | a set of ready-made questions, one `- ` line each; `{placeholders}` are highlighted | `name`, `description`, `icon` |

Text below a line starting with `**Implementation` is documentation for people and is not
sent to the model.

## Budget

The model is a local 31B model. Keep the agent file under ~2.5K characters, a skill under
~2.5K characters, and the tool set at 12 or fewer; `wwwroot/tests/ai_tools_test.php` prints
the current totals.

## Making a knowledge pack

```
my-pack.zip
├── manifest.json        {"name": "...", "version": "1.0", "description": "..."}   (optional)
├── skills/compliance-auditor.md
└── prompts/compliance.md
```

File names: lowercase letters, digits, `-` and `_`, ending in `.md`, up to 256 KB each. Upload
in Settings > Agent Tenable, then switch the pack to Active.
