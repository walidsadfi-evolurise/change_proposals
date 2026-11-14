<?php

declare(strict_types=1);

namespace Drupal\change_proposals_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: list_proposals.
 */
#[Tool(
  id: 'list_proposals',
  label: new TranslatableMarkup('List proposals'),
  description: new TranslatableMarkup('Proposals visible to this account (its own, or all of them for a reviewer account), newest first, optionally filtered by status. Use it to avoid proposing the same change twice and to pick up decisions.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'failed', 'expired']],
      'target_id' => ['type' => 'integer', 'description' => 'Only proposals about this entity id.'],
      'limit' => ['type' => 'integer', 'description' => 'Default 20, max 100.'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ListProposals extends ProposalToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $storage = $this->entityTypeManager->getStorage('change_proposal');
    $q = $storage->getQuery()->accessCheck(FALSE)->sort('created', 'DESC')->range(0, max(1, min(100, (int) ($arguments['limit'] ?? 20))));
    if (!$this->account->hasPermission('review change proposals')) {
      $q->condition('uid', (int) $this->account->id());
    }
    if ($status = $this->str($arguments, 'status')) {
      $q->condition('status', $status);
    }
    if (!empty($arguments['target_id'])) {
      $q->condition('target_id', (int) $arguments['target_id']);
    }
    $out = [];
    foreach ($storage->loadMultiple($q->execute()) as $proposal) {
      /** @var \Drupal\change_proposals\ChangeProposalInterface $proposal */
      $out[] = $this->view($proposal, FALSE);
    }
    return ['proposals' => $out, 'count' => count($out)];
  }

}
