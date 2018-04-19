<?php
/**
 * @see       https://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-cache/blob/master/LICENSE.md New BSD License
 */

namespace Zend\Cache\Psr;

use Exception;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Throwable;
use Zend\Cache\Exception\InvalidArgumentException as ZendCacheInvalidArgumentException;
use Zend\Cache\Storage\FlushableInterface;
use Zend\Cache\Storage\StorageInterface;

/**
 * Decorate a zend-cache storage adapter for usage as a PSR-16 implementation.
 */
class SimpleCacheDecorator implements SimpleCacheInterface
{
    use SerializationTrait;

    /**
     * Characters reserved by PSR-16 that are not valid in cache keys.
     */
    const INVALID_KEY_CHARS = '@{}()/\\';

    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * Reference used by storage when calling getItem() to indicate status of
     * operation.
     *
     * @var null|bool
     */
    private $success;

    public function __construct(StorageInterface $storage)
    {
        $this->memoizeSerializationCapabilities($storage);
        $this->storage = $storage;
    }

    /**
     * {@inheritDoc}
     */
    public function get($key, $default = null)
    {
        $this->success = null;
        try {
            $result = $this->storage->getItem($key, $this->success);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }

        if ($this->serializeValues && $this->success) {
            $result = $this->unserialize($result);
            return $result === null ? $default : $result;
        }

        $result = $result === null ? $default : $result;
        return $this->success ? $result : $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set($key, $value, $ttl = null)
    {
        $this->validateKey($key);

        // PSR-16 states that 0 or negative TTL values should result in cache
        // invalidation for the item.
        $ttl = null !== $ttl ? (int) $ttl : null;
        if (null !== $ttl && 1 > $ttl) {
            return $this->delete($key);
        }

        $options = $this->storage->getOptions();
        $previousTtl = $options->getTtl();
        $options->setTtl($ttl);
        $value = $this->serializeValues ? serialize($value) : $value;

        try {
            $result = $this->storage->setItem($key, $value);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }

        $options->setTtl($previousTtl);
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function delete($key)
    {
        try {
            return $this->storage->removeItem($key);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clear()
    {
        if (! $this->storage instanceof FlushableInterface) {
            return false;
        }
        return $this->storage->flush();
    }

    /**
     * {@inheritDoc}
     */
    public function getMultiple($keys, $default = null)
    {
        try {
            $results = $this->storage->getItems($keys);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }

        foreach ($keys as $key) {
            if (! isset($results[$key]) && null !== $default) {
                $results[$key] = $default;
                continue;
            }

            if (isset($results[$key]) && $this->serializeValues) {
                $value = $this->unserialize($results[$key]);
                $results[$key] = null === $value ? $default : $value;
                continue;
            }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple($values, $ttl = null)
    {
        $ttl = null !== $ttl ? (int) $ttl : null;

        foreach (array_keys($values) as $key) {
            $this->validateKey($key);
            // Don't serialize values if we'll be invalidating them.
            if (null !== $ttl && 0 < $ttl) {
                $values[$key] = $this->serializeValues ? serialize($values[$key]) : $values[$key];
            }
        }

        // PSR-16 states that 0 or negative TTL values should result in cache
        // invalidation for the items.
        if (null !== $ttl && 1 > $ttl) {
            return $this->deleteMultiple(array_keys($values));
        }

        $options = $this->storage->getOptions();
        $previousTtl = $options->getTtl();
        $options->setTtl($ttl);

        try {
            $result = $this->storage->setItems($values);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }

        $options->setTtl($previousTtl);
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple($keys)
    {
        try {
            $result = $this->storage->removeItems($keys);
            return empty($result);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function has($key)
    {
        try {
            return $this->storage->hasItem($key);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } catch (Exception $e) {
            throw static::translateException($e);
        }
    }

    /**
     * @param Throwable|Exception $e
     * @return SimpleCacheException
     */
    private static function translateException($e)
    {
        $exceptionClass = $e instanceof ZendCacheInvalidArgumentException
            ? SimpleCacheInvalidArgumentException::class
            : SimpleCacheException::class;

        return new $exceptionClass($e->getMessage(), $e->getCode(), $e);
    }

    /**
     * @param string $key
     * @return void
     * @throws SimpleCacheInvalidArgumentException if key is invalid
     */
    private function validateKey($key)
    {
        $regex = sprintf('/[%s]/', preg_quote(self::INVALID_KEY_CHARS, '/'));
        if (preg_match($regex, $key)) {
            throw new SimpleCacheInvalidArgumentException(sprintf(
                'Invalid key "%s" provided; cannot contain any of (%s)',
                $key,
                self::INVALID_KEY_CHARS
            ));
        }

        if (preg_match('/^.{65,}/u', $key)) {
            throw new SimpleCacheInvalidArgumentException(sprintf(
                'Invalid key "%s" provided; key is too long. Must be no more than 64 characters',
                $key
            ));
        }
    }

    /**
     * Unserializes a value.
     *
     * If the $value returned matches a serialized false value, returns
     * false for the value.
     *
     * Otherwise, it unserializes the value. If it is a boolean false at
     * that point, it returns a null; otherwise it returns the unserialized
     * value.
     *
     * @param string $value
     * @return mixed
     */
    private function unserialize($value)
    {
        if ($value == static::$serializedFalse) {
            return false;
        }

        if (false === ($value = unserialize($value))) {
            return null;
        }

        return $value;
    }
}
