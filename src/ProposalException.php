<?php

declare(strict_types=1);

namespace Drupal\change_proposals;

/**
 * A proposal could not be accepted or applied.
 */
class ProposalException extends \RuntimeException {

  /**
   * Machine-readable code: not_allowed, not_found, blocked_fields, invalid, not_pending, expired.
   */
  public readonly string $errorCode;

  /**
   * Per-field or general messages.
   *
   * @var string[]
   */
  public readonly array $messages;

  public function __construct(string $errorCode, string $message, array $messages = []) {
    parent::__construct($message);
    $this->errorCode = $errorCode;
    $this->messages = array_values(array_map('strval', $messages));
  }

  /**
   * Array form for API responses.
   */
  public function toArray(): array {
    return ['error' => $this->errorCode, 'message' => $this->getMessage(), 'details' => $this->messages];
  }

}
