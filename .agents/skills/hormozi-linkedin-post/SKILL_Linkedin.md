---
name: hormozi-linkedin-post
description: Search the web for a keyword or topic and draft a punchy, high-value LinkedIn post in the style of Alex Hormozi (strictly <= 150 words). Trigger whenever the user requests a LinkedIn post, content in Alex Hormozi's style, short business posts, or post drafting based on a keyword.
---

# Alex Hormozi Style LinkedIn Post Generator

This skill guides the agent to search the web for real-time information on a keyword and synthesize the findings into a high-impact, short-form LinkedIn post modeled after Alex Hormozi's signature writing style.

## Workflow

### 1. Keyword Web Search
1. Extract the target keyword or topic provided by the user.
2. Execute a web search using `search_web` to gather current insights, statistics, industry shifts, or actionable frameworks related to the keyword.
3. Select the top 1–2 most compelling, non-obvious, or practical insights from the search results.

### 2. Alex Hormozi Style Guidelines

Alex Hormozi's writing is known for high clarity, low fluff, extreme practical value, and short line breaks.

- **Word Count Limit**: **STRICTLY 150 words or fewer**.
- **Hook**: First sentence must be a bold, pattern-interrupt statement, counter-intuitive truth, or high-contrast comparison. (No "Hey guys", "In today's fast-paced world...", or standard intro fluff).
- **Formatting**:
  - 1 sentence per line with double line breaks for mobile readability.
  - Bullet points or simple numbers (1-2-3) if laying out steps.
  - Minimal to zero emojis. Use ALL-CAPS sparingly for emphasis on key words.
- **Language & Tone**:
  - Simple, 5th-grade reading level.
  - Direct, confident, no corporate buzzwords (e.g., avoid "synergy", "paradigm shift", "pivot", "leverage").
  - Use simple math or high-contrast trade-offs (e.g., "Most people do X. The top 1% do Y.").
  - Focus on effort, volume, skill acquisition, or high-leverage actions.
- **Closing / Call to Action**:
  - End with a sharp, memorable 1-liner lesson or a direct value rule.

---

## Example Output Structure

```markdown
[Bold Hook - Statement of fact or counter-intuitive truth]

[Context or simple high-contrast comparison]

[3 simple steps or 1 core lesson derived from web search]
1. [Actionable point]
2. [Actionable point]
3. [Actionable point]

[Punchy closing takeaway line]

Word count: XX words
```

---

## Quality Checklist
Before outputting the post:
- [ ] Is the word count <= 150 words?
- [ ] Did you use web search to find real, relevant insights on the keyword?
- [ ] Is there zero introductory filler/fluff?
- [ ] Are sentences short and single-lined?
