<?php

declare(strict_types=1);

namespace Drupal\change_proposals;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A proposed change to a content entity.
 */
interface ChangeProposalInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  public const PENDING = 'pending';
  public const APPROVED = 'approved';
  public const REJECTED = 'rejected';
  public const EXPIRED = 'expired';
  public const FAILED = 'failed';

  /**
   * Current status: pending, approved, rejected, expired, failed.
   */
  public function getStatus(): string;

  /**
   * TRUE while the proposal awaits a decision.
   */
  public function isPending(): bool;

  /**
   * The operation: "update" or "create".
   */
  public function getOperation(): string;

  /**
   * Target entity type id.
   */
  public function getTargetType(): string;

  /**
   * Target bundle.
   */
  public function getTargetBundle(): string;

  /**
   * Target entity id, NULL for a creation.
   */
  public function getTargetId(): ?int;

  /**
   * Target translation, NULL for the default one.
   */
  public function getTargetLangcode(): ?string;

  /**
   * Proposed values, field name => value.
   */
  public function getPayload(): array;

  /**
   * What happened when the proposal was applied.
   */
  public function getResult(): array;

  /**
   * Validation messages recorded when the proposal was made.
   */
  public function getValidation(): array;

  /**
   * The target entity (in the proposed translation), or NULL.
   */
  public function getTarget(): ?ContentEntityInterface;

  /**
   * The representation handed back to the proposer (API, MCP, Drush).
   */
  public function toPublicArray(): array;

}
