<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Exception\NodeExistsException;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\PublishingService;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTranslationService
{
    public const TRANSLATION_STRATEGY_ONCE = 'once';
    public const TRANSLATION_STRATEGY_SYNC = 'sync';
    public const TRANSLATION_STRATEGY_NONE = 'none';

    /**
     * @var bool
     */
    protected $isActive = false;

    /**
     * @Flow\Inject
     * @var TranslationServiceInterface
     */
    protected $translationService;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.enabled")
     * @var bool
     */
    protected $enabled;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     * @var bool
     */
    protected $translateRichtextProperties;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensionConfiguration;

    /**
     * @var Context[]
     */
    protected $contextFirstLevelCache = [];

    /**
     * @Flow\Inject
     * @var ContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject()
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @param  NodeInterface  $node
     * @param  Context  $context
     * @param $recursive
     * @return void
     */
    public function afterAdoptNode(NodeInterface $node, Context $context, $recursive): void
    {
        if (!$this->enabled) {
            return;
        }

        $isAutomaticTranslationEnabledForNodeType = $node->getNodeType()->getConfiguration('options.automaticTranslation') ?? true;
        if (!$isAutomaticTranslationEnabledForNodeType) {
            return;
        }

        $targetDimensionValue = $context->getTargetDimensions()[$this->languageDimensionName];
        $languagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$targetDimensionValue];
        $translationStrategy = $languagePreset['options']['translationStrategy'] ?? null;
        if ($translationStrategy !== self::TRANSLATION_STRATEGY_ONCE) {
            return;
        }

        $this->isActive = true;

        $adoptedNode = $context->getNodeByIdentifier((string) $node->getNodeAggregateIdentifier());
        $this->syncNodeInternal($node, $adoptedNode, $context);

        $this->isActive = false;
    }

    /**
     * @param  NodeInterface  $node
     * @param  Workspace  $workspace
     * @return void
     */
    public function afterNodePublish(NodeInterface $node, Workspace $workspace): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($workspace->getName() !== 'live') {
            return;
        }

        $this->syncNode($node);
    }

    /**
     * All translatable properties from the source node are collected and passed translated via deepl and
     * applied to the target node
     *
     * @param  NodeInterface  $sourceNode
     * @param  NodeInterface  $targetNode
     * @param  Context  $context
     * @param  bool  $translate
     * @return void
     */
    protected function syncNodeInternal(NodeInterface $sourceNode, NodeInterface $targetNode, Context $context, bool $translate = true): void
    {
        $propertyDefinitions = $sourceNode->getNodeType()->getProperties();

        $sourceDimensionValue = $sourceNode->getContext()->getTargetDimensions()[$this->languageDimensionName];
        $targetDimensionValue = $context->getTargetDimensions()[$this->languageDimensionName];

        $sourceLanguage = explode('_', $sourceDimensionValue)[0];
        $targetLanguage = explode('_', $targetDimensionValue)[0];

        $sourceLanguagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$sourceDimensionValue];
        $targetLanguagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$targetDimensionValue];

        if (array_key_exists('options', $sourceLanguagePreset) && array_key_exists('deeplLanguage', $sourceLanguagePreset['options'])) {
            $sourceLanguage = $sourceLanguagePreset['options']['deeplLanguage'];
        }

        if (array_key_exists('options', $targetLanguagePreset) && array_key_exists('deeplLanguage', $targetLanguagePreset['options'])) {
            $targetLanguage = $targetLanguagePreset['options']['deeplLanguage'];
        }

        if (empty($sourceLanguage) || empty($targetLanguage) || ($sourceLanguage == $targetLanguage)) {
            return;
        }

        // Move node if targetNode has no parent or node parents are not matching
        if (!$targetNode->getParent() || ($sourceNode->getParentPath() !== $targetNode->getParentPath())) {
            try {
                $referenceNode = $context->getNodeByIdentifier($sourceNode->getParent()->getIdentifier());
                $targetNode->moveInto($referenceNode);
            } catch (NodeExistsException|InvalidArgumentException $e) {
            }
        }

        // Sync internal properties
        $targetNode->setNodeType($sourceNode->getNodeType());
        $targetNode->setHidden($sourceNode->isHidden());
        $targetNode->setHiddenInIndex($sourceNode->isHiddenInIndex());
        $targetNode->setHiddenBeforeDateTime($sourceNode->getHiddenBeforeDateTime());
        $targetNode->setHiddenAfterDateTime($sourceNode->getHiddenAfterDateTime());
        $targetNode->setIndex($sourceNode->getIndex());

        $properties = (array) $sourceNode->getProperties(true);
        $propertiesToTranslate = [];
        foreach ($properties as $propertyName => $propertyValue) {
            if (empty($propertyValue)) {
                continue;
            }
            if (!array_key_exists($propertyName, $propertyDefinitions)) {
                continue;
            }
            if (!isset($propertyDefinitions[$propertyName]['type']) || $propertyDefinitions[$propertyName]['type'] != 'string' || !is_string($propertyValue)) {
                continue;
            }
            if ((trim(strip_tags($propertyValue))) == "") {
                continue;
            }

            $isInlineEditable = $propertyDefinitions[$propertyName]['ui']['inlineEditable'] ?? false;
            // @deprecated Fallback for renamed setting translateOnAdoption -> automaticTranslation
            $isTranslateEnabledForProperty = $propertyDefinitions[$propertyName]['options']['automaticTranslation'] ?? ($propertyDefinitions[$propertyName]['options']['translateOnAdoption'] ?? null);
            $translateProperty = $isTranslateEnabledForProperty == true || (is_null($isTranslateEnabledForProperty) && $this->translateRichtextProperties && $isInlineEditable == true);

            if ($translateProperty) {
                $propertiesToTranslate[$propertyName] = $propertyValue;
                unset($properties[$propertyName]);
            }
        }

        if ($translate && count($propertiesToTranslate) > 0) {
            $translatedProperties = $this->translationService->translate($propertiesToTranslate, $targetLanguage, $sourceLanguage);
            $properties = array_merge($translatedProperties, $properties);
        }

        foreach ($properties as $propertyName => $propertyValue) {
            if ($targetNode->getProperty($propertyName) != $propertyValue) {
                $targetNode->setProperty($propertyName, $propertyValue);
            }
        }
    }

    /**
     * @param  string  $targetLanguage
     * @param  string|null  $sourceLanguage
     * @param  string  $workspaceName
     * @return Context
     */
    public function getContextForTargetLanguageDimensionAndSourceLanguageDimensionAndWorkspaceName(string $targetLanguage, string $sourceLanguage = null, string $workspaceName = 'live'): Context
    {
        $dimensionAndWorkspaceIdentifierHash = md5(trim($sourceLanguage.$targetLanguage.$workspaceName));

        if (array_key_exists($dimensionAndWorkspaceIdentifierHash, $this->contextFirstLevelCache)) {
            return $this->contextFirstLevelCache[$dimensionAndWorkspaceIdentifierHash];
        }

        $languageDimensions = array($targetLanguage);
        if (!is_null($sourceLanguage)) {
            $languageDimensions[] = $sourceLanguage;
        }

        return $this->contextFirstLevelCache[$dimensionAndWorkspaceIdentifierHash] = $this->contextFactory->create(
            array(
                'workspaceName' => $workspaceName,
                'invisibleContentShown' => true,
                'removedContentShown' => true,
                'inaccessibleContentShown' => true,
                'dimensions' => array(
                    $this->languageDimensionName => $languageDimensions,
                ),
                'targetDimensions' => array(
                    $this->languageDimensionName => $targetLanguage,
                ),
            )
        );
    }

    /**
     * @param  string  $language
     * @param  string  $workspaceName
     * @return Context
     *
     * @deprecated
     */
    public function getContextForLanguageDimensionAndWorkspaceName(string $language, string $workspaceName = 'live'): Context
    {
        return $this->getContextForTargetLanguageDimensionAndSourceLanguageDimensionAndWorkspaceName($language, null, $workspaceName);
    }

    /**
     * Checks the requirements if a node can be synchronised and executes the sync.
     *
     * @param  NodeInterface  $node
     * @param  string  $workspaceName
     * @param  bool  $translate
     * @return void
     */
    public function syncNode(NodeInterface $node, string $workspaceName = 'live', bool $translate = true): void
    {
        $isAutomaticTranslationEnabledForNodeType = $node->getNodeType()->getConfiguration('options.automaticTranslation') ?? true;
        if (!$isAutomaticTranslationEnabledForNodeType) {
            return;
        }

        $nodeSourceDimensionValue = $node->getContext()->getTargetDimensions()[$this->languageDimensionName];
        $defaultPreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['defaultPreset'];

        if ($nodeSourceDimensionValue !== $defaultPreset) {
            return;
        }

        $this->isActive = true;

        foreach ($this->contentDimensionConfiguration[$this->languageDimensionName]['presets'] as $presetIdentifier => $languagePreset) {
            if ($nodeSourceDimensionValue === $presetIdentifier) {
                continue;
            }

            $translationStrategy = $languagePreset['options']['translationStrategy'] ?? null;
            if ($translationStrategy !== self::TRANSLATION_STRATEGY_SYNC) {
                continue;
            }

            if (!$node->isRemoved()) {
                $context = $this->getContextForTargetLanguageDimensionAndSourceLanguageDimensionAndWorkspaceName($presetIdentifier, $nodeSourceDimensionValue, $workspaceName);
                $context->getFirstLevelNodeCache()->flush();

                $adoptedNode = $context->adoptNode($node);
                $this->syncNodeInternal($node, $adoptedNode, $context, $translate);

                $context->getFirstLevelNodeCache()->flush();
                $this->publishingService->publishNode($adoptedNode);
                $this->nodeDataRepository->persistEntities();
            } else {
                $removeContext = $this->getContextForTargetLanguageDimensionAndSourceLanguageDimensionAndWorkspaceName($presetIdentifier, null, $workspaceName);
                $adoptedNode = $removeContext->getNodeByIdentifier($node->getIdentifier());
                if ($adoptedNode !== null) {
                    $adoptedNode->setRemoved(true);
                }
            }
        }

        $this->isActive = false;
    }
}
