<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\TestFramework\Config\App\Config\Type;

use Magento\Config\App\Config\Type\System as SystemCache;
use Magento\Framework\App\Config\ConfigSourceInterface;
use Magento\Framework\App\Config\Spi\PostProcessorInterface;
use Magento\Framework\App\Config\Spi\PreProcessorInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Config\App\Config\Type\System\Reader;
use Magento\Framework\Serialize\Serializer\Sensitive as SensitiveSerializer;
use Magento\Framework\Serialize\Serializer\SensitiveFactory as SensitiveSerializerFactory;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\Config\Processor\Fallback;
use Magento\Store\Model\ScopeInterface as StoreScope;

/**
 * System configuration type
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class System extends SystemCache
{
    /**
     * @var array
     */
    private $data = [];

    /**
     * @var PostProcessorInterface
     */
    private $postProcessor;

    /**
     * @var FrontendInterface
     */
    private $cache;

    /**
     * @var SensitiveSerializer
     */
    private $serializer;

    /**
     * The type of config.
     *
     * @var string
     */
    private $configType;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * List of scopes that were retrieved from configuration storage
     *
     * Is used to make sure that we don't try to load non-existing configuration scopes.
     *
     * @var array
     */
    private $availableDataScopes;

    /**
     * @param ConfigSourceInterface $source
     * @param PostProcessorInterface $postProcessor
     * @param Fallback $fallback
     * @param FrontendInterface $cache
     * @param SerializerInterface $serializer
     * @param PreProcessorInterface $preProcessor
     * @param int $cachingNestedLevel
     * @param string $configType
     * @param Reader $reader
     * @param SensitiveSerializerFactory|null $sensitiveFactory
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        ConfigSourceInterface $source,
        PostProcessorInterface $postProcessor,
        Fallback $fallback,
        FrontendInterface $cache,
        SerializerInterface $serializer,
        PreProcessorInterface $preProcessor,
        $cachingNestedLevel = 1,
        $configType = self::CONFIG_TYPE,
        Reader $reader = null,
        SensitiveSerializerFactory $sensitiveFactory = null
    ) {
        $this->postProcessor = $postProcessor;
        $this->cache = $cache;
        $this->configType = $configType;
        $this->reader = $reader ?: ObjectManager::getInstance()
            ->get(Reader::class);
        $sensitiveFactory = $sensitiveFactory ?? ObjectManager::getInstance()
                ->get(SensitiveSerializerFactory::class);
        //Using sensitive serializer because any kind of information may
        //be stored in configs.
        $this->serializer = $sensitiveFactory->create(
            ['serializer' => $serializer]
        );
    }

    /**
     * @inheritdoc
     */
    public function get($path = '')
    {
        if ($path === '') {
            $this->data = array_replace_recursive($this->loadAllData(), $this->data);

            return $this->data;
        }

        return $this->getWithParts($path);
    }

    /**
     * Proceed with parts extraction from path.
     *
     * @param string $path
     * @return array|int|string|boolean
     */
    private function getWithParts($path)
    {
        $pathParts = explode('/', $path);

        if (count($pathParts) === 1 && $pathParts[0] !== ScopeInterface::SCOPE_DEFAULT) {
            if (!isset($this->data[$pathParts[0]])) {
                $data = $this->readData();
                $this->data = array_replace_recursive($data, $this->data);
            }

            return $this->data[$pathParts[0]];
        }

        $scopeType = array_shift($pathParts);

        if ($scopeType === ScopeInterface::SCOPE_DEFAULT) {
            if (!isset($this->data[$scopeType])) {
                $this->data = array_replace_recursive($this->loadDefaultScopeData($scopeType), $this->data);
            }

            return $this->getDataByPathParts($this->data[$scopeType], $pathParts);
        }

        $scopeId = array_shift($pathParts);

        if (!isset($this->data[$scopeType][$scopeId])) {
            $scopeData = $this->loadScopeData($scopeType, $scopeId);

            if (!isset($this->data[$scopeType][$scopeId])) {
                $this->data = array_replace_recursive($scopeData, $this->data);
            }
        }

        return isset($this->data[$scopeType][$scopeId])
            ? $this->getDataByPathParts($this->data[$scopeType][$scopeId], $pathParts)
            : null;
    }

    /**
     * Load configuration data for all scopes
     *
     * @return array
     */
    private function loadAllData()
    {
        $cachedData = $this->cache->load($this->configType);

        if ($cachedData === false) {
            $data = $this->readData();
        } else {
            $data = $this->serializer->unserialize($cachedData);
        }

        return $data;
    }

    /**
     * Load configuration data for default scope
     *
     * @param string $scopeType
     * @return array
     */
    private function loadDefaultScopeData($scopeType)
    {
        $cachedData = $this->cache->load($this->configType . '_' . $scopeType);

        if ($cachedData === false) {
            $data = $this->readData();
            $this->cacheData($data);
        } else {
            $data = [$scopeType => $this->serializer->unserialize($cachedData)];
        }

        return $data;
    }

    /**
     * Load configuration data for a specified scope
     *
     * @param string $scopeType
     * @param string $scopeId
     * @return array
     */
    private function loadScopeData($scopeType, $scopeId)
    {
        $cachedData = $this->cache->load($this->configType . '_' . $scopeType . '_' . $scopeId);

        if ($cachedData === false) {
            if ($this->availableDataScopes === null) {
                $cachedScopeData = $this->cache->load($this->configType . '_scopes');
                if ($cachedScopeData !== false) {
                    $this->availableDataScopes = $this->serializer->unserialize($cachedScopeData);
                }
            }
            if (is_array($this->availableDataScopes) && !isset($this->availableDataScopes[$scopeType][$scopeId])) {
                return [$scopeType => [$scopeId => []]];
            }
            $data = $this->readData();
            $this->cacheData($data);
        } else {
            $data = [$scopeType => [$scopeId => $this->serializer->unserialize($cachedData)]];
        }

        return $data;
    }

    /**
     * Cache configuration data.
     * Caches data per scope to avoid reading data for all scopes on every request
     *
     * @param array $data
     * @return void
     */
    private function cacheData(array $data)
    {
        $this->cache->save(
            $this->serializer->serialize($data),
            $this->configType,
            [self::CACHE_TAG]
        );
        $this->cache->save(
            $this->serializer->serialize($data['default']),
            $this->configType . '_default',
            [self::CACHE_TAG]
        );
        $scopes = [];
        foreach ([StoreScope::SCOPE_WEBSITES, StoreScope::SCOPE_STORES] as $curScopeType) {
            foreach ($data[$curScopeType] ?? [] as $curScopeId => $curScopeData) {
                $scopes[$curScopeType][$curScopeId] = 1;
                $this->cache->save(
                    $this->serializer->serialize($curScopeData),
                    $this->configType . '_' . $curScopeType . '_' . $curScopeId,
                    [self::CACHE_TAG]
                );
            }
        }
        $this->cache->save(
            $this->serializer->serialize($scopes),
            $this->configType . '_scopes',
            [self::CACHE_TAG]
        );
    }

    /**
     * Walk nested hash map by keys from $pathParts
     *
     * @param array $data to walk in
     * @param array $pathParts keys path
     * @return mixed
     */
    private function getDataByPathParts($data, $pathParts)
    {
        foreach ($pathParts as $key) {
            if ((array)$data === $data && isset($data[$key])) {
                $data = $data[$key];
            } elseif ($data instanceof \Magento\Framework\DataObject) {
                $data = $data->getDataByKey($key);
            } else {
                return null;
            }
        }

        return $data;
    }

    /**
     * The freshly read data.
     *
     * @return array
     */
    private function readData(): array
    {
        $this->data = $this->reader->read();
        $this->data = $this->postProcessor->process(
            $this->data
        );

        return $this->data;
    }

    /**
     * @inheritdoc
     */
    public function clean()
    {
        $this->data = [];
        $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::CACHE_TAG]);
    }
}
