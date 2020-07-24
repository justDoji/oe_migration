<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_migration_workflow\Unit\Plugin\migrate\process;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\MigrateException;
use Drupal\oe_migration_workflow\Plugin\migrate\process\SetWorkflowState;
use Drupal\migrate\Row;
use Drupal\Tests\migrate\Unit\process\MigrateProcessTestCase;
use Drupal\workflows\WorkflowInterface;
use stdClass;

/**
 * @coversDefaultClass \Drupal\oe_migration_workflow\Plugin\migrate\process\SetWorkflowState
 *
 * @group oe_migration
 */
class SetWorkflowStateTest extends MigrateProcessTestCase {
  /**
   * The ID of the plugin under test.
   *
   * @var string
   */
  protected $pluginId = 'oe_migration_workflow_set_workflow_state';

  /**
   * The instance of the plugin under test.
   *
   * @var \Drupal\migrate\ProcessPluginBase
   */
  protected $plugin;

  /**
   * Destination property, only to show errors.
   *
   * @var string
   */
  protected $destinationProperty = 'Destination test property';

  /**
   * The EntityTypeManager mock object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Process plugin configuration.
   *
   * @var array
   */
  protected $configuration;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->configuration = [
      'workflow_config_name' => 'oe_corporate_workflow',
    ];

    $this->row = new Row(
      [
        'name' => 'published',
        'status' => 1,
      ],
      [
        'status' => [
          'type' => 'integer',
          'unsigned' => FALSE,
          'alias' => 'st',
        ],
        'name' => [
          'type' => 'string',
          'unsigned' => FALSE,
          'alias' => 'st',
        ],
      ],
      TRUE
    );

    // Define two different workflows.
    $workflow_base = $this->createMock(WorkflowInterface::class);
    $workflow_base->expects($this->any())
      ->method('get')
      ->with('type_settings')
      ->willReturn(
        [
          'states' =>
            [
              'draft' => [
                'published' => 0,
              ],
              'published' => [
                'published' => 1,
              ],
            ],
        ]
      );

    $workflow_alternative = $this->createMock(WorkflowInterface::class);
    $workflow_alternative->expects($this->any())
      ->method('get')
      ->with('type_settings')
      ->willReturn(
        [
          'states' =>
            [
              'invalid' => [
                'published' => 0,
              ],
              'valid' => [
                'published' => 1,
              ],
            ],
        ]
      );

    $configEntityTypeInterface = $this->createMock(ConfigEntityTypeInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->any())
      ->method('load')
      ->willReturnMap(
        [
          ['oe_corporate_workflow', $workflow_base],
          ['test', $workflow_alternative],
        ]
      );

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->expects($this->any())
      ->method('getDefinition')
      ->with('workflow')
      ->willReturn($configEntityTypeInterface);
    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('workflow')
      ->willReturn($storage);
  }

  /**
   * Test de base case without any special configuration.
   *
   * @throws \Exception
   */
  public function testTransformDefault() {
    $this->initializePlugin();

    $value = 'published';
    $statusDestination = $this->plugin->transform($value, $this->migrateExecutable, $this->row, $this->destinationProperty);

    // The resulting value should be "published" because that is the default
    // plugin configuration that matches with a status of 1 (the default
    // status).
    $this->assertEquals('published', $statusDestination);

    $value = 'draft';
    $this->row->setSourceProperty('status', 0);
    $this->row->setSourceProperty('name', $value);
    $statusDestination = $this->plugin->transform($value, $this->migrateExecutable, $this->row, $this->destinationProperty);

    // The resulting value should be "draft" because that is the default plugin
    // configuration that matches with a status of 0.
    $this->assertEquals('draft', $statusDestination);

    $this->initializePlugin();
    $value = 'invalid_and_published';
    $this->row->setSourceProperty('status', 1);
    $this->row->setSourceProperty('name', $value);
    $statusDestination = $this->plugin->transform($value, $this->migrateExecutable, $this->row, $this->destinationProperty);

    // The resulting value should be "published" because that is the default
    // plugin configuration that matches with a status of 1.
    $this->assertEquals('published', $statusDestination);

    $value = 'invalid_and_draft';
    $this->row->setSourceProperty('name', $value);
    $this->row->setSourceProperty('status', 0);
    $statusDestination = $this->plugin->transform($value, $this->migrateExecutable, $this->row, $this->destinationProperty);

    // The resulting value should be "draft" because that is the default plugin
    // configuration that matches with a status of 0.
    $this->assertEquals('draft', $statusDestination);

    $this->configuration = [
      'workflow_config_name' => 'test',
      'published_state' => 'valid',
      'unpublished_state' => 'invalid',
    ];

    $this->initializePlugin();

    $value = 'published';
    $this->row->setSourceProperty('status', 1);
    $statusDestination = $this->plugin->transform($value, $this->migrateExecutable, $this->row, $this->destinationProperty);

    // The result should be 'valid' because is configured as published state.
    $this->assertEquals('valid', $statusDestination);
  }

  /**
   * Test when the entity status doesn't match with the workflow state.
   */
  public function testStatDoesntMatch() {
    $value = 'published';

    $this->initializePlugin();
    $this->row->setSourceProperty('status', 0);

    // This situation has to throw an exception.
    $this->expectException(MigrateException::class);
    $this->expectExceptionMessage('The entity status and the workflow state status don\'t match.');
    $this->plugin->transform($value, $this->migrateExecutable, $this->row, $this->destinationProperty);
  }

  /**
   * Tests some cases with invalid configuration.
   *
   * @param array $config
   *   An invalid configuration array.
   * @param string $message
   *   The message to check.
   *
   * @dataProvider invalidConfigurationProvider
   */
  public function testInvalidArgumentConfiguration(array $config, $message) {
    $this->configuration = $config;
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    $this->initializePlugin();
  }

  /**
   * Test with an invalid workflow name.
   */
  public function testInvalidWorkflowName() {
    $invalid_workflow_name = $this->getRandomGenerator()->string();

    $this->configuration = ['workflow_config_name' => $invalid_workflow_name];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(sprintf('"%s" is not a valid workflow.', $invalid_workflow_name));
    $this->initializePlugin();
  }

  /**
   * Data provider for the test testInvalidConfiguration().
   *
   * @return array
   *   The data to tests.
   */
  public function invalidConfigurationProvider() {
    return [
      [
        [
          'published_state' => new stdClass(),
          'workflow_config_name' => 'oe_corporate_workflow',
        ],
        'The "published_state" option must be a string. The given value is of type "object".',
      ],
      [
        [
          'unpublished_state' => '',
          'workflow_config_name' => 'oe_corporate_workflow',
        ],
        '"" is not a valid state of the "oe_corporate_workflow" workflow.',
      ],
      [
        [
          'published_state' => '',
          'workflow_config_name' => 'oe_corporate_workflow',
        ],
        '"" is not a valid state of the "oe_corporate_workflow" workflow.',
      ],
    ];
  }

  /**
   * Create an instance of the process plugin.
   */
  protected function initializePlugin() {
    $this->plugin = new SetWorkflowState(
      $this->configuration,
      $this->pluginId,
      [],
      $this->entityTypeManager
    );
  }

}
