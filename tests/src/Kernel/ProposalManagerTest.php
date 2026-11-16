<?php

declare(strict_types=1);

namespace Drupal\Tests\change_proposals\Kernel;

use Drupal\change_proposals\ChangeProposalInterface;
use Drupal\change_proposals\ProposalException;
use Drupal\change_proposals\ProposalManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Propose, validate, diff, approve, reject, expire against real nodes.
 *
 * @group change_proposals
 */
#[RunTestsInSeparateProcesses]
final class ProposalManagerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'options',
    'change_proposals',
  ];

  /**
   * The proposal manager.
   *
   * @var \Drupal\change_proposals\ProposalManager
   */
  private ProposalManager $manager;

  /**
   * The account proposing changes (the "agent").
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $agent;

  /**
   * The account approving or rejecting.
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $reviewer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('change_proposal');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'change_proposals']);
    FilterFormat::create(['format' => 'raw', 'name' => 'Raw'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->addBodyField('article');

    // UserCreationTrait creates uid 1 first; make sure neither test user is it.
    $this->createUser();
    $this->agent = $this->createUser(['access content', 'create change proposals', 'view own change proposals'], 'agent');
    $this->reviewer = $this->createUser(['access content', 'review change proposals', 'bypass node access'], 'reviewer');
    $this->manager = $this->container->get('change_proposals.manager');
  }

  /**
   * A published article.
   */
  private function article(string $title = 'Original title'): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'status' => 1,
      'uid' => $this->reviewer->id(),
      'body' => ['value' => '<p>Old body</p>', 'format' => 'raw'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Propose, inspect, approve: the node changes only on approval.
   *
   * The new revision is authored by the reviewer.
   */
  public function testProposeUpdateAndApprove(): void {
    $node = $this->article();
    $firstRevision = $node->getRevisionId();

    $proposal = $this->manager->propose('node', (int) $node->id(), NULL, [
      'title' => 'Better title',
      'body' => ['value' => '<p>New body</p>', 'format' => 'raw'],
    ], $this->agent, 'SEO: the old title had no keyword', 'test');

    $this->assertSame(ChangeProposalInterface::PENDING, $proposal->getStatus());
    $this->assertSame('update', $proposal->getOperation());
    $this->assertSame('article', $proposal->getTargetBundle());
    $this->assertSame((int) $this->agent->id(), (int) $proposal->getOwnerId());
    $this->assertSame(sprintf('Update Article #%d "Original title": title, body', $node->id()), $proposal->label());
    $this->assertGreaterThan(time(), (int) $proposal->get('expires')->value);
    $this->assertSame((int) $node->getChangedTime(), (int) $proposal->get('base_changed')->value);

    // Nothing changed yet.
    $fresh = Node::load($node->id());
    $this->assertSame('Original title', $fresh->getTitle());
    $this->assertSame($firstRevision, $fresh->getRevisionId());

    $diff = $this->manager->diff($proposal);
    $this->assertCount(2, $diff);
    $this->assertSame('title', $diff[0]['field']);
    $this->assertSame('Original title', $diff[0]['current']);
    $this->assertSame('Better title', $diff[0]['proposed']);
    $this->assertTrue($diff[0]['changed']);
    $this->assertStringContainsString('<p>New body</p>', $diff[1]['proposed']);

    $public = $proposal->toPublicArray();
    $this->assertSame('pending', $public['status']);
    $this->assertSame('Better title', $public['payload']['title']);
    $this->assertStringContainsString('/admin/content/change-proposals/' . $proposal->id(), $public['review_url']);

    $applied = $this->manager->approve($proposal, $this->reviewer, 'looks good');
    $this->assertSame('Better title', $applied->label());

    $fresh = Node::load($node->id());
    $this->assertSame('Better title', $fresh->getTitle());
    $this->assertSame('<p>New body</p>', $fresh->get('body')->value);
    $this->assertNotSame($firstRevision, $fresh->getRevisionId(), 'a new revision was created');
    $this->assertSame((int) $this->reviewer->id(), (int) $fresh->getRevisionUserId(), 'the reviewer authored the revision');
    $this->assertSame((int) $this->reviewer->id(), (int) $fresh->getOwnerId(), 'content owner untouched');
    $this->assertStringContainsString('Change proposal #' . $proposal->id(), (string) $fresh->getRevisionLogMessage());

    $this->assertSame(ChangeProposalInterface::APPROVED, $proposal->getStatus());
    $this->assertSame('looks good', $proposal->get('decision_note')->value);
    $this->assertSame((int) $this->reviewer->id(), (int) $proposal->get('decided_by')->target_id);
    $this->assertSame((int) $fresh->getRevisionId(), (int) $proposal->getResult()['revision_id']);

    // Cannot decide twice.
    $this->expectException(ProposalException::class);
    $this->manager->approve($proposal, $this->reviewer);
  }

  /**
   * Reject keeps the node and records the note.
   */
  public function testReject(): void {
    $node = $this->article();
    $proposal = $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'Nope'], $this->agent);
    $this->manager->reject($proposal, $this->reviewer, 'off brand');
    $this->assertSame(ChangeProposalInterface::REJECTED, $proposal->getStatus());
    $this->assertSame('off brand', $proposal->toPublicArray()['decision_note']);
    $this->assertSame('Original title', Node::load($node->id())->getTitle());
  }

  /**
   * Entity keys and configured fields are refused up front.
   */
  public function testBlockedAndUnknownFields(): void {
    $node = $this->article();
    try {
      $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'x', 'uid' => 1, 'created' => 1], $this->agent);
      $this->fail('blocked fields accepted');
    }
    catch (ProposalException $e) {
      $this->assertSame('blocked_fields', $e->errorCode);
      $this->assertEqualsCanonicalizing(['uid', 'created'], $e->messages);
    }
    try {
      $this->manager->propose('node', (int) $node->id(), NULL, ['field_nope' => 'x'], $this->agent);
      $this->fail('unknown field accepted');
    }
    catch (ProposalException $e) {
      $this->assertSame('invalid', $e->errorCode);
      $this->assertSame(['field_nope'], $e->messages);
    }
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('change_proposal')->getQuery()->accessCheck(FALSE)->count()->execute(), 'nothing stored');
  }

  /**
   * Field constraints run at proposal time.
   *
   * The proposer gets the error, not the reviewer.
   */
  public function testInvalidValues(): void {
    $node = $this->article();
    try {
      $this->manager->propose('node', (int) $node->id(), NULL, ['title' => str_repeat('x', 300)], $this->agent);
      $this->fail('invalid value accepted');
    }
    catch (ProposalException $e) {
      $this->assertSame('invalid', $e->errorCode);
      $this->assertStringStartsWith('title', $e->messages[0]);
    }
  }

  /**
   * Permissions and allow-list.
   */
  public function testNotAllowed(): void {
    $node = $this->article();
    $nobody = $this->createUser(['access content']);
    try {
      $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'x'], $nobody);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('not_allowed', $e->errorCode);
    }
    try {
      $this->manager->propose('user', (int) $this->agent->id(), NULL, ['name' => 'x'], $this->agent);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('not_allowed', $e->errorCode);
    }
    try {
      $this->manager->propose('node', 999, NULL, ['title' => 'x'], $this->agent);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('not_found', $e->errorCode);
    }
    // Unpublished content the agent cannot see.
    $draft = Node::create(['type' => 'article', 'title' => 'Draft', 'status' => 0, 'uid' => $this->reviewer->id()]);
    $draft->save();
    try {
      $this->manager->propose('node', (int) $draft->id(), NULL, ['title' => 'x'], $this->agent);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('not_allowed', $e->errorCode);
    }
  }

  /**
   * A creation proposal becomes a node on approval.
   *
   * The node is owned by the proposer; the revision is by the reviewer.
   */
  public function testCreate(): void {
    $proposal = $this->manager->propose('node', NULL, 'article', [
      'title' => 'Brand new',
      'body' => ['value' => 'Hello', 'format' => 'raw'],
      'status' => 0,
    ], $this->agent, 'draft for review', 'mcp');
    $this->assertSame('create', $proposal->getOperation());
    $this->assertSame('Create Article "Brand new"', $proposal->label());
    $this->assertNull($proposal->getTargetId());
    $this->assertSame(0, (int) \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'Brand new')->count()->execute());

    $diff = $this->manager->diff($proposal);
    $this->assertSame('', $diff[0]['current']);
    $this->assertSame('Brand new', $diff[0]['proposed']);

    $node = $this->manager->approve($proposal, $this->reviewer);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertFalse($node->isPublished());
    $this->assertSame((int) $this->agent->id(), (int) $node->getOwnerId());
    $this->assertSame((int) $this->reviewer->id(), (int) $node->getRevisionUserId());
    $this->assertSame((int) $node->id(), $proposal->getTargetId());
    $this->assertSame((int) $node->id(), (int) $proposal->getResult()['entity_id']);

    try {
      $this->manager->propose('node', NULL, 'article', ['body' => 'no title'], $this->agent);
      $this->fail('missing required title accepted');
    }
    catch (ProposalException $e) {
      $this->assertSame('invalid', $e->errorCode);
    }
    try {
      $this->manager->propose('node', NULL, 'nope', ['title' => 'x'], $this->agent);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('not_found', $e->errorCode);
    }
  }

  /**
   * Expiry: cron marks it, approve refuses it.
   */
  public function testExpire(): void {
    $node = $this->article();
    $p1 = $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'late'], $this->agent);
    $p2 = $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'on time'], $this->agent);
    $p1->set('expires', time() - 3600)->save();
    $this->assertSame(1, $this->manager->expire());
    $this->assertSame(ChangeProposalInterface::EXPIRED, $this->reload($p1)->getStatus());
    $this->assertSame(ChangeProposalInterface::PENDING, $this->reload($p2)->getStatus());

    $p2->set('expires', time() - 3600)->save();
    try {
      $this->manager->approve($this->reload($p2), $this->reviewer);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('expired', $e->errorCode);
    }
    $this->assertSame('Original title', Node::load($node->id())->getTitle());
  }

  /**
   * Target deleted between proposal and approval: failed, not crashed.
   */
  public function testTargetDeleted(): void {
    $node = $this->article();
    $proposal = $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'x'], $this->agent);
    $node->delete();
    try {
      $this->manager->approve($proposal, $this->reviewer);
      $this->fail();
    }
    catch (ProposalException $e) {
      $this->assertSame('not_found', $e->errorCode);
    }
    $this->assertSame(ChangeProposalInterface::FAILED, $proposal->getStatus());
    $this->assertSame('not_found', $proposal->getResult()['error']);
    $diff = $this->manager->diff($proposal);
    $this->assertCount(1, $diff);
    $this->assertSame('', $diff[0]['current'], 'no current value without a target');
    $this->assertSame('x', $diff[0]['proposed']);
  }

  /**
   * Access: reviewers see all, proposers only their own.
   */
  public function testAccess(): void {
    $node = $this->article();
    $proposal = $this->manager->propose('node', (int) $node->id(), NULL, ['title' => 'x'], $this->agent);
    $other = $this->createUser(['access content', 'create change proposals', 'view own change proposals']);
    $this->assertTrue($proposal->access('view', $this->agent));
    $this->assertTrue($proposal->access('view', $this->reviewer));
    $this->assertFalse($proposal->access('view', $other));
    $this->assertTrue($proposal->access('review', $this->reviewer));
    $this->assertFalse($proposal->access('review', $this->agent));
    $this->assertFalse($proposal->access('delete', $this->reviewer));
  }

  /**
   * Fresh copy from storage.
   */
  private function reload(ChangeProposalInterface $p): ChangeProposalInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('change_proposal');
    $storage->resetCache([$p->id()]);
    return $storage->load($p->id());
  }

  /**
   * Adds a "body" text field to node bundles.
   *
   * Replaces node_add_body_field(), deprecated in drupal:11.3.0.
   */
  private function addBodyField(string ...$bundles): void {
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Body',
      ])->save();
    }
  }

}
