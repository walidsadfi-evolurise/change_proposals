<?php

declare(strict_types=1);

namespace Drupal\change_proposals\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;

/**
 * Delete a proposal (administrators only; decisions are normally kept as audit trail).
 */
final class ChangeProposalDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Proposals are the audit trail of what was proposed and decided. Delete only when the record itself is wrong. This action cannot be undone.');
  }

}
