# Custom OJS CLI Tools

This directory contains custom command-line tools that are copied into the OJS Docker container at build time.

## automateCopyeditingTransition.php

### Purpose

This script automates the transition of submissions from the copyediting stage back to a new external review round. It was created to support a bulk workflow correction where submissions needed to be moved from copyediting (Stage 4) to a second round of external review (Stage 3, Round 2).

### Background

In the standard OJS workflow, submissions progress linearly through stages:
1. Submission
2. Review (can have multiple rounds)
3. Copyediting
4. Production

In the ICAT process, we use two review rounds -- one for abstract review, and then another for reviewing full papers. However, in some cases, abstracts may have been mistakenly moved into the "Copyediting" phase. This script will move those submissions from copyediting back into a new review phase.

Performing this transition manually through the OJS web interface can be time-consuming for large batches of submissions and may trigger email notifications that are not desired. This script automates the process and **suppresses all email notifications**.

### How It Works

The script performs two decisions in sequence:

1. **BACK_FROM_COPYEDITING (Decision 30)**: Moves the submission from Stage 4 (Editing) back to Stage 3 (External Review).
2. **NEW_EXTERNAL_ROUND (Decision 14)**: Creates a new review round (Round 2) in the external review stage.

Email notifications are suppressed by not passing any `actions` to the decision recording API.

### Usage

```bash
# Dry run - show what would be done without making changes
php tools/automateCopyeditingTransition.php --dry-run

# Process all submissions currently in the copyediting stage
php tools/automateCopyeditingTransition.php

# Process a specific submission by ID
php tools/automateCopyeditingTransition.php --submissionId 276
```

### Technical Notes

- The script injects the submission's context into the request router before processing. This is necessary because OJS's notification system expects a valid HTTP request context, which doesn't exist in CLI mode.
- The logged-in user (from `CommandLineTool`) is used as the editor for all decisions.
- Submissions must have an existing external review round (Round 1) for this script to work correctly.

## automateRequestRevisions.php

### Purpose

This script automates the request for revisions for submissions in **External Review Round 2**. It sets the decision to "Request Revisions" (which means revisions will not be subject to a new round of peer reviews) and **suppresses all email notifications**.

### Background

In the ICAT workflow, we sometimes need to bulk-process submissions that have completed a second round of review and need revisions from the author, but do not require a third round of review. Doing this manually via the UI involves ensuring the "Review Round Status" is set correctly and unchecking the notification box, which is error-prone at scale.

### How It Works

1.  Identifies submissions in `WORKFLOW_STAGE_ID_EXTERNAL_REVIEW`.
2.  Checks if the submission is in **Review Round 2**.
3.  Records a **PENDING_REVISIONS (Decision 4)** decision.
4.  Suppresses email notifications by passing an empty actions list.

### Usage

```bash
# Dry run
php tools/automateRequestRevisions.php --dry-run

# Process all eligible submissions
php tools/automateRequestRevisions.php

# Process a specific submission
php tools/automateRequestRevisions.php --submissionId 123
```

