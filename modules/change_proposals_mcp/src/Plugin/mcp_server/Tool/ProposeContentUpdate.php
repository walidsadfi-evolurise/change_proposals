<?php

declare(strict_types=1);

namespace Drupal\change_proposals_mcp\Plugin\mcp_server\Tool;

use Drupal\change_proposals\ProposalException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: propose_content_update.
 */
#[Tool(
  id: 'propose_content_update',
  label: new TranslatableMarkup('Propose a content update'),
  description: new TranslatableMarkup('Propose new field values for an existing piece of content. Nothing is changed until a human reviewer approves; you get a proposal id to follow with get_proposal. Give a clear "reason": the reviewer reads it. Use describe_content_type first to learn the field names.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'entity_type' => ['type' => 'string', 'description' => 'Default "node".'],
      'id' => ['type' => 'integer', 'description' => 'Entity id to update.'],
      'values' => [
        'type' => 'object',
        'description' => 'field_name => new value. Only the fields to change.',
        'additionalProperties' => TRUE,
      ],
      'reason' => ['type' => 'string', 'description' => 'Why this change; shown to the reviewer.'],
      'langcode' => [
        'type' => 'string',
        'description' => 'Translation to change, e.g. "fr". Default: original language.',
      ],
    ],
    'required' => ['id', 'values', 'reason'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class ProposeContentUpdate extends ProposalToolBase {

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
        (int) ($arguments['id'] ?? 0),
        NULL,
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
