<?php
namespace TYPO3\CMS\Install\Controller\Action\Tool;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Cache\DatabaseSchemaService;
use TYPO3\CMS\Core\Database\Schema\Exception\StatementException;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Install\Controller\Action;
use TYPO3\CMS\Install\Status\ErrorStatus;
use TYPO3\CMS\Install\Updates\AbstractUpdate;

/**
 * Handle update wizards
 */
class UpgradeWizard extends Action\AbstractAction
{
    /**
     * There are tables and fields missing in the database
     *
     * @var bool
     */
    protected $needsInitialUpdateDatabaseSchema = false;

    /**
     * Executes the tool
     *
     * @return string Rendered content
     */
    protected function executeAction()
    {
        // ext_localconf, db and ext_tables must be loaded for the updates
        $this->loadExtLocalconfDatabaseAndExtTables();

        if (empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'])) {
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] = [];
        }

        $actionMessages = [];

        try {
            // To make sure DatabaseCharsetUpdate and initialUpdateDatabaseSchema are first wizards, they are added here instead of ext_localconf.php
            $databaseCharsetUpdateObject = $this->getUpdateObjectInstance(\TYPO3\CMS\Install\Updates\DatabaseCharsetUpdate::class, 'databaseCharsetUpdate');
            if ($databaseCharsetUpdateObject->shouldRenderWizard()) {
                $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] = array_merge(
                    ['databaseCharsetUpdate' => \TYPO3\CMS\Install\Updates\DatabaseCharsetUpdate::class],
                    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']
                );
            }
            $initialUpdateDatabaseSchemaUpdateObject = $this->getUpdateObjectInstance(\TYPO3\CMS\Install\Updates\InitialDatabaseSchemaUpdate::class, 'initialUpdateDatabaseSchema');
            if ($initialUpdateDatabaseSchemaUpdateObject->shouldRenderWizard()) {
                $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] = array_merge(
                    ['initialUpdateDatabaseSchema' => \TYPO3\CMS\Install\Updates\InitialDatabaseSchemaUpdate::class],
                    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']
                );
                $this->needsInitialUpdateDatabaseSchema = true;
            }

            // To make sure finalUpdateDatabaseSchema is last wizard, it is added here instead of ext_localconf.php
            $finalUpdateDatabaseSchemaUpdateObject = $this->getUpdateObjectInstance(\TYPO3\CMS\Install\Updates\FinalDatabaseSchemaUpdate::class, 'finalUpdateDatabaseSchema');
            if ($finalUpdateDatabaseSchemaUpdateObject->shouldRenderWizard()) {
                $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['finalUpdateDatabaseSchema'] = \TYPO3\CMS\Install\Updates\FinalDatabaseSchemaUpdate::class;
            }
        } catch (StatementException $exception) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(ErrorStatus::class);
            $message->setTitle('SQL error');
            $message->setMessage($exception->getMessage());
            $actionMessages[] = $message;
        }

        // Perform silent cache framework table upgrade
        $this->silentCacheFrameworkTableSchemaMigration();

        if (isset($this->postValues['set']['getUserInput'])) {
            $actionMessages[] = $this->getUserInputForUpdate();
            $this->view->assign('updateAction', 'getUserInput');
        } elseif (isset($this->postValues['set']['performUpdate'])) {
            $actionMessages[] = $this->performUpdate();
            $this->view->assign('updateAction', 'performUpdate');
        } else {
            $this->listUpdates();
            $this->view->assign('updateAction', 'listUpdates');
        }

        $this->view->assign('actionMessages', $actionMessages);

        return $this->view->render();
    }

    /**
     * List of available updates
     *
     * @return void
     */
    protected function listUpdates()
    {
        if (empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'])) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\WarningStatus::class);
            $message->setTitle('No update wizards registered');
            return $message;
        }

        $availableUpdates = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] as $identifier => $className) {
            $updateObject = $this->getUpdateObjectInstance($className, $identifier);
            if ($updateObject->shouldRenderWizard()) {
                // $explanation is changed by reference in Update objects!
                $explanation = '';
                $updateObject->checkForUpdate($explanation);
                $availableUpdates[$identifier] = [
                    'identifier' => $identifier,
                    'title' => $updateObject->getTitle(),
                    'explanation' => $explanation,
                    'renderNext' => false,
                ];
                if ($identifier === 'initialUpdateDatabaseSchema') {
                    $availableUpdates['initialUpdateDatabaseSchema']['renderNext'] = $this->needsInitialUpdateDatabaseSchema;
                    // initialUpdateDatabaseSchema is always the first update
                    // we stop immediately here as the remaining updates may
                    // require the new fields to be present in order to avoid SQL errors
                    break;
                } elseif ($identifier === 'finalUpdateDatabaseSchema') {
                    // Okay to check here because finalUpdateDatabaseSchema is last element in array
                    $availableUpdates['finalUpdateDatabaseSchema']['renderNext'] = count($availableUpdates) === 1;
                } elseif (!$this->needsInitialUpdateDatabaseSchema && $updateObject->shouldRenderNextButton()) {
                    // There are Updates that only show text and don't want to be executed
                    $availableUpdates[$identifier]['renderNext'] = true;
                }
            }
        }

        $this->view->assign('availableUpdates', $availableUpdates);

        // compute done wizards for statistics
        $wizardsDone = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] as $identifier => $className) {
            /** @var AbstractUpdate $updateObject */
            $updateObject = $this->getUpdateObjectInstance($className, $identifier);
            if ($updateObject->shouldRenderWizard() !== true) {
                $wizardsDone[] = $updateObject;
            }
        }
        $this->view->assign('wizardsDone', $wizardsDone);

        $wizardsTotal = (count($wizardsDone) + count($availableUpdates));
        $this->view->assign('wizardsTotal', $wizardsTotal);

        $this->view->assign('wizardsPercentageDone', floor(($wizardsTotal - count($availableUpdates)) * 100 / $wizardsTotal));
    }

    /**
     * Get user input of update wizard
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function getUserInputForUpdate()
    {
        $wizardIdentifier = $this->postValues['values']['identifier'];

        $className = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'][$wizardIdentifier];
        $updateObject = $this->getUpdateObjectInstance($className, $wizardIdentifier);
        $wizardHtml = '';
        if (method_exists($updateObject, 'getUserInput')) {
            $wizardHtml = $updateObject->getUserInput('install[values][' . $wizardIdentifier . ']');
        }

        $updateData = [
            'identifier' => $wizardIdentifier,
            'title' => $updateObject->getTitle(),
            'wizardHtml' => $wizardHtml,
        ];

        $this->view->assign('updateData', $updateData);

        /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
        $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
        $message->setTitle('Show wizard options');
        return $message;
    }

    /**
     * Perform update of a specific wizard
     *
     * @throws \TYPO3\CMS\Install\Exception
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function performUpdate()
    {
        $this->getDatabaseConnection()->store_lastBuiltQuery = true;

        $wizardIdentifier = $this->postValues['values']['identifier'];
        $className = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'][$wizardIdentifier];
        $updateObject = $this->getUpdateObjectInstance($className, $wizardIdentifier);

        $wizardData = [
            'identifier' => $wizardIdentifier,
            'title' => $updateObject->getTitle(),
        ];

        // $wizardInputErrorMessage is given as reference to wizard object!
        $wizardInputErrorMessage = '';
        if (method_exists($updateObject, 'checkUserInput') && !$updateObject->checkUserInput($wizardInputErrorMessage)) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Input parameter broken');
            $message->setMessage($wizardInputErrorMessage ?: 'Something went wrong!');
            $wizardData['wizardInputBroken'] = true;
        } else {
            if (!method_exists($updateObject, 'performUpdate')) {
                throw new \TYPO3\CMS\Install\Exception(
                    'No performUpdate method in update wizard with identifier ' . $wizardIdentifier,
                    1371035200
                );
            }

            // Both variables are used by reference in performUpdate()
            $customOutput = '';
            $databaseQueries = [];
            $performResult = $updateObject->performUpdate($databaseQueries, $customOutput);

            if ($performResult) {
                /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
                $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
                $message->setTitle('Update successful');
            } else {
                /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
                $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
                $message->setTitle('Update failed!');
                if ($customOutput) {
                    $message->setMessage($customOutput);
                }
            }

            if ($this->postValues['values']['showDatabaseQueries'] == 1) {
                $wizardData['queries'] = $databaseQueries;
            }
        }

        $this->view->assign('wizardData', $wizardData);

        $this->getDatabaseConnection()->store_lastBuiltQuery = false;

        // Next update wizard, if available
        $nextUpdate = $this->getNextUpdateInstance($updateObject);
        $nextUpdateIdentifier = '';
        if ($nextUpdate) {
            $nextUpdateIdentifier = $nextUpdate->getIdentifier();
        }
        $this->view->assign('nextUpdateIdentifier', $nextUpdateIdentifier);

        return $message;
    }

    /**
     * Creates instance of an Update object
     *
     * @param string $className The class name
     * @param string $identifier The identifier of Update object - needed to fetch user input
     * @return AbstractUpdate Newly instantiated Update object
     */
    protected function getUpdateObjectInstance($className, $identifier)
    {
        $userInput = $this->postValues['values'][$identifier];
        $versionAsInt = VersionNumberUtility::convertVersionNumberToInteger(TYPO3_version);
        return GeneralUtility::makeInstance($className, $identifier, $versionAsInt, $userInput, $this);
    }

    /**
     * Returns the next Update object
     * Used to show the link/button to the next Update
     *
     * @param AbstractUpdate $currentUpdate Current Update object
     * @return AbstractUpdate|NULL
     */
    protected function getNextUpdateInstance(AbstractUpdate $currentUpdate)
    {
        $isPreviousRecord = true;
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] as $identifier => $className) {
            // Find the current update wizard, and then start validating the next ones
            if ($currentUpdate->getIdentifier() === $identifier) {
                $isPreviousRecord = false;
                // For the updateDatabaseSchema-wizards verify they do not have to be executed again
                if ($identifier !== 'initialUpdateDatabaseSchema' && $identifier !== 'finalUpdateDatabaseSchema') {
                    continue;
                }
            }
            if (!$isPreviousRecord) {
                $nextUpdate = $this->getUpdateObjectInstance($className, $identifier);
                if ($nextUpdate->shouldRenderWizard()) {
                    return $nextUpdate;
                }
            }
        }
        return null;
    }

    /**
     * Force creation / update of caching framework tables that are needed by some update wizards
     *
     * @TODO: See also the other remarks on this topic in the abstract class, this whole area needs improvements
     * @return void
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Core\Database\Schema\Exception\UnexpectedSignalReturnValueTypeException
     * @throws \TYPO3\CMS\Core\Database\Schema\Exception\StatementException
     * @throws \RuntimeException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function silentCacheFrameworkTableSchemaMigration()
    {
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $cachingFrameworkDatabaseSchemaService = GeneralUtility::makeInstance(DatabaseSchemaService::class);
        $createTableStatements = $sqlReader->getStatementArray(
            $cachingFrameworkDatabaseSchemaService->getCachingFrameworkRequiredDatabaseSchema()
        );

        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);
        $schemaMigrationService->install($createTableStatements);
    }
}
