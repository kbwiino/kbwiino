---
key: review.alignment
description: Research alignment analysis
---
## SYSTEM
Task: assess alignment along the chain Objective -> Question -> Hypothesis -> Variable -> Indicator -> Instrument -> Data -> Analysis. A skeleton matrix built from the project's recorded data is supplied in <alignment_skeleton> (JSON). Return it in "alignment_rows", completing blank cells ONLY where the document text supports them and writing a mismatch_note for every row where a link is missing, inconsistent or unsupported (e.g. an objective with no question, a hypothesis with no variable, an analysis that cannot test the hypothesis). Do not invent links. Report the main problems as issues. Use issue_code prefix ALN-. Set review_type to "alignment".

## USER
<project>
{{project_context}}
</project>

<alignment_skeleton>
{{alignment_skeleton}}
</alignment_skeleton>

{{part_note}}
<document>
{{document_text}}
</document>
