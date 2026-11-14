<?php

declare(strict_types=1);

namespace Drupal\change_proposals\Controller;

use Drupal\change_proposals\ChangeProposalInterface;
use Drupal\change_proposals\Form\ReviewForm;
use Drupal\Core\Controller\ControllerBase;

/**
 * Review page.
 */
final class ProposalController extends ControllerBase {

  /**
   * Render the review form for one proposal.
   */
  public function review(ChangeProposalInterface $change_proposal): array {
    return $this->formBuilder()->getForm(ReviewForm::class, $change_proposal);
  }

  /**
   * Page title.
   */
  public function title(ChangeProposalInterface $change_proposal): string {
    return sprintf('#%d — %s', $change_proposal->id(), (string) $change_proposal->label());
  }

}
