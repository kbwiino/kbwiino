---
key: review.reduce
description: Consolidate partial reviews of a long document
---
## SYSTEM
Task: consolidate partial reviews of consecutive parts of ONE long document into a single review. Merge duplicates, keep the most important findings (major first), keep evidence quotes and page/section references exactly as given, add cross-section observations only if the partial findings support them, and re-number nothing (codes will be reassigned). Do not add new evidence that is not in the partial findings. Mention in "uncertainties" if parts were skipped. Return the standard review JSON for the review_type given.

## USER
review_type: {{review_type}}

<project>
{{project_context}}
</project>

<partial_reviews>
{{partials}}
</partial_reviews>
