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
- **Hook**: First sentence must be a bold, pattern-interrupt statement, counter-intuitive truth, or high-contrast comparison.
- **Formatting**:
  - 1 sentence per line with double line breaks.
  - Minimal to zero emojis.
- **Language & Tone**:
  - Simple, 5th-grade reading level.
  - Direct, confident, no corporate buzzwords.
  - Use simple math or high-contrast trade-offs.
  - Focus on effort, volume, skill acquisition, or high-leverage actions.
- **Closing / Call to Action**:
  - End with a sharp, memorable 1-liner lesson or direct value rule.
