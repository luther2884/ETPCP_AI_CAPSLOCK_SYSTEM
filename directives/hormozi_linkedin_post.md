# Directive: Draft Alex Hormozi Style LinkedIn Post

## Goal
Search the web for a given keyword or topic and draft a short, punchy LinkedIn post in the style of Alex Hormozi (150 words or fewer).

## Inputs
- `keyword`: Topic or keyword supplied by the user.

## Tools & Steps
1. **Search Web**: Perform `search_web` query for `keyword` to identify key statistics, trends, or actionable tips.
2. **Drafting (Style: Alex Hormozi)**:
   - Max 150 words.
   - Pattern-interrupting hook (no greeting/fluff).
   - 1-sentence line breaks.
   - Plain English, high contrast ("X vs Y"), value-focused.
   - Concise takeaway closing.
3. **Validation**: Count words in output to guarantee <= 150 words limit.
