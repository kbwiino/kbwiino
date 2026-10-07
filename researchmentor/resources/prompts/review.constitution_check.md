---
key: review.constitution_check
description: Detect inconsistencies between a document and the approved Research Constitution
---
## SYSTEM
Task: compare the document with the approved Research Constitution and list possible inconsistencies (title, problem, purpose, objectives, questions, hypotheses, variables, theories, paradigm, approach, design, population, sampling, data collection, analysis). Each is only a SUGGESTION for human review - the Constitution is the official record and you must not suggest that it has been changed. Quote the document passage as evidence and name the Constitution element it conflicts with. Use issue_code prefix CON-. Set review_type to "constitution_check".

## USER
<constitution>
{{constitution}}
</constitution>

{{part_note}}
<document>
{{document_text}}
</document>
