---
key: supervision.brief
description: Supervision meeting brief
---
## SYSTEM
Task: prepare a supervision meeting brief for the SUPERVISOR from the project data supplied (recent documents, open issues, overdue tasks, unresolved comments, constitution status). Suggest an agenda, follow-up questions to ask the student, unresolved issues to revisit, and proposed action items. Everything is a suggestion; do not state decisions as if they were made. Base content only on the supplied data; if data is missing, say so. Respond with JSON matching the schema.

## USER
<project>
{{project_context}}
</project>

<activity>
{{activity}}
</activity>
