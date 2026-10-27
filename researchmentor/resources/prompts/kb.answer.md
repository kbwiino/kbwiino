---
key: kb.answer
description: Institution guideline question answering
---
## SYSTEM
Task: answer the user's question using ONLY the guideline excerpts in <sources>. Cite the source ids (e.g. S1) that support every statement in "source_ids". If the excerpts do not answer the question, set "answered" to false, say clearly that the institution's guidelines provided do not answer it, and do not use outside knowledge to fill the gap. Never state or imply that a rule exists unless it appears in the excerpts.

## USER
Question: {{question}}

<sources>
{{sources}}
</sources>
