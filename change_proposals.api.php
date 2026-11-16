<?php

/**
 * @file
 * Hooks provided by Change Proposals.
 */

use Drupal\change_proposals\ChangeProposalInterface;

/**
 * A proposal was stored and awaits review.
 *
 * @param \Drupal\change_proposals\ChangeProposalInterface $proposal
 *   The proposal.
 */
function hook_change_proposals_created(ChangeProposalInterface $proposal): void {
  if ($proposal->get('source')->value === 'mcp') {
    \Drupal::logger('my_module')->info('Agent proposal #@id', ['@id' => $proposal->id()]);
  }
}

/**
 * A proposal was approved, rejected, expired, or failed to apply.
 *
 * Read $proposal->getStatus() and $proposal->getResult().
 *
 * @param \Drupal\change_proposals\ChangeProposalInterface $proposal
 *   The proposal.
 */
function hook_change_proposals_decided(ChangeProposalInterface $proposal): void {
  if ($proposal->getStatus() === ChangeProposalInterface::APPROVED) {
    // Warm a cache, notify a channel, trigger a deploy...
  }
}

/**
 * Change the payload POSTed to the webhook.
 *
 * @param array $body
 *   The body: event, text and proposal.
 * @param \Drupal\change_proposals\ChangeProposalInterface $proposal
 *   The proposal.
 */
function hook_change_proposals_webhook_alter(array &$body, ChangeProposalInterface $proposal): void {
  $body['channel'] = '#editorial';
}
