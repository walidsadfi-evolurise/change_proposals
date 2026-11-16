<?php

declare(strict_types=1);

namespace Drupal\change_proposals_mcp\Plugin\mcp_server\Tool;

use Drupal\change_proposals\ProposalException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: propose_content_create.
 */
#[Tool(
  id: 'propose_content_create',
  label: new TranslatableMarkup('Propose new content'),
  description: new TranslatableMarkup('Propose a new piece of content (for example a draft article). It is created only when a human reviewer approves. Use describe_content_type to learn the required fields. Give a "reason".'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'entity_type' => ['type' => 'string', 'description' => 'Default "node".'],
      'bundle' => ['type' => 'string', 'description' => 'Content type machine name, e.g. "article".'],
      'values' => [
        'type' => 'object',
        'description' => 'field_name => value, including the required ones (title...).',
        'additionalProperties' => TRUE,
      ],
      'reason' => ['type' => 'string'],
      'langcode' => ['type' => 'string'],
    ],
    'required' => ['bundle', 'values', 'reason'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class ProposeContentCreate extends ProposalToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $values = $this->values($arguments);
    if (!$values) {
      return ['error' => 'invalid_arguments', 'message' => '"values" must be a non-empty object of field => value.'];
    }
    try {
      $proposal = $this->manager->propose(
        $this->str($arguments, 'entity_type', 'node'),
        NULL,
        $this->str($arguments, 'bundle', ''),
        $values,
        $this->account,
        $this->str($arguments, 'reason', '') ?? '',
        'mcp',
        $this->str($arguments, 'langcode'),
      );
    }
    catch (ProposalException $e) {
      return $e->toArray();
    }
    return $this->view($proposal);
  }

}
