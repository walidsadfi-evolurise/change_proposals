<?php

declare(strict_types=1);

namespace Drupal\change_proposals_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: get_proposal.
 */
#[Tool(
  id: 'get_proposal',
  label: new TranslatableMarkup('Get a proposal'),
  description: new TranslatableMarkup('Status and details of one proposal: pending, approved (with the resulting revision and URL), rejected (with the reviewer\'s note), failed or expired. Includes the current-vs-proposed diff.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'id' => ['type' => 'string', 'description' => 'Proposal id or UUID.'],
    ],
    'required' => ['id'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetProposal extends ProposalToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $proposal = $this->loadVisible($this->str($arguments, 'id', '') ?? '');
    return is_array($proposal) ? $proposal : $this->view($proposal);
  }

}
