<?php

/**
 * @file tools/automateMissingRevisions.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class automateMissingRevisions
 *
 * @ingroup tools
 *
 * @brief CLI tool to fix missing editorial decisions by recording a PENDING_REVISIONS
 *        decision for the latest review round if no qualifying decision exists.
 */

require(dirname(__FILE__) . '/bootstrap.php');

use PKP\db\DAORegistry;
use APP\facades\Repo;
use PKP\decision\Decision;
use APP\core\Application;
use PKP\cliTool\CommandLineTool;

class automateMissingRevisions extends CommandLineTool
{
    /** @var bool Whether to run in dry-run mode */
    protected $dryRun = false;

    /** @var int|null Specific submission ID to process */
    protected $submissionId = null;

    /**
     * Constructor
     */
    public function __construct($argv = [])
    {
        parent::__construct($argv);

        if (in_array('--dry-run', $this->argv)) {
            $this->dryRun = true;
        }

        $submissionIdPos = array_search('--submissionId', $this->argv);
        if ($submissionIdPos !== false && isset($this->argv[$submissionIdPos + 1])) {
            $this->submissionId = (int)$this->argv[$submissionIdPos + 1];
        }
    }

    /**
     * Print usage message
     */
    public function usage()
    {
        echo "Usage: php " . $this->scriptName . " [options]\n"
            . "Options:\n"
            . "  --dry-run          Don't make any changes, just show what would be done.\n"
            . "  --submissionId ID  Process only the specified submission.\n";
    }

    /**
     * Execute the tool
     */
    public function execute()
    {
        $submissionCollector = Repo::submission()->getCollector();
        $submissionCollector->filterByContextIds([\PKP\core\PKPApplication::SITE_CONTEXT_ID_ALL]);

        if ($this->submissionId) {
            $submissionCollector->filterByIds([$this->submissionId]);
        } else {
            // Filter by External Review stage
            $submissionCollector->filterByStageIds([WORKFLOW_STAGE_ID_EXTERNAL_REVIEW]);
        }

        $submissions = $submissionCollector->getMany();

        echo "Found " . $submissions->count() . " submissions in External Review stage.\n";
        if ($this->dryRun) {
            echo "DRY RUN: No changes will be made.\n";
        }

        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $qualifyingDecisions = [
            Decision::ACCEPT,
            Decision::PENDING_REVISIONS,
            Decision::NEW_EXTERNAL_ROUND,
            Decision::RESUBMIT,
        ];

        $affectedCount = 0;

        foreach ($submissions as $submission) {
            if ($submission->getData('stageId') !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
                continue;
            }

            // Get the latest review round
            $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);

            if (!$reviewRound) {
                continue;
            }

            // Check if there are qualifying decisions for this review round
            $countDecisions = Repo::decision()->getCollector()
                ->filterBySubmissionIds([$submission->getId()])
                ->filterByStageIds([$reviewRound->getStageId()])
                ->filterByReviewRoundIds([$reviewRound->getId()])
                ->filterByDecisionTypes($qualifyingDecisions)
                ->getCount();

            if ($countDecisions > 0) {
                // There is already a qualifying decision for the latest round
                if ($this->submissionId) {
                    echo "Submission " . $submission->getId() . ": Already has a qualifying decision for Round " . $reviewRound->getRound() . ". Skipping.\n";
                }
                continue;
            }

            $affectedCount++;
            echo "Processing submission " . $submission->getId() . " (Latest Round: " . $reviewRound->getRound() . ")...\n";

            try {
                // Hack: Inject the context into the request router so that notification managers
                // can access it without crashing.
                if (!$this->dryRun) {
                    $context = app()->get('context')->get($submission->getData('contextId'));
                    $request = Application::get()->getRequest();
                    if ($request->getRouter()) {
                        $request->getRouter()->_context = $context;
                    }
                }

                $this->recordPendingRevisions($submission, $reviewRound);
            } catch (\Exception $e) {
                echo "Error processing submission " . $submission->getId() . ": " . $e->getMessage() . "\n";
                echo $e->getTraceAsString() . "\n";
            }
        }

        echo "Done. Processed $affectedCount affected submissions.\n";
    }

    /**
     * Record PENDING_REVISIONS decision for a single submission
     */
    protected function recordPendingRevisions($submission, $reviewRound)
    {
        echo "  - Recording PENDING_REVISIONS decision for Round " . $reviewRound->getRound() . "...\n";

        if ($this->dryRun) {
            return;
        }

        $decisionConst = Decision::PENDING_REVISIONS;
        $decisionRepo = Repo::decision();
        $decisionType = $decisionRepo->getDecisionType($decisionConst);

        if (!$decisionType) {
            throw new \Exception("Unknown decision type: " . $decisionConst);
        }

        $params = [
            'decision' => $decisionConst,
            'submissionId' => $submission->getId(),
            'stageId' => $submission->getData('stageId'),
            'editorId' => $this->user ? $this->user->getId() : 1, // Fallback to admin user 1 if not running as specific user
            'reviewRoundId' => $reviewRound->getId(),
            'actions' => [], // Explicitly empty to prevent emails/notifications
        ];

        $decision = $decisionRepo->newDataObject($params);
        $decisionRepo->add($decision);
    }
}

$tool = new automateMissingRevisions($argv ?? []);
$tool->execute();
