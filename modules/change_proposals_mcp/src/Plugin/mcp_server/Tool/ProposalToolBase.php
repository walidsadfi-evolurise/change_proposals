<?php

declare(strict_types=1);

namespace Drupal\change_proposals_mcp\Plugin\mcp_server\Tool;

use Drupal\change_proposals\ChangeProposalInterface;
use Drupal\change_proposals\ProposalManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared wiring for the proposal tools.
 */
abstract class ProposalToolBase extends ToolPluginBase {

  protected const PERMISSION = 'propose changes through mcp';

  protected ProposalManager $manager;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected EntityFieldManagerInterface $fieldManager;

  protected EntityTypeBundleInfoInterface $bundleInfo;

  protected AccountInterface $account;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'));
    $instance->manager = $container->get('change_proposals.manager');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fieldManager = $container->get('entity_field.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->account = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * Access failure payload, or NULL.
   */
  protected function denied(): ?array {
    if ($this->account->hasPermission(static::PERMISSION)) {
      return NULL;
    }
    return ['error' => 'forbidden', 'message' => sprintf('The account this MCP session runs as lacks the "%s" permission.', static::PERMISSION)];
  }

  /**
   * What the agent gets back about a proposal: public fields + diff.
   */
  protected function view(ChangeProposalInterface $proposal, bool $withDiff = TRUE): array {
    $out = $proposal->toPublicArray();
    $out['next_step'] = match ($proposal->getStatus()) {
      ChangeProposalInterface::PENDING => 'A human reviewer must approve this proposal before anything changes. Poll get_proposal, or wait for the site\'s notification.',
      ChangeProposalInterface::APPROVED => 'Applied. See result.url for the live content.',
      ChangeProposalInterface::REJECTED => 'Rejected by a reviewer. Read decision_note before proposing again.',
      ChangeProposalInterface::FAILED => 'Approved but could not be applied; see result.messages.',
      default => 'Expired without review.',
    };
    if ($withDiff) {
      $out['diff'] = $this->manager->diff($proposal);
    }
    return $out;
  }

  /**
   * Load a proposal the account may see.
   */
  protected function loadVisible(int|string $id): ChangeProposalInterface|array {
    $storage = $this->entityTypeManager->getStorage('change_proposal');
    $proposal = ctype_digit((string) $id) ? $storage->load((int) $id) : current($storage->loadByProperties(['uuid' => (string) $id]) ?: [NULL]);
    if (!$proposal instanceof ChangeProposalInterface || !$proposal->access('view', $this->account)) {
      return ['error' => 'not_found', 'message' => sprintf('No proposal "%s" visible to this account.', $id)];
    }
    return $proposal;
  }

  /**
   * Argument helpers.
   */
  protected function str(array $args, string $key, ?string $default = NULL): ?string {
    $v = $args[$key] ?? NULL;
    if ($v === NULL || $v === '') {
      return $default;
    }
    return is_scalar($v) ? trim((string) $v) : $default;
  }

  protected function values(array $args): array {
    $v = $args['values'] ?? [];
    if (is_string($v)) {
      $decoded = json_decode($v, TRUE);
      $v = is_array($decoded) ? $decoded : [];
    }
    return is_array($v) ? $v : [];
  }

}
