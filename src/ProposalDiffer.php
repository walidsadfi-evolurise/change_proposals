<?php

declare(strict_types=1);

namespace Drupal\change_proposals;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Human-readable before/after of the proposed fields.
 */
class ProposalDiffer {

  /**
   * One row per proposed field.
   *
   * @param array $payload
   *   field => proposed value (raw).
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $current
   *   The target as it is now (NULL for a creation).
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $candidate
   *   The target with the payload applied (NULL when it could not be built).
   *
   * @return list<array{field: string, label: string, current: string, proposed: string, changed: bool}>
   */
  public function diff(array $payload, ?ContentEntityInterface $current, ?ContentEntityInterface $candidate): array {
    $rows = [];
    foreach ($payload as $field => $raw) {
      $label = $field;
      $before = '';
      $after = $this->scalar($raw);
      if ($current && $current->hasField($field)) {
        $label = (string) $current->get($field)->getFieldDefinition()->getLabel();
        $before = $this->items($current->get($field));
      }
      if ($candidate && $candidate->hasField($field)) {
        $label = (string) $candidate->get($field)->getFieldDefinition()->getLabel();
        $after = $this->items($candidate->get($field));
      }
      $rows[] = [
        'field' => (string) $field,
        'label' => $label,
        'current' => $before,
        'proposed' => $after,
        'changed' => $before !== $after,
      ];
    }
    return $rows;
  }

  /**
   * Render a field item list as comparable text.
   */
  public function items(FieldItemListInterface $items): string {
    if ($items->isEmpty()) {
      return '';
    }
    $type = $items->getFieldDefinition()->getType();
    $out = [];
    foreach ($items as $item) {
      if (in_array($type, ['entity_reference', 'entity_reference_revisions', 'file', 'image'], TRUE)) {
        $entity = $item->entity;
        $out[] = $entity ? sprintf('%s (#%s)', (string) $entity->label(), $entity->id()) : '#' . $item->target_id;
        continue;
      }
      $value = $item->getValue();
      $main = $items->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      if ($main && array_key_exists($main, $value) && count(array_filter($value, static fn($v) => $v !== NULL && $v !== '')) === 1) {
        $out[] = $this->scalar($value[$main]);
        continue;
      }
      if (in_array($type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
        $parts = [(string) ($value['value'] ?? '')];
        if (!empty($value['summary'])) {
          array_unshift($parts, '[summary] ' . $value['summary']);
        }
        if (!empty($value['format'])) {
          $parts[] = '[format: ' . $value['format'] . ']';
        }
        $out[] = implode("\n", $parts);
        continue;
      }
      $out[] = $this->scalar($value);
    }
    return implode("\n", $out);
  }

  /**
   * Stable text for any raw value.
   */
  public function scalar(mixed $value): string {
    if ($value === NULL) {
      return '';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    if (is_array($value)) {
      // A list of scalars reads better one per line.
      if ($value && array_is_list($value) && !array_filter($value, 'is_array')) {
        return implode("\n", array_map(fn($v) => $this->scalar($v), $value));
      }
      return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return (string) json_encode($value);
  }

}
