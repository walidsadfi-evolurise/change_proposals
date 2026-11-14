<?php

declare(strict_types=1);

namespace Drupal\change_proposals_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: describe_content_type.
 */
#[Tool(
  id: 'describe_content_type',
  label: new TranslatableMarkup('Describe a content type for proposals'),
  description: new TranslatableMarkup('The fields an agent may propose for a content type: machine name, label, type, required, cardinality, allowed values, and which fields are blocked. Call this before propose_content_update or propose_content_create so the "values" you send are valid.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'entity_type' => ['type' => 'string', 'description' => 'Entity type id, default "node".'],
      'bundle' => ['type' => 'string', 'description' => 'Bundle / content type machine name, e.g. "article". Omit to list the bundles.'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class DescribeContentType extends ProposalToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $type = $this->str($arguments, 'entity_type', 'node');
    $allowed = $this->manager->allowedEntityTypes();
    if (!isset($allowed[$type])) {
      return ['error' => 'not_allowed', 'message' => sprintf('"%s" does not accept proposals.', $type), 'entity_types' => array_keys($allowed)];
    }
    $bundles = $this->bundleInfo->getBundleInfo($type);
    $bundle = $this->str($arguments, 'bundle');
    if (!$bundle) {
      return [
        'entity_type' => $type,
        'bundles' => array_map(static fn($id, $info) => ['id' => $id, 'label' => (string) $info['label']], array_keys($bundles), $bundles),
      ];
    }
    if (!isset($bundles[$bundle])) {
      return ['error' => 'not_found', 'message' => sprintf('No bundle "%s".', $bundle), 'bundles' => array_keys($bundles)];
    }
    $blocked = $this->manager->blockedFields($type);
    $fields = [];
    foreach ($this->fieldManager->getFieldDefinitions($type, $bundle) as $name => $def) {
      if (in_array($name, $blocked, TRUE) || $def->isComputed() || $def->isReadOnly()) {
        continue;
      }
      $storage = $def->getFieldStorageDefinition();
      $f = [
        'name' => $name,
        'label' => (string) $def->getLabel(),
        'type' => $def->getType(),
        'required' => $def->isRequired(),
        'cardinality' => $storage->getCardinality(),
        'description' => (string) $def->getDescription(),
      ];
      if ($allowedValues = $storage->getSetting('allowed_values')) {
        $f['allowed_values'] = $allowedValues;
      }
      if ($def->getType() === 'entity_reference') {
        $f['target_type'] = $def->getSetting('target_type');
        $f['value_format'] = 'integer id, or list of ids';
      }
      elseif (in_array($def->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
        $f['value_format'] = '{"value": "<html>", "format": "<text format id>"} or a plain string';
      }
      $fields[] = $f;
    }
    return [
      'entity_type' => $type,
      'bundle' => $bundle,
      'label' => (string) $bundles[$bundle]['label'],
      'fields' => $fields,
      'blocked_fields' => $blocked,
      'hint' => 'Send only the fields you want to change. Each proposal is validated against Drupal\'s own field constraints and then reviewed by a human.',
    ];
  }

}
