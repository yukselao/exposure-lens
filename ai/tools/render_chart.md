---
name: render_chart
description: Draw a chart in the chat from numbers you already have (from a previous tool result). Use for a distribution or a ranking; at most one per answer. Does not query anything.
kind: ui
---

```json parameters
{
  "type": "object",
  "properties": {
    "type": { "type": "string", "enum": ["bar", "horizontalBar", "doughnut", "line"], "description": "horizontalBar for rankings, doughnut for a split, bar/line for series" },
    "title": { "type": "string" },
    "labels": { "type": "array", "items": { "type": "string" }, "description": "Up to 20 labels" },
    "values": { "type": "array", "items": { "type": "number" }, "description": "Same length as labels" },
    "series_label": { "type": "string", "description": "Legend text, e.g. 'Critical findings'" },
    "palette": { "type": "string", "enum": ["severity", "accent", "tier"], "description": "severity colours critical→info in order; accent is one colour; tier colours tier 0/1/2" }
  },
  "required": ["type", "labels", "values"]
}
```

**Returns** `{ "rendered": true }`. The chart is drawn by the UI with Chart.js; the model only
supplies the data.
