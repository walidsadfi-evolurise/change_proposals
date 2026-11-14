<?php

declare(strict_types=1);

namespace Drupal\change_proposals\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\change_proposals\ChangeProposalInterface;
use Drupal\change_proposals\ProposalException;
use Drupal\change_proposals\ProposalManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Propose, list, approve and reject from the command line.
 */
final class ChangeProposalsCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly ProposalManager $manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {
    parent::__construct();
  }

  /**
   * List proposals.
   */
  #[CLI\Command(name: 'change-proposals:list', aliases: ['cp-list'])]
  #[CLI\Option(name: 'status', description: 'pending (default), approved, rejected, failed, expired, all.')]
  #[CLI\Option(name: 'limit', description: 'Default 50.')]
  #[CLI\FieldLabels(labels: ['id' => 'ID', 'status' => 'Status', 'summary' => 'Summary', 'owner' => 'Proposed by', 'source' => 'Source', 'created' => 'Created'])]
  #[CLI\DefaultTableFields(fields: ['id', 'status', 'summary', 'owner', 'source', 'created'])]
  #[CLI\Usage(name: 'drush cp-list', description: 'Pending proposals.')]
  #[CLI\Usage(name: 'drush cp-list --status=all --format=json', description: 'Everything, as JSON.')]
  public function list(array $options = ['status' => 'pending', 'limit' => 50, 'format' => 'table']): RowsOfFields {
    $storage = $this->entityTypeManager->getStorage('change_proposal');
    $q = $storage->getQuery()->accessCheck(FALSE)->sort('created', 'DESC')->range(0, (int) $options['limit']);
    if ($options['status'] !== 'all') {
      $q->condition('status', $options['status']);
    }
    $rows = [];
    foreach ($storage->loadMultiple($q->execute()) as $p) {
      /** @var \Drupal\change_proposals\ChangeProposalInterface $p */
      $rows[] = [
        'id' => $p->id(),
        'status' => $p->getStatus(),
        'summary' => $p->label(),
        'owner' => $p->getOwner()?->getAccountName(),
        'source' => $p->get('source')->value,
        'created' => date('Y-m-d H:i', (int) $p->get('created')->value),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Show one proposal with its diff.
   */
  #[CLI\Command(name: 'change-proposals:show', aliases: ['cp-show'])]
  #[CLI\Argument(name: 'id', description: 'Proposal id.')]
  public function show(int $id): void {
    $p = $this->load($id);
    $this->output()->writeln(json_encode($p->toPublicArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $this->output()->writeln('');
    foreach ($this->manager->diff($p) as $row) {
      $this->output()->writeln(sprintf('<info>%s</info>%s', $row['label'], $row['changed'] ? '' : ' (unchanged)'));
      $this->output()->writeln('  - ' . str_replace("\n", "\n    ", $row['current']));
      $this->output()->writeln('  + ' . str_replace("\n", "\n    ", $row['proposed']));
    }
  }

  /**
   * Propose a change: values as JSON.
   */
  #[CLI\Command(name: 'change-proposals:propose', aliases: ['cp-propose'])]
  #[CLI\Argument(name: 'target', description: '"node:12" to update, or "node:article" to create.')]
  #[CLI\Argument(name: 'values', description: 'JSON object of field values, e.g. \'{"title":"New title"}\'.')]
  #[CLI\Option(name: 'reason', description: 'Why, shown to the reviewer.')]
  #[CLI\Option(name: 'langcode', description: 'Translation to change.')]
  #[CLI\Usage(name: 'drush --user=agent cp-propose node:12 \'{"title":"Better title"}\' --reason="SEO"', description: 'Propose a title change as the "agent" account.')]
  public function propose(string $target, string $values, array $options = ['reason' => '', 'langcode' => NULL]): void {
    [$type, $ref] = array_pad(explode(':', $target, 2), 2, '');
    $payload = json_decode($values, TRUE);
    if (!is_array($payload)) {
      throw new \InvalidArgumentException('values must be a JSON object.');
    }
    try {
      $p = ctype_digit($ref)
        ? $this->manager->propose($type, (int) $ref, NULL, $payload, $this->currentUser, (string) $options['reason'], 'drush', $options['langcode'] ?: NULL)
        : $this->manager->propose($type, NULL, $ref, $payload, $this->currentUser, (string) $options['reason'], 'drush', $options['langcode'] ?: NULL);
      $this->logger()->success(dt('Proposal #@id created: @s', ['@id' => $p->id(), '@s' => $p->label()]));
    }
    catch (ProposalException $e) {
      throw new \RuntimeException($e->getMessage() . ($e->messages ? ' ' . implode(' / ', $e->messages) : ''));
    }
  }

  /**
   * Approve and apply.
   */
  #[CLI\Command(name: 'change-proposals:approve', aliases: ['cp-approve'])]
  #[CLI\Argument(name: 'ids', description: 'Proposal id(s).')]
  #[CLI\Option(name: 'note', description: 'Reviewer note.')]
  public function approve(array $ids, array $options = ['note' => '']): void {
    foreach ($ids as $id) {
      try {
        $entity = $this->manager->approve($this->load((int) $id), $this->currentUser, (string) $options['note']);
        $this->logger()->success(dt('#@id applied to @label', ['@id' => $id, '@label' => $entity->label()]));
      }
      catch (ProposalException $e) {
        $this->logger()->error(dt('#@id: @m @d', ['@id' => $id, '@m' => $e->getMessage(), '@d' => implode(' / ', $e->messages)]));
      }
    }
  }

  /**
   * Reject.
   */
  #[CLI\Command(name: 'change-proposals:reject', aliases: ['cp-reject'])]
  #[CLI\Argument(name: 'ids', description: 'Proposal id(s).')]
  #[CLI\Option(name: 'note', description: 'Reviewer note, returned to the proposer.')]
  public function reject(array $ids, array $options = ['note' => '']): void {
    foreach ($ids as $id) {
      try {
        $this->manager->reject($this->load((int) $id), $this->currentUser, (string) $options['note']);
        $this->logger()->success(dt('#@id rejected', ['@id' => $id]));
      }
      catch (ProposalException $e) {
        $this->logger()->error(dt('#@id: @m', ['@id' => $id, '@m' => $e->getMessage()]));
      }
    }
  }

  /**
   * Expire overdue proposals now (cron does this too).
   */
  #[CLI\Command(name: 'change-proposals:expire', aliases: ['cp-expire'])]
  public function expire(): void {
    $this->logger()->success(dt('@n proposal(s) expired.', ['@n' => $this->manager->expire()]));
  }

  /**
   * Load or fail.
   */
  private function load(int $id): ChangeProposalInterface {
    $p = $this->entityTypeManager->getStorage('change_proposal')->load($id);
    if (!$p instanceof ChangeProposalInterface) {
      throw new \InvalidArgumentException("No proposal #$id.");
    }
    return $p;
  }

}
